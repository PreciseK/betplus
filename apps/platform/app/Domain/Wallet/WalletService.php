<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Ticket\TicketEligibilityException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Player;
use App\Models\PlayerWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Story 2.1/2.2 — the sole ledger writer (REQ-ARCH-001, REQ-ARCH-002). Nothing else in
 * the codebase should call LedgerEntry::create() or touch playerWallet directly.
 *
 * ponytail: REQ-ARCH-002's "only the Wallet Service credential may write ledger tables"
 * is a production DB-grant concern (a separate MySQL user with INSERT-only privilege on
 * these tables) — not expressible in dev sqlite, which has no user/grant system. What's
 * enforced and tested here: application-level sole-writer (only this class), and
 * DB-level immutability via the triggers on ledgerEntry (REQ-WAL-040). Add the grant
 * when a real MySQL deployment target exists.
 */
final class WalletService
{
    /** The only account types the ledger recognises (REQ-WAL-012). */
    private const ACCOUNT_TYPES = [
        'PLAYER_PLAY', 'PLAYER_WINNINGS', 'SUSPENSE', 'HOUSE_REVENUE',
        'PAYMENT_CLEARING', 'OPAY_FLOAT', 'PRIZE_LIABILITY',
        'WHT_PAYABLE', 'GGR_LEVY_PAYABLE', 'FEES', 'DRAW_TICKET_COST',
    ];

    private const CREDIT_NORMAL = ['PLAYER_PLAY', 'PLAYER_WINNINGS', 'HOUSE_REVENUE', 'PRIZE_LIABILITY', 'WHT_PAYABLE', 'GGR_LEVY_PAYABLE'];

    public function provisionWallet(Player $player): PlayerWallet
    {
        return PlayerWallet::firstOrCreate(['playerId' => $player->id]);
    }

    public function walletFor(Player $player): PlayerWallet
    {
        return $this->provisionWallet($player);
    }

    /**
     * The core primitive. Every other money movement in the system should ultimately
     * call this — never LedgerEntry::create() directly.
     *
     * @param list<LedgerLine> $lines
     */
    public function post(array $lines, ?string $referenceType = null, ?int $referenceId = null): string
    {
        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            if (!in_array($line->accountType, self::ACCOUNT_TYPES, true)) {
                throw new RuntimeException("Unknown ledger account type: {$line->accountType}");
            }
            if ($line->direction === 'debit') {
                $debits += $line->amountKobo;
            } elseif ($line->direction === 'credit') {
                $credits += $line->amountKobo;
            } else {
                throw new RuntimeException("Invalid ledger direction: {$line->direction}");
            }
        }

        if ($debits !== $credits) {
            Log::critical('Ledger imbalance rejected before write', ['debits' => $debits, 'credits' => $credits]);
            throw new LedgerImbalanceException("Ledger imbalance: debits=$debits credits=$credits");
        }

        $transactionGroup = (string) Str::ulid();

        DB::transaction(function () use ($lines, $transactionGroup, $referenceType, $referenceId) {
            foreach ($lines as $line) {
                $account = LedgerAccount::firstOrCreate(
                    ['type' => $line->accountType, 'scope' => $line->scope],
                    ['normalBalance' => in_array($line->accountType, self::CREDIT_NORMAL, true) ? 'credit' : 'debit'],
                );

                LedgerEntry::create([
                    'accountId' => $account->id,
                    'direction' => $line->direction,
                    'amountKobo' => $line->amountKobo,
                    'stateCode' => $line->stateCode,
                    'transactionGroup' => $transactionGroup,
                    'referenceType' => $referenceType,
                    'referenceId' => $referenceId,
                ]);
            }
        });

        return $transactionGroup;
    }

    /**
     * Deposits credit Play Balance only, never Winnings Balance (REQ-WAL-020 rule 1) —
     * enforced by this being the only funding-facing method, not a generic "credit any
     * balance" one.
     */
    public function creditPlayBalanceFromOpay(
        Player $player,
        int $amountKobo,
        string $referenceType,
        int $referenceId,
        ?string $stateCode = null,
    ): string {
        $group = $this->post([
            new LedgerLine('PAYMENT_CLEARING', 'OPAY', 'debit', $amountKobo, $stateCode),
            new LedgerLine('PLAYER_PLAY', (string) $player->id, 'credit', $amountKobo, $stateCode),
        ], $referenceType, $referenceId);

        $this->adjustCachedBalance($player, playDeltaKobo: $amountKobo, winningsDeltaKobo: 0);

        return $group;
    }

    /**
     * REQ-WAL-020 / REQ-TKT-012 — debits Play Balance into SUSPENSE. The balance check
     * is inherent in the conditional UPDATE below (`WHERE playBalanceKobo >= amount`),
     * so a concurrent second reservation cannot both succeed against the same headroom
     * — this IS the "re-asserted inside the commitment transaction" balance check.
     */
    public function reserveStake(Player $player, int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $wallet = $this->provisionWallet($player);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $affected = PlayerWallet::where('id', $wallet->id)
                ->where('version', $wallet->version)
                ->where('playBalanceKobo', '>=', $amountKobo)
                ->update([
                    'playBalanceKobo' => DB::raw("playBalanceKobo - ($amountKobo)"),
                    'version' => DB::raw('version + 1'),
                ]);

            if ($affected === 1) {
                return $this->post([
                    new LedgerLine('PLAYER_PLAY', (string) $player->id, 'debit', $amountKobo, $stateCode),
                    new LedgerLine('SUSPENSE', null, 'credit', $amountKobo, $stateCode),
                ], $referenceType, $referenceId);
            }

            $wallet = PlayerWallet::findOrFail($wallet->id);
            if ($wallet->playBalanceKobo < $amountKobo) {
                throw new TicketEligibilityException('INSUFFICIENT_PLAY_BALANCE', 'Stake exceeds Play Balance.');
            }
            // Otherwise a concurrent write raced the version — retry against fresh state.
        }

        throw new RuntimeException('Could not reserve stake after 3 concurrent-write retries.');
    }

    /** REQ-WAL-020 — a loss moves the reserved stake from SUSPENSE to HOUSE_REVENUE. */
    public function settleLoss(int $stakeKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        return $this->post([
            new LedgerLine('SUSPENSE', null, 'debit', $stakeKobo, $stateCode),
            new LedgerLine('HOUSE_REVENUE', null, 'credit', $stakeKobo, $stateCode),
        ], $referenceType, $referenceId);
    }

    /**
     * REQ-WAL-020 / REQ-TAX-005 — a win clears the reserved stake from SUSPENSE, funds
     * the excess over stake from HOUSE_REVENUE (the house's accumulated take from other
     * tickets funds this one's prize — how a fixed-odds book works), and credits
     * Winnings Balance net of the withholding posted to WHT_PAYABLE:{state}.
     */
    public function settleWin(Player $player, int $stakeKobo, int $grossPrizeKobo, int $taxWithheldKobo, int $netCreditKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $excessKobo = $grossPrizeKobo - $stakeKobo;

        $group = $this->post(array_values(array_filter([
            $excessKobo > 0 ? new LedgerLine('HOUSE_REVENUE', null, 'debit', $excessKobo, $stateCode) : null,
            new LedgerLine('SUSPENSE', null, 'debit', $stakeKobo, $stateCode),
            $taxWithheldKobo > 0 ? new LedgerLine('WHT_PAYABLE', $stateCode, 'credit', $taxWithheldKobo, $stateCode) : null,
            new LedgerLine('PLAYER_WINNINGS', (string) $player->id, 'credit', $netCreditKobo, $stateCode),
        ])), $referenceType, $referenceId);

        $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: $netCreditKobo);

        return $group;
    }

    /**
     * Story 4.6 — a withdrawal request reserves the amount out of Winnings Balance
     * immediately (conditional UPDATE, same insufficient-funds protection as
     * reserveStake), so a second concurrent withdrawal request can't double-spend the
     * same funds while the first is still in flight to OPay.
     */
    public function reserveWinningsForWithdrawal(Player $player, int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $wallet = $this->provisionWallet($player);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $affected = PlayerWallet::where('id', $wallet->id)
                ->where('version', $wallet->version)
                ->where('winningsBalanceKobo', '>=', $amountKobo)
                ->update([
                    'winningsBalanceKobo' => DB::raw("winningsBalanceKobo - ($amountKobo)"),
                    'version' => DB::raw('version + 1'),
                ]);

            if ($affected === 1) {
                return $this->post([
                    new LedgerLine('PLAYER_WINNINGS', (string) $player->id, 'debit', $amountKobo, $stateCode),
                    new LedgerLine('PAYMENT_CLEARING', 'OPAY', 'credit', $amountKobo, $stateCode),
                ], $referenceType, $referenceId);
            }

            $wallet = PlayerWallet::findOrFail($wallet->id);
            if ($wallet->winningsBalanceKobo < $amountKobo) {
                throw new RuntimeException('Withdrawal amount exceeds Winnings Balance.');
            }
        }

        throw new RuntimeException('Could not reserve withdrawal after 3 concurrent-write retries.');
    }

    /** A withdrawal OPay confirms as SUCCESS: the clearing reservation is consumed by the float. */
    public function confirmWithdrawalPayout(int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        return $this->post([
            new LedgerLine('PAYMENT_CLEARING', 'OPAY', 'debit', $amountKobo, $stateCode),
            new LedgerLine('OPAY_FLOAT', null, 'credit', $amountKobo, $stateCode),
        ], $referenceType, $referenceId);
    }

    /**
     * REQ-PO-009 in spirit, applied to a player-initiated withdrawal: a confirmed
     * failure after retries returns the reserved amount — a failed transfer never just
     * disappears.
     */
    public function refundFailedWithdrawal(Player $player, int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $group = $this->post([
            new LedgerLine('PAYMENT_CLEARING', 'OPAY', 'debit', $amountKobo, $stateCode),
            new LedgerLine('PLAYER_WINNINGS', (string) $player->id, 'credit', $amountKobo, $stateCode),
        ], $referenceType, $referenceId);

        $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: $amountKobo);

        return $group;
    }

    /**
     * REQ-PO-003/REQ-PO-008 — an automatic prize was already credited to Winnings
     * Balance at settlement (settleWin). This is the money actually LEAVING once OPay
     * confirms — never called on failure, so a failed automatic disbursement leaves the
     * credited prize untouched, exactly as REQ-PO-008 requires.
     */
    public function confirmPrizePayout(Player $player, int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $group = $this->post([
            new LedgerLine('PLAYER_WINNINGS', (string) $player->id, 'debit', $amountKobo, $stateCode),
            new LedgerLine('OPAY_FLOAT', null, 'credit', $amountKobo, $stateCode),
        ], $referenceType, $referenceId);

        $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: -$amountKobo);

        return $group;
    }

    /**
     * REQ-BO-006 — the only way a settled/historical outcome is ever corrected: never
     * an edit to the original record, always a new compensating entry with a recorded
     * reason. Routes through maker-checker (Domain/BackOffice/MakerChecker), never
     * called directly from a back-office controller. HOUSE_REVENUE is the balancing
     * side — the same account that already absorbs forfeited stakes and funds prizes
     * (settleWin/settleLoss), since a manual correction is economically the same kind
     * of house-borne cost or recovery.
     */
    public function manualCredit(Player $player, string $balance, int $amountKobo, string $referenceType, int $referenceId): string
    {
        $account = $balance === 'WINNINGS' ? 'PLAYER_WINNINGS' : 'PLAYER_PLAY';
        $group = $this->post([
            new LedgerLine('HOUSE_REVENUE', null, 'debit', $amountKobo),
            new LedgerLine($account, (string) $player->id, 'credit', $amountKobo),
        ], $referenceType, $referenceId);

        $balance === 'WINNINGS'
            ? $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: $amountKobo)
            : $this->adjustCachedBalance($player, playDeltaKobo: $amountKobo, winningsDeltaKobo: 0);

        return $group;
    }

    public function manualDebit(Player $player, string $balance, int $amountKobo, string $referenceType, int $referenceId): string
    {
        $account = $balance === 'WINNINGS' ? 'PLAYER_WINNINGS' : 'PLAYER_PLAY';
        $wallet = $this->provisionWallet($player);
        $currentKobo = $balance === 'WINNINGS' ? $wallet->winningsBalanceKobo : $wallet->playBalanceKobo;
        if ($amountKobo > $currentKobo) {
            throw new RuntimeException('Manual debit exceeds the current balance.');
        }

        $group = $this->post([
            new LedgerLine($account, (string) $player->id, 'debit', $amountKobo),
            new LedgerLine('HOUSE_REVENUE', null, 'credit', $amountKobo),
        ], $referenceType, $referenceId);

        $balance === 'WINNINGS'
            ? $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: -$amountKobo)
            : $this->adjustCachedBalance($player, playDeltaKobo: -$amountKobo, winningsDeltaKobo: 0);

        return $group;
    }

    /**
     * Story 7.7 / REQ-HG-030 — a TIER_SECOND_CHANCE settlement is a loss for the
     * player's balance (settleLoss already moved the stake to HOUSE_REVENUE), but the
     * house separately incurs a real cost lodging the draw entry with the partner at
     * sc_stake_ratio of the player's stake. DRAW_TICKET_COST was declared in
     * ACCOUNT_TYPES from the start specifically for this — never posted to until now
     * because BlackRed has no draw entries.
     */
    public function recordDrawEntryCost(int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        return $this->post([
            new LedgerLine('HOUSE_REVENUE', null, 'debit', $amountKobo, $stateCode),
            new LedgerLine('DRAW_TICKET_COST', null, 'credit', $amountKobo, $stateCode),
        ], $referenceType, $referenceId);
    }

    /**
     * REQ-HG-037 — an entry that could not be lodged within 3 draws is returned to
     * the player at the value of the entry, told plainly. An automatic system
     * compensation, not a manual back-office correction — deliberately NOT
     * manualCredit(), which routes through maker-checker for a human-initiated
     * balance correction; this is triggered by SubmitSecondChanceEntryJob itself.
     */
    public function compensateFailedSecondChanceEntry(Player $player, int $amountKobo, string $referenceType, int $referenceId): string
    {
        $group = $this->post([
            new LedgerLine('HOUSE_REVENUE', null, 'debit', $amountKobo),
            new LedgerLine('PLAYER_PLAY', (string) $player->id, 'credit', $amountKobo),
        ], $referenceType, $referenceId);

        $this->adjustCachedBalance($player, playDeltaKobo: $amountKobo, winningsDeltaKobo: 0);

        return $group;
    }

    private function adjustCachedBalance(Player $player, int $playDeltaKobo, int $winningsDeltaKobo): void
    {
        $wallet = $this->provisionWallet($player);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $affected = PlayerWallet::where('id', $wallet->id)
                ->where('version', $wallet->version)
                ->update([
                    'playBalanceKobo' => DB::raw("playBalanceKobo + ($playDeltaKobo)"),
                    'winningsBalanceKobo' => DB::raw("winningsBalanceKobo + ($winningsDeltaKobo)"),
                    'version' => DB::raw('version + 1'),
                ]);

            if ($affected === 1) {
                return;
            }

            // Another writer updated this wallet between our read and write — re-read
            // the current version and retry (REQ-WAL-002 optimistic concurrency).
            $wallet = PlayerWallet::findOrFail($wallet->id);
        }

        throw new RuntimeException('Could not update playerWallet after 3 concurrent-write retries.');
    }
}
