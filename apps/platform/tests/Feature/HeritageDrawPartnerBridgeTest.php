<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Draw\DrawPartnerAdapter;
use App\Domain\Notifications\SmsSender;
use App\Domain\Wallet\WalletService;
use App\Jobs\SubmitSecondChanceEntryJob;
use App\Models\AuditLog;
use App\Models\FairnessSeed;
use App\Models\HeritageDrawCalendarEntry;
use App\Models\HeritageSecondChanceEntry;
use App\Models\Player;
use App\Models\SmsLog;
use App\Models\Ticket;
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Story 7.7/7.8/7.9 — the Draw Partner Bridge, exercised against
 * StubDrawPartnerAdapter (no contracted partner exists — see that class's doc
 * comment). What's asserted here is the platform's OWN behaviour around the
 * interface, not partner-specific behaviour no stub can meaningfully fake.
 */
final class HeritageDrawPartnerBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);
    }

    private function player(): Player
    {
        return Player::create([
            'msisdn' => '+2348066' . random_int(100000, 999999),
            'registeredName' => 'Draw Test Player', 'registrationChannel' => 'web', 'kycTier' => 1,
        ]);
    }

    private function ticketWithEntry(Player $player, int $entryStakeKobo = 10_000): Ticket
    {
        $seed = FairnessSeed::create([
            'seedHex' => bin2hex(random_bytes(32)), 'algorithm' => 'test', 'hash' => bin2hex(random_bytes(32)), 'issuedAt' => now(),
        ]);
        $ticket = Ticket::create([
            'reference' => (string) Str::ulid(), 'playerId' => $player->id, 'gameCode' => 'HERITAGE',
            'idempotencyKey' => (string) Str::ulid(), 'stateCode' => 'LAG', 'attributionConfidence' => 0.99,
            'jurisdictionRulesetVersion' => '2026.1', 'stakeKobo' => 100_000, 'predictionJson' => [0, 1, 2, 3, 4],
            'positions' => 5, 'prizeTableVersion' => 'HG-NG-2026.1', 'rngSeedRef' => $seed->id,
            'rngAlgorithm' => 'test', 'engineVersion' => 'heritage-1.0.0', 'status' => 'SETTLED',
        ]);
        HeritageSecondChanceEntry::create([
            'ticketId' => $ticket->id, 'playerId' => $player->id,
            'selectedNumbers' => [10, 20, 30], 'entryStakeKobo' => $entryStakeKobo,
            'status' => 'SECOND_CHANCE_PENDING',
        ]);

        return $ticket;
    }

    public function test_it_confirms_the_entry_and_sends_a_receipt_when_an_open_draw_exists(): void
    {
        $player = $this->player();
        $ticket = $this->ticketWithEntry($player);
        HeritageDrawCalendarEntry::create([
            'partnerCode' => 'STUB', 'drawName' => 'Lagos 5/90 Midday',
            'scheduledAt' => now()->addMinutes(30), 'cutoffAt' => now()->addMinutes(10), 'status' => 'open',
        ]);

        (new SubmitSecondChanceEntryJob($ticket->id))->handle(
            app(DrawPartnerAdapter::class),
            app(WalletService::class),
            app(SmsSender::class),
        );

        $entry = HeritageSecondChanceEntry::where('ticketId', $ticket->id)->firstOrFail();
        $this->assertSame('confirmed', $entry->status);
        $this->assertNotNull($entry->partnerReference);
        $this->assertSame(1, SmsLog::where('playerId', $player->id)->count());
    }

    public function test_it_rolls_and_requeues_when_no_open_draw_exists(): void
    {
        Queue::fake();
        $player = $this->player();
        $ticket = $this->ticketWithEntry($player);

        (new SubmitSecondChanceEntryJob($ticket->id))->handle(
            app(DrawPartnerAdapter::class),
            app(WalletService::class),
            app(SmsSender::class),
        );

        $entry = HeritageSecondChanceEntry::where('ticketId', $ticket->id)->firstOrFail();
        $this->assertSame('rolled', $entry->status);
        $this->assertSame(1, $entry->rollCount);
        Queue::assertPushed(SubmitSecondChanceEntryJob::class);
    }

    public function test_after_3_failed_attempts_the_player_is_compensated_and_told_plainly(): void
    {
        Queue::fake();
        $player = $this->player();
        $ticket = $this->ticketWithEntry($player, entryStakeKobo: 15_000);

        $job = new SubmitSecondChanceEntryJob($ticket->id);
        $deps = [
            app(DrawPartnerAdapter::class),
            app(WalletService::class),
            app(SmsSender::class),
        ];
        $job->handle(...$deps); // attempt 1 -> rolled
        $job->handle(...$deps); // attempt 2 -> rolled
        $job->handle(...$deps); // attempt 3 -> credited

        $entry = HeritageSecondChanceEntry::where('ticketId', $ticket->id)->firstOrFail();
        $this->assertSame('credited', $entry->status);
        $this->assertSame(3, $entry->rollCount);

        $wallet = app(WalletService::class)->walletFor($player->fresh());
        $this->assertSame(15_000, $wallet->playBalanceKobo);

        $this->assertDatabaseHas('auditLog', ['action' => 'heritage.second_chance.compensated', 'targetId' => $entry->id]);
    }

    public function test_reconciliation_flags_a_confirmed_entry_the_stub_partner_does_not_confirm(): void
    {
        $player = $this->player();
        $ticket = $this->ticketWithEntry($player);
        HeritageSecondChanceEntry::where('ticketId', $ticket->id)->update([
            'status' => 'confirmed', 'partnerReference' => 'STUB-REF-1', 'partnerCode' => 'STUB', 'confirmedAt' => now(),
        ]);

        $exitCode = Artisan::call('heritage:reconcile-second-chance');

        $this->assertSame(1, $exitCode); // FAILURE — StubDrawPartnerAdapter::reconcile() confirms nothing (see its doc comment)
        $this->assertDatabaseHas('auditLog', ['action' => 'heritage.second_chance.reconciliation_exception']);
    }

    public function test_reconciliation_is_a_no_op_with_nothing_confirmed_to_check(): void
    {
        $exitCode = Artisan::call('heritage:reconcile-second-chance');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('auditLog', ['action' => 'heritage.second_chance.reconciliation_exception']);
    }

    public function test_notify_results_leaves_unresulted_entries_alone(): void
    {
        $player = $this->player();
        $ticket = $this->ticketWithEntry($player);
        HeritageSecondChanceEntry::where('ticketId', $ticket->id)->update([
            'status' => 'confirmed', 'partnerReference' => 'STUB-REF-2', 'confirmedAt' => now(),
        ]);

        Artisan::call('heritage:notify-second-chance-results');

        // StubDrawPartnerAdapter::getResults() always returns null — nothing to notify yet.
        $this->assertNull(HeritageSecondChanceEntry::where('ticketId', $ticket->id)->first()->resultNotifiedAt);
    }
}
