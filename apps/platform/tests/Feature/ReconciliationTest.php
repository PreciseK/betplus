<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\ReconciliationService;
use App\Domain\Wallet\WalletService;
use App\Models\LedgerDiscrepancy;
use App\Models\Player;
use App\Models\PlayerWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function player(): Player
    {
        return Player::create(['msisdn' => '+2348031234567', 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web']);
    }

    public function test_a_correctly_credited_wallet_has_no_discrepancy(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);

        $result = app(ReconciliationService::class)->reconcileWalletBalances();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['discrepancies']);
        $this->assertSame(0, LedgerDiscrepancy::count());
    }

    public function test_failure_injection_a_corrupted_cached_balance_is_detected_and_escalated(): void
    {
        // Simulates exactly the invariant violation REQ-QA-007 asks to be caught: the
        // ledger (source of truth) and the cached balance have drifted apart. Nothing
        // in this codebase does this in normal operation — a bug or manual DB edit would.
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);
        PlayerWallet::where('playerId', $player->id)->update(['playBalanceKobo' => 999_999]);

        $result = app(ReconciliationService::class)->reconcileWalletBalances();

        $this->assertSame(1, $result['discrepancies']);
        $discrepancy = LedgerDiscrepancy::first();
        $this->assertSame('wallet_balance', $discrepancy->checkType);
        $this->assertSame($player->id, $discrepancy->subjectId);
        $this->assertSame(100_000, $discrepancy->expectedKobo);
        $this->assertSame(999_999, $discrepancy->actualKobo);
        $this->assertSame('p1', $discrepancy->severity);
        $this->assertDatabaseHas('auditLog', ['action' => 'ledger.discrepancy.p1', 'targetId' => $player->id]);
    }

    public function test_reconcile_command_exits_non_zero_when_a_discrepancy_is_found(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);
        PlayerWallet::where('playerId', $player->id)->update(['playBalanceKobo' => 0]);

        $this->artisan('ledger:reconcile')->assertExitCode(1);
    }

    public function test_reconcile_command_exits_zero_when_the_ledger_is_clean(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);

        $this->artisan('ledger:reconcile')->assertExitCode(0);
    }

    public function test_a_wallet_never_credited_still_reconciles_cleanly_at_zero(): void
    {
        app(WalletService::class)->provisionWallet($this->player());

        $result = app(ReconciliationService::class)->reconcileWalletBalances();

        $this->assertSame(0, $result['discrepancies']);
    }
}
