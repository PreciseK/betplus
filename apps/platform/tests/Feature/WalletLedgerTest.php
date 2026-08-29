<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\LedgerImbalanceException;
use App\Domain\Wallet\LedgerLine;
use App\Domain\Wallet\WalletService;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Player;
use App\Models\PlayerWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function player(): Player
    {
        return Player::create(['msisdn' => '+2348031234567', 'registeredName' => 'Ada Okafor', 'registrationChannel' => 'web']);
    }

    public function test_balanced_entries_write_successfully(): void
    {
        $group = app(WalletService::class)->post([
            new LedgerLine('PAYMENT_CLEARING', 'OPAY', 'debit', 100_000),
            new LedgerLine('PLAYER_PLAY', '1', 'credit', 100_000),
        ]);

        $this->assertSame(2, LedgerEntry::where('transactionGroup', $group)->count());
    }

    public function test_imbalanced_entries_are_rejected_and_write_nothing(): void
    {
        $this->expectException(LedgerImbalanceException::class);

        try {
            app(WalletService::class)->post([
                new LedgerLine('PAYMENT_CLEARING', 'OPAY', 'debit', 100_000),
                new LedgerLine('PLAYER_PLAY', '1', 'credit', 90_000),
            ]);
        } finally {
            $this->assertSame(0, LedgerEntry::count());
        }
    }

    public function test_ledger_entries_are_immutable(): void
    {
        app(WalletService::class)->post([
            new LedgerLine('PAYMENT_CLEARING', 'OPAY', 'debit', 100_000),
            new LedgerLine('PLAYER_PLAY', '1', 'credit', 100_000),
        ]);
        $entry = LedgerEntry::first();

        $this->expectException(\Throwable::class);
        DB::table('ledgerEntry')->where('id', $entry->id)->update(['amountKobo' => 1]);
    }

    public function test_ledger_entries_cannot_be_deleted(): void
    {
        app(WalletService::class)->post([
            new LedgerLine('PAYMENT_CLEARING', 'OPAY', 'debit', 100_000),
            new LedgerLine('PLAYER_PLAY', '1', 'credit', 100_000),
        ]);
        $entry = LedgerEntry::first();

        $this->expectException(\Throwable::class);
        DB::table('ledgerEntry')->where('id', $entry->id)->delete();
    }

    public function test_unknown_account_type_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        app(WalletService::class)->post([
            new LedgerLine('NOT_A_REAL_ACCOUNT', null, 'debit', 100),
            new LedgerLine('PLAYER_PLAY', '1', 'credit', 100),
        ]);
    }

    public function test_provisioning_creates_exactly_one_wallet_per_player(): void
    {
        $player = $this->player();
        $service = app(WalletService::class);

        $service->provisionWallet($player);
        $service->provisionWallet($player);

        $this->assertSame(1, PlayerWallet::where('playerId', $player->id)->count());
    }

    public function test_deposit_credits_play_balance_only(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 250_000, 'collection', 1);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(250_000, $wallet->playBalanceKobo);
        $this->assertSame(0, $wallet->winningsBalanceKobo);
    }

    public function test_named_accounts_are_created_with_correct_scope(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1, 'LA');

        $this->assertDatabaseHas('ledgerAccount', ['type' => 'PLAYER_PLAY', 'scope' => (string) $player->id, 'normalBalance' => 'credit']);
        $this->assertDatabaseHas('ledgerAccount', ['type' => 'PAYMENT_CLEARING', 'scope' => 'OPAY', 'normalBalance' => 'debit']);
    }

    public function test_repeated_deposits_accumulate_correctly_under_optimistic_concurrency(): void
    {
        $player = $this->player();
        $service = app(WalletService::class);

        $service->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);
        $service->creditPlayBalanceFromOpay($player, 50_000, 'collection', 2);
        $service->creditPlayBalanceFromOpay($player, 25_000, 'collection', 3);

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame(175_000, $wallet->playBalanceKobo);
        $this->assertSame(3, $wallet->version);
    }

    public function test_cached_balance_matches_summed_ledger(): void
    {
        $player = $this->player();
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 50_000, 'collection', 2);

        $account = LedgerAccount::where('type', 'PLAYER_PLAY')->where('scope', (string) $player->id)->first();
        $summed = LedgerEntry::where('accountId', $account->id)->where('direction', 'credit')->sum('amountKobo')
            - LedgerEntry::where('accountId', $account->id)->where('direction', 'debit')->sum('amountKobo');

        $wallet = PlayerWallet::where('playerId', $player->id)->first();
        $this->assertSame($summed, $wallet->playBalanceKobo);
    }
}
