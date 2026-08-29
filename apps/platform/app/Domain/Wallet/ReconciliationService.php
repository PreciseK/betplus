<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Models\AuditLog;
use App\Models\LedgerAccount;
use App\Models\LedgerDiscrepancy;
use App\Models\LedgerEntry;
use App\Models\PlayerWallet;

/**
 * Story 2.7 — nightly reconciliation (REQ-WAL-043, M11).
 *
 * Only the wallet-balance check is implemented. "Clearing accounts against OPay
 * settlement files" and "tax payable accounts against remittances made" both need a
 * real external data source this codebase doesn't have yet — no settlement file
 * ingestion exists, and no tax remittance is tracked anywhere (Epic 10 territory).
 * Add reconcileClearingAccounts()/reconcileTaxPayables() when those exist; a
 * discrepancy report against data nobody produces would just be a report of zero,
 * every time, which is worse than not claiming the check runs at all.
 */
final class ReconciliationService
{
    /** @return array{checked: int, discrepancies: int} */
    public function reconcileWalletBalances(): array
    {
        $checked = 0;
        $discrepancies = 0;

        PlayerWallet::chunkById(100, function ($wallets) use (&$checked, &$discrepancies) {
            foreach ($wallets as $wallet) {
                $checked++;

                $expectedPlay = $this->summedBalance('PLAYER_PLAY', (string) $wallet->playerId);
                if ($expectedPlay !== $wallet->playBalanceKobo) {
                    $this->recordDiscrepancy($wallet->playerId, $expectedPlay, $wallet->playBalanceKobo);
                    $discrepancies++;
                }

                $expectedWinnings = $this->summedBalance('PLAYER_WINNINGS', (string) $wallet->playerId);
                if ($expectedWinnings !== $wallet->winningsBalanceKobo) {
                    $this->recordDiscrepancy($wallet->playerId, $expectedWinnings, $wallet->winningsBalanceKobo);
                    $discrepancies++;
                }
            }
        });

        return ['checked' => $checked, 'discrepancies' => $discrepancies];
    }

    private function summedBalance(string $accountType, string $scope): int
    {
        $account = LedgerAccount::where('type', $accountType)->where('scope', $scope)->first();
        if ($account === null) {
            return 0; // no ledger activity for this account yet
        }

        // Both PLAYER_PLAY and PLAYER_WINNINGS are credit-normal (they increase what
        // Betplus owes the player) — see WalletService::CREDIT_NORMAL.
        $credits = (int) LedgerEntry::where('accountId', $account->id)->where('direction', 'credit')->sum('amountKobo');
        $debits = (int) LedgerEntry::where('accountId', $account->id)->where('direction', 'debit')->sum('amountKobo');

        return $credits - $debits;
    }

    private function recordDiscrepancy(int $playerId, int $expectedKobo, int $actualKobo): void
    {
        LedgerDiscrepancy::create([
            'checkType' => 'wallet_balance',
            'subjectId' => $playerId,
            'expectedKobo' => $expectedKobo,
            'actualKobo' => $actualKobo,
            'differenceKobo' => $expectedKobo - $actualKobo,
            'severity' => 'p1',
            'status' => 'open',
        ]);

        // No paging pipeline exists yet — see PollCollectionStatusJob's same caveat.
        // This is the record a real one would consume; Finance needs it in an
        // exception queue (Epic 6), not just logged where nobody looks.
        AuditLog::create([
            'actorType' => 'system',
            'action' => 'ledger.discrepancy.p1',
            'targetTable' => 'playerWallet',
            'targetId' => $playerId,
            'reason' => "Cached balance disagrees with the summed ledger by {$this->signed($expectedKobo - $actualKobo)} kobo.",
        ]);
    }

    private function signed(int $kobo): string
    {
        return $kobo >= 0 ? "+$kobo" : (string) $kobo;
    }
}
