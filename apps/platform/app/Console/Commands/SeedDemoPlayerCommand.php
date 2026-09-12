<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\PhoneNumber;
use App\Domain\Wallet\WalletService;
use App\Models\Collection;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Player;
use App\Models\PlayerWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SeedDemoPlayerCommand extends Command
{
    protected $signature = 'app:seed-demo-player {--phone=08000000000} {--name=Demo Player} {--naira=1000000}';

    protected $description = 'Seed or update a demo player with specified Naira balance (default 1,000,000 NGN).';

    public function handle(WalletService $walletService): int
    {
        $rawPhone = (string) $this->option('phone');
        $name = (string) $this->option('name');
        $naira = (int) $this->option('naira');
        $amountKobo = $naira * 100; // Naira to kobo

        $msisdn = PhoneNumber::toE164($rawPhone);

        $this->info("Setting up Demo Player for {$msisdn} ({$name}) with ₦" . number_format($naira, 2) . " ({$amountKobo} kobo)...");

        $player = Player::updateOrCreate(
            ['msisdn' => $msisdn],
            [
                'registeredName' => $name,
                'displayName' => $name,
                'email' => 'demo@betplus.ng',
                'kycTier' => 2,
                'kycStatus' => 'verified',
                'accountStatus' => 'active',
                'residencyStatus' => 'resident',
                'registrationChannel' => 'web',
                'bvnVerifiedAt' => now(),
                'ninHash' => hash('sha256', '12345678901'),
                'ninVerifiedAt' => now(),
            ]
        );

        $wallet = PlayerWallet::firstOrCreate(
            ['playerId' => $player->id],
            [
                'playBalanceKobo' => 0,
                'winningsBalanceKobo' => 0,
                'version' => 0,
            ]
        );

        // Update balance directly and post balanced ledger entries
        DB::transaction(function () use ($player, $wallet, $amountKobo, $naira) {
            $wallet->update([
                'playBalanceKobo' => $amountKobo,
                'winningsBalanceKobo' => 0,
                'version' => $wallet->version + 1,
            ]);

            $clearingAccount = LedgerAccount::firstOrCreate(
                ['type' => 'PAYMENT_CLEARING', 'scope' => 'OPAY'],
                ['normalBalance' => 'debit']
            );

            $playerAccount = LedgerAccount::firstOrCreate(
                ['type' => 'PLAYER_PLAY', 'scope' => (string) $player->id],
                ['normalBalance' => 'credit']
            );

            $txGroup = (string) Str::ulid();
            $ref = 'DEMO-' . strtoupper(Str::random(10));

            LedgerEntry::create([
                'accountId' => $clearingAccount->id,
                'direction' => 'debit',
                'amountKobo' => $amountKobo,
                'stateCode' => 'LA',
                'transactionGroup' => $txGroup,
                'referenceType' => 'COLLECTION',
                'referenceId' => $player->id,
            ]);

            LedgerEntry::create([
                'accountId' => $playerAccount->id,
                'direction' => 'credit',
                'amountKobo' => $amountKobo,
                'stateCode' => 'LA',
                'transactionGroup' => $txGroup,
                'referenceType' => 'COLLECTION',
                'referenceId' => $player->id,
            ]);

            Collection::create([
                'playerId' => $player->id,
                'reference' => $ref,
                'amountKobo' => $amountKobo,
                'feeKobo' => 0,
                'providerCollectionId' => 'OPAY-DEMO-' . Str::random(8),
                'status' => 'paid',
                'paidAt' => now(),
            ]);
        });

        $this->info("✅ Demo player successfully configured! Phone: {$rawPhone} ({$msisdn}), Balance: ₦" . number_format($naira, 2));

        return 0;
    }
}
