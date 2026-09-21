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
        'PLAYER_PLAY', 'PLAYER_WINNINGS', 'PLAYER_BONUS', 'SUSPENSE', 'HOUSE_REVENUE',
        'PAYMENT_CLEARING', 'OPAY_FLOAT', 'PRIZE_LIABILITY',
        'WHT_PAYABLE', 'GGR_LEVY_PAYABLE', 'FEES', 'DRAW_TICKET_COST',
        'MARKETING_EXPENSE', 'BONUS_EXPENSE', 'PROMO_DRAW_POOL', 'RESERVE_FUND',
    ];

    private const CREDIT_NORMAL = [
        'PLAYER_PLAY', 'PLAYER_WINNINGS', 'PLAYER_BONUS', 'HOUSE_REVENUE',
        'PRIZE_LIABILITY', 'WHT_PAYABLE', 'GGR_LEVY_PAYABLE', 'PROMO_DRAW_POOL', 'RESERVE_FUND',
    ];

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
     * Inspects the ledger to determine how much of a stake reservation came from PLAYER_BONUS.
     */
    public function bonusStakeFor(string $referenceType, int $referenceId): int
    {
        return (int) DB::table('ledgerEntry')
            ->join('ledgerAccount', 'ledgerEntry.accountId', '=', 'ledgerAccount.id')
            ->where('ledgerEntry.referenceType', $referenceType)
            ->where('ledgerEntry.referenceId', $referenceId)
            ->where('ledgerAccount.type', 'PLAYER_BONUS')
            ->where('ledgerEntry.direction', 'debit')
            ->sum('ledgerEntry.amountKobo');
    }

    /**
     * REQ-WAL-020 / REQ-TKT-012 — debits available balance into SUSPENSE.
     * Wager Priority Engine: consumes bonusBalanceKobo first, then playBalanceKobo for the remainder.
     */
    public function reserveStake(Player $player, int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $wallet = $this->provisionWallet($player);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $availableBonusKobo = (int) $wallet->bonusBalanceKobo;
            $bonusUsedKobo = min($availableBonusKobo, $amountKobo);
            $playUsedKobo = $amountKobo - $bonusUsedKobo;

            $query = PlayerWallet::where('id', $wallet->id)
                ->where('version', $wallet->version)
                ->where('playBalanceKobo', '>=', $playUsedKobo);

            if ($bonusUsedKobo > 0) {
                $query->where('bonusBalanceKobo', '>=', $bonusUsedKobo);
            }

            $updates = [
                'version' => DB::raw('version + 1'),
            ];
            if ($playUsedKobo > 0) {
                $updates['playBalanceKobo'] = DB::raw("playBalanceKobo - ($playUsedKobo)");
            }
            if ($bonusUsedKobo > 0) {
                $updates['bonusBalanceKobo'] = DB::raw("bonusBalanceKobo - ($bonusUsedKobo)");
            }

            $affected = $query->update($updates);

            if ($affected === 1) {
                $lines = [];
                if ($bonusUsedKobo > 0) {
                    $lines[] = new LedgerLine('PLAYER_BONUS', (string) $player->id, 'debit', $bonusUsedKobo, $stateCode);
                    $lines[] = new LedgerLine('SUSPENSE', null, 'credit', $bonusUsedKobo, $stateCode);
                }
                if ($playUsedKobo > 0) {
                    $lines[] = new LedgerLine('PLAYER_PLAY', (string) $player->id, 'debit', $playUsedKobo, $stateCode);
                    $lines[] = new LedgerLine('SUSPENSE', null, 'credit', $playUsedKobo, $stateCode);
                }

                return $this->post($lines, $referenceType, $referenceId);
            }

            $wallet = PlayerWallet::findOrFail($wallet->id);
            $totalHeadroomKobo = (int) $wallet->playBalanceKobo + (int) $wallet->bonusBalanceKobo;
            if ($totalHeadroomKobo < $amountKobo) {
                throw new TicketEligibilityException('INSUFFICIENT_PLAY_BALANCE', 'Your stake exceeds your available balance. Please top up your wallet or enter a lower stake.');
            }
            // Otherwise a concurrent write raced the version — retry against fresh state.
        }

        throw new RuntimeException('Could not reserve stake after 3 concurrent-write retries.');
    }

    /** REQ-WAL-020 — a loss moves reserved cash to HOUSE_REVENUE and reserved bonus to BONUS_EXPENSE. */
    public function settleLoss(int $stakeKobo, string $referenceType, int $referenceId, ?string $stateCode = null, ?int $bonusStakeKobo = null): string
    {
        $bonusUsedKobo = $bonusStakeKobo ?? $this->bonusStakeFor($referenceType, $referenceId);
        $playUsedKobo = $stakeKobo - $bonusUsedKobo;

        $lines = [];
        if ($bonusUsedKobo > 0) {
            $lines[] = new LedgerLine('SUSPENSE', null, 'debit', $bonusUsedKobo, $stateCode);
            $lines[] = new LedgerLine('BONUS_EXPENSE', null, 'credit', $bonusUsedKobo, $stateCode);
        }
        if ($playUsedKobo > 0) {
            $lines[] = new LedgerLine('SUSPENSE', null, 'debit', $playUsedKobo, $stateCode);
            $lines[] = new LedgerLine('HOUSE_REVENUE', null, 'credit', $playUsedKobo, $stateCode);
        }

        return $this->post($lines, $referenceType, $referenceId);
    }

    /**
     * REQ-WAL-020 / REQ-TAX-005 — a win clears reserved stake from SUSPENSE, funds
     * excess from HOUSE_REVENUE, reclaims bonus principal to BONUS_EXPENSE (1x playthrough rule),
     * and credits net profit won + real cash stake to Winnings Balance.
     */
    public function settleWin(
        Player $player,
        int $stakeKobo,
        int $grossPrizeKobo,
        int $taxWithheldKobo,
        int $netCreditKobo,
        string $referenceType,
        int $referenceId,
        ?string $stateCode = null,
        ?int $bonusStakeKobo = null,
    ): string {
        $bonusUsedKobo = $bonusStakeKobo ?? $this->bonusStakeFor($referenceType, $referenceId);
        $excessKobo = $grossPrizeKobo - $stakeKobo;

        // Under 1x playthrough rule: bonus principal ($bonusUsedKobo) is reclaimed by house to BONUS_EXPENSE.
        // Net profit won + real cash stake returns to winnings.
        $actualNetCreditKobo = $netCreditKobo - $bonusUsedKobo;

        $lines = [];
        if ($excessKobo > 0) {
            $lines[] = new LedgerLine('HOUSE_REVENUE', null, 'debit', $excessKobo, $stateCode);
        } elseif ($excessKobo < 0) {
            $lines[] = new LedgerLine('HOUSE_REVENUE', null, 'credit', -$excessKobo, $stateCode);
        }
        $lines[] = new LedgerLine('SUSPENSE', null, 'debit', $stakeKobo, $stateCode);

        if ($bonusUsedKobo > 0) {
            $lines[] = new LedgerLine('BONUS_EXPENSE', null, 'credit', $bonusUsedKobo, $stateCode);
        }
        if ($taxWithheldKobo > 0) {
            $lines[] = new LedgerLine('WHT_PAYABLE', $stateCode, 'credit', $taxWithheldKobo, $stateCode);
        }
        if ($actualNetCreditKobo > 0) {
            $lines[] = new LedgerLine('PLAYER_WINNINGS', (string) $player->id, 'credit', $actualNetCreditKobo, $stateCode);
        }

        $group = $this->post($lines, $referenceType, $referenceId);

        if ($actualNetCreditKobo > 0) {
            $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: $actualNetCreditKobo);
        }

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

    /**
     * Credits bonus play credits into PLAYER_BONUS and updates cached bonusBalanceKobo.
     * Non-withdrawable play credits.
     */
    public function creditBonusPlayBalance(Player $player, int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $group = $this->post([
            new LedgerLine('BONUS_EXPENSE', null, 'debit', $amountKobo, $stateCode),
            new LedgerLine('PLAYER_BONUS', (string) $player->id, 'credit', $amountKobo, $stateCode),
        ], $referenceType, $referenceId);

        $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: 0, bonusDeltaKobo: $amountKobo);

        return $group;
    }

    /**
     * Burns expired bonus credits from PLAYER_BONUS back to BONUS_EXPENSE.
     */
    public function expireBonusPlayBalance(Player $player, int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $group = $this->post([
            new LedgerLine('PLAYER_BONUS', (string) $player->id, 'debit', $amountKobo, $stateCode),
            new LedgerLine('BONUS_EXPENSE', null, 'credit', $amountKobo, $stateCode),
        ], $referenceType, $referenceId);

        $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: 0, bonusDeltaKobo: -$amountKobo);

        return $group;
    }

    /**
     * Settle Weekend Double Odds boost bonus from marketing subvention to player winnings.
     */
    public function settleMarketingBoost(Player $player, int $boostBonusKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $group = $this->post([
            new LedgerLine('MARKETING_EXPENSE', null, 'debit', $boostBonusKobo, $stateCode),
            new LedgerLine('PLAYER_WINNINGS', (string) $player->id, 'credit', $boostBonusKobo, $stateCode),
        ], $referenceType, $referenceId);

        $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: $boostBonusKobo, bonusDeltaKobo: 0);

        return $group;
    }

    /**
     * Settle Monthly VIP Draw cash prize to player winnings from the promotional pool reserve.
     */
    public function settleMonthlyDrawPrize(Player $player, int $prizeKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $group = $this->post([
            new LedgerLine('PROMO_DRAW_POOL', null, 'debit', $prizeKobo, $stateCode),
            new LedgerLine('PLAYER_WINNINGS', (string) $player->id, 'credit', $prizeKobo, $stateCode),
        ], $referenceType, $referenceId);

        $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: $prizeKobo, bonusDeltaKobo: 0);

        return $group;
    }

    /**
     * Allocates monthly turnover rake from HOUSE_REVENUE to PROMO_DRAW_POOL.
     */
    public function allocateMonthlyDrawPool(int $rakeAmountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        return $this->post([
            new LedgerLine('HOUSE_REVENUE', null, 'debit', $rakeAmountKobo, $stateCode),
            new LedgerLine('PROMO_DRAW_POOL', null, 'credit', $rakeAmountKobo, $stateCode),
        ], $referenceType, $referenceId);
    }

    /**
     * Model 1's reserve-fund siphon (BalancedHybridParams::reserveSiphonBps) — moves a
     * slice of a day's net GGR from HOUSE_REVENUE into the segregated RESERVE_FUND
     * account, same shape as allocateMonthlyDrawPool.
     */
    public function allocateReserveFund(int $amountKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        return $this->post([
            new LedgerLine('HOUSE_REVENUE', null, 'debit', $amountKobo, $stateCode),
            new LedgerLine('RESERVE_FUND', null, 'credit', $amountKobo, $stateCode),
        ], $referenceType, $referenceId);
    }

    /**
     * Model 4 (pari-mutuel pool), step 1 of settlement — releases an ENTIRE pool's
     * stakes from SUSPENSE in one aggregate posting (winners' and losers' stakes
     * alike; individual entries are never settled one-by-one the way an instant
     * ticket is, because a pooled stake's fate was never tied to only its own
     * ticket). The rake goes straight to HOUSE_REVENUE; everything else becomes a
     * PRIZE_LIABILITY the house owes to whichever entries end up matching the draw
     * — same "money set aside for a future payout" shape as PROMO_DRAW_POOL, except
     * this liability can roll forward across draws when nobody wins (see
     * PoolPayoutCalculator/PoolDraw.rolloverInKobo).
     *
     * ponytail: does not split bonus-vs-play stake per entry the way settleLoss/
     * settleWin do — every pooled stake is treated as real cash for ledger
     * purposes. Bonus-funded stakes losing into a pool with dozens of other
     * players' money has no clean "give the bonus principal back to BONUS_EXPENSE"
     * story once it's merged; revisit if pari-mutuel pools ever need to accept
     * bonus balance at all.
     */
    public function closePariMutuelPool(int $grossStakedKobo, int $rakeKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $lines = [new LedgerLine('SUSPENSE', null, 'debit', $grossStakedKobo, $stateCode)];

        if ($rakeKobo > 0) {
            $lines[] = new LedgerLine('HOUSE_REVENUE', null, 'credit', $rakeKobo, $stateCode);
        }

        $liabilityKobo = $grossStakedKobo - $rakeKobo;
        if ($liabilityKobo > 0) {
            $lines[] = new LedgerLine('PRIZE_LIABILITY', null, 'credit', $liabilityKobo, $stateCode);
        }

        return $this->post($lines, $referenceType, $referenceId);
    }

    /**
     * Model 4, step 2 — pays one winning pool entry's share out of PRIZE_LIABILITY
     * (already funded by closePariMutuelPool, possibly across several rolled-over
     * draws). Unlike settleWin, there is no per-ticket stake-release line here —
     * that already happened in aggregate — and no bonus reclaim, for the same
     * reason closePariMutuelPool doesn't split bonus-vs-play.
     */
    public function settlePariMutuelWin(Player $player, int $grossPayoutKobo, int $taxWithheldKobo, int $netCreditKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        $lines = [new LedgerLine('PRIZE_LIABILITY', null, 'debit', $grossPayoutKobo, $stateCode)];

        if ($taxWithheldKobo > 0) {
            $lines[] = new LedgerLine('WHT_PAYABLE', $stateCode, 'credit', $taxWithheldKobo, $stateCode);
        }
        if ($netCreditKobo > 0) {
            $lines[] = new LedgerLine('PLAYER_WINNINGS', (string) $player->id, 'credit', $netCreditKobo, $stateCode);
        }

        $group = $this->post($lines, $referenceType, $referenceId);

        if ($netCreditKobo > 0) {
            $this->adjustCachedBalance($player, playDeltaKobo: 0, winningsDeltaKobo: $netCreditKobo);
        }

        return $group;
    }

    /**
     * Model 4, step 3 — the few kobo PoolPayoutCalculator's integer-division split
     * leaves in PRIZE_LIABILITY after every winner is paid sweep to HOUSE_REVENUE,
     * so the liability account never carries a permanent, unexplained dust balance.
     */
    public function sweepPariMutuelRemainder(int $remainderKobo, string $referenceType, int $referenceId, ?string $stateCode = null): string
    {
        return $this->post([
            new LedgerLine('PRIZE_LIABILITY', null, 'debit', $remainderKobo, $stateCode),
            new LedgerLine('HOUSE_REVENUE', null, 'credit', $remainderKobo, $stateCode),
        ], $referenceType, $referenceId);
    }

    private function adjustCachedBalance(Player $player, int $playDeltaKobo, int $winningsDeltaKobo, int $bonusDeltaKobo = 0): void
    {
        $wallet = $this->provisionWallet($player);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $affected = PlayerWallet::where('id', $wallet->id)
                ->where('version', $wallet->version)
                ->update([
                    'playBalanceKobo' => DB::raw("playBalanceKobo + ($playDeltaKobo)"),
                    'winningsBalanceKobo' => DB::raw("winningsBalanceKobo + ($winningsDeltaKobo)"),
                    'bonusBalanceKobo' => DB::raw("bonusBalanceKobo + ($bonusDeltaKobo)"),
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
