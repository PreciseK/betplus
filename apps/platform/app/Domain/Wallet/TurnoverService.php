<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Player;

/**
 * REQ-WAL-030 — a deposit becomes withdrawable from Play Balance once cumulative
 * stakes equal or exceed it, tracked per deposit, FIFO. What's built here is a
 * lifetime aggregate (total staked vs total deposited, capped at the deposit total),
 * not the real per-deposit FIFO ledger REQ-WAL-030 specifies — good enough for
 * display (Story 3.9's BlackRed descriptor, Story 4.6's withdrawal-source screen),
 * but WithdrawalFlow's "Released Play Balance" option is disabled in the frontend
 * precisely because that path isn't real yet. Story 4.5 (the actual FIFO tracker and
 * the Compliance-referral path for withdrawing an un-staked deposit) is not built.
 */
final class TurnoverService
{
    /** @return array{stakedKobo:int, requiredKobo:int, releasedPlayBalanceKobo:int} */
    public function positionFor(Player $player): array
    {
        $account = LedgerAccount::where('type', 'PLAYER_PLAY')->where('scope', (string) $player->id)->first();
        if ($account === null) {
            return ['stakedKobo' => 0, 'requiredKobo' => 0, 'releasedPlayBalanceKobo' => 0];
        }

        $depositedKobo = (int) LedgerEntry::where('accountId', $account->id)
            ->where('direction', 'credit')->where('referenceType', 'collection')->sum('amountKobo');
        $stakedKobo = (int) LedgerEntry::where('accountId', $account->id)
            ->where('direction', 'debit')->where('referenceType', 'ticket')->sum('amountKobo');
        $stakedCapped = min($stakedKobo, $depositedKobo);

        return [
            'stakedKobo' => $stakedCapped,
            'requiredKobo' => $depositedKobo,
            // Not a real released balance — see class doc. Withdrawal from this figure
            // isn't reachable through the UI (WithdrawalFlow disables that option).
            'releasedPlayBalanceKobo' => 0,
        ];
    }
}
