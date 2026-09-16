<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\NetPositionService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\ResponsibleGaming\Registries\RegistryClient;
use App\Domain\ResponsibleGaming\Registries\RegistryUnavailableException;
use App\Domain\Ticket\CreateTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\FundingService;
use App\Domain\Wallet\WalletService;
use App\Models\Player;
use App\Models\RegistryExclusion;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ResponsibleGamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
        // A win queues DispatchPrizePayoutJob (Epic 4); with QUEUE_CONNECTION=sync it
        // would otherwise run inline with no configured payout credentials — same fix
        // as BlackRedTicketTest/PayoutTest.
        Queue::fake();
    }

    private function player(bool $verified = true): Player
    {
        return Player::create([
            'msisdn' => '+2348033' . random_int(100000, 999999),
            'registeredName' => 'RG Test',
            'registrationChannel' => 'web',
            'kycTier' => 1,
            'ninHash' => $verified ? hash('sha256', (string) random_int(10000000000, 99999999999)) : null,
        ]);
    }

    // ── Story 5.1: Limits ──────────────────────────────────────────────

    public function test_default_limits_are_provisioned_on_first_read(): void
    {
        $limits = app(LimitsService::class)->limitsFor($this->player());
        $this->assertCount(6, $limits);
        $this->assertSame(1_500_000, collect($limits)->firstWhere('limitKey', 'stake-daily')->currentValue);
    }

    public function test_reducing_a_limit_applies_immediately(): void
    {
        $player = $this->player();
        $updated = app(LimitsService::class)->updateLimit($player, 'stake-daily', 100_000);

        $this->assertSame(100_000, $updated->currentValue);
        $this->assertNull($updated->pendingValue);
    }

    public function test_increasing_a_limit_is_held_for_24_hours(): void
    {
        $player = $this->player();
        $updated = app(LimitsService::class)->updateLimit($player, 'stake-daily', 3_000_000);

        $this->assertSame(1_500_000, $updated->currentValue); // unchanged
        $this->assertSame(3_000_000, $updated->pendingValue);
        $this->assertTrue($updated->pendingEffectiveAt->isFuture());
    }

    public function test_a_stake_exceeding_the_daily_limit_is_refused_with_no_ticket_created(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 5_000_000, 'collection', 1);
        app(LimitsService::class)->updateLimit($player, 'stake-daily', 100_000);

        try {
            app(CreateTicket::class)->create($player, ['B'], 150_000, 'limit-test-1');
            $this->fail('Expected TicketEligibilityException');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('LIMIT_REACHED', $e->errorCode);
        }

        $this->assertSame(0, \App\Models\Ticket::where('playerId', $player->id)->count());
    }

    public function test_a_stake_within_the_limit_succeeds(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 5_000_000, 'collection', 1);
        app(LimitsService::class)->updateLimit($player, 'stake-daily', 100_000);

        $ticket = app(CreateTicket::class)->create($player, ['B'], 50_000, 'limit-test-2');

        $this->assertSame('SETTLED', $ticket->status);
    }

    // ── Story 5.2/5.3: Cool-off and self-exclusion ────────────────────

    public function test_an_active_cool_off_blocks_a_new_ticket(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', 1);
        app(ProtectionService::class)->startCoolOff($player, 'cool-off-24h');

        try {
            app(CreateTicket::class)->create($player, ['B'], 10_000, 'coolOff-test-1');
            $this->fail('Expected TicketEligibilityException');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('EXCLUDED', $e->errorCode);
        }
    }

    public function test_self_exclusion_options_never_offer_less_than_six_months(): void
    {
        $options = app(ProtectionService::class)->selfExclusionOptions();
        foreach ($options as $option) {
            $this->assertGreaterThanOrEqual(24 * 183, $option['durationHours']);
        }
    }

    public function test_self_exclusion_blocks_deposit_too(): void
    {
        $player = $this->player();
        app(ProtectionService::class)->selfExclude($player, 'exclude-6m');

        $result = app(FundingService::class)->collect($player, 100_000);

        $this->assertSame('protection_active', $result['status']);
    }

    public function test_withdrawal_is_never_gated_by_protection_service(): void
    {
        // Structural assertion: PayoutService has no dependency on ProtectionService at
        // all (REQ-RG-004/005 — withdrawal remains available throughout a cool-off or
        // self-exclusion). If this ever changes, it must be a deliberate decision, not
        // an accidental copy-paste of the play/deposit gate.
        $reflection = new \ReflectionClass(\App\Domain\Payout\PayoutService::class);
        $paramTypes = array_map(
            fn ($p) => $p->getType()?->getName(),
            $reflection->getConstructor()->getParameters(),
        );
        $this->assertNotContains(ProtectionService::class, $paramTypes);
    }

    // ── Story 5.4: Net position ────────────────────────────────────────

    public function test_net_position_is_winnings_minus_stakes(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', 1);

        app(CreateTicket::class)->create($player, ['B'], 100_000, 'net-test-1');

        $net = app(NetPositionService::class)->positionsFor($player);
        // Either a loss (-100_000) or a win (net positive) — either way, staked and
        // any winnings are both reflected; assert the arithmetic holds against the
        // actual outcome rather than assuming win/loss.
        $ticket = \App\Models\Ticket::where('playerId', $player->id)->first();
        $expected = $ticket->outcome->won ? $ticket->outcome->netCreditKobo - 100_000 : -100_000;
        $this->assertSame($expected, $net['sevenDays']);
    }

    // ── Story 5.5/5.6: Exclusion registry ──────────────────────────────

    public function test_a_player_with_no_verified_nin_is_refused_registry_status_as_unavailable(): void
    {
        $service = app(RegistryCheckService::class);
        $this->assertSame('unavailable', $service->statusFor($this->player(verified: false)));
    }

    public function test_a_fresh_cached_exclusion_blocks_play_without_calling_the_client(): void
    {
        $player = $this->player();
        RegistryExclusion::create([
            'ninHash' => $player->ninHash,
            'registryName' => 'Test Registry',
            'excluded' => true,
            'checkedAt' => now(),
        ]);

        $spy = new class implements RegistryClient {
            public bool $called = false;
            public function registryName(): string { return 'Test'; }
            public function isExcluded(string $ninHash): bool { $this->called = true; return false; }
        };
        $service = new RegistryCheckService($spy);

        $this->assertSame('excluded', $service->statusFor($player));
        $this->assertFalse($spy->called, 'A fresh cache entry must not trigger a live registry call.');
    }

    public function test_a_stale_cache_beyond_six_hours_with_an_unavailable_client_fails_closed(): void
    {
        $player = $this->player();
        RegistryExclusion::create([
            'ninHash' => $player->ninHash,
            'registryName' => 'Test Registry',
            'excluded' => false,
            'checkedAt' => now()->subHours(7),
        ]);

        $failing = new class implements RegistryClient {
            public function registryName(): string { return 'Test'; }
            public function isExcluded(string $ninHash): bool { throw new RegistryUnavailableException(); }
        };
        $service = new RegistryCheckService($failing);

        $this->assertSame('unavailable', $service->statusFor($player));
    }

    public function test_a_stale_but_under_six_hours_cache_still_serves_when_the_client_is_unavailable(): void
    {
        $player = $this->player();
        RegistryExclusion::create([
            'ninHash' => $player->ninHash,
            'registryName' => 'Test Registry',
            'excluded' => false,
            'checkedAt' => now()->subHours(2),
        ]);

        $failing = new class implements RegistryClient {
            public function registryName(): string { return 'Test'; }
            public function isExcluded(string $ninHash): bool { throw new RegistryUnavailableException(); }
        };
        $service = new RegistryCheckService($failing);

        $this->assertSame('clear', $service->statusFor($player));
    }

    public function test_registry_excluded_refuses_ticket_creation_with_no_money_moved(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', 1);
        RegistryExclusion::create([
            'ninHash' => $player->ninHash,
            'registryName' => 'Test Registry',
            'excluded' => true,
            'checkedAt' => now(),
        ]);

        try {
            app(CreateTicket::class)->create($player, ['B'], 10_000, 'registry-test-1');
            $this->fail('Expected TicketEligibilityException');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('EXCLUDED', $e->errorCode);
        }

        $this->assertSame(1_000_000, app(WalletService::class)->walletFor($player)->playBalanceKobo);
    }
}
