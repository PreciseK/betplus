<?php

declare(strict_types=1);

namespace App\Domain\Games\BirdEscape;

use App\Domain\Tax\TaxEngine;
use App\Domain\Wallet\WalletService;
use App\Models\CrashBet;
use Illuminate\Support\Facades\DB;

/**
 * The one place that touches WalletService/TaxEngine for a crash bet. Three call
 * sites settle bets: a player's manual cashout request, the round loop's auto-cashout
 * sweep, and the round loop's crash batch-loss sweep — all route through this class so
 * the "only one writer may settle a bet" guard exists in exactly one place, never
 * duplicated three times.
 *
 * Concurrency: settleCashout/settleLoss each hinge on a single conditional
 * `UPDATE crashBet SET status=... WHERE id=? AND status='PLACED'` — the same
 * primitive WalletService::reserveStake already uses for balance checks. Exactly one
 * writer's UPDATE can ever affect the row; every other concurrent caller sees 0 rows
 * affected and treats that as "already settled by someone else", never as an error.
 */
final class BirdEscapeSettlement
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly TaxEngine $tax,
    ) {
    }

    /** Returns false if the bet was already settled by a concurrent request/tick. */
    public function settleCashout(CrashBet $bet, int $multiplierHundredths, bool $auto): bool
    {
        return DB::transaction(function () use ($bet, $multiplierHundredths, $auto) {
            $affected = CrashBet::where('id', $bet->id)->where('status', 'PLACED')->update([
                'status' => 'CASHED_OUT',
                'cashedOutAtMultiplierHundredths' => $multiplierHundredths,
                'autoCashedOut' => $auto,
                'settledAt' => now(),
            ]);
            if ($affected !== 1) {
                return false;
            }

            $grossPrizeKobo = intdiv($bet->stakeKobo * $multiplierHundredths, 100);
            $withholding = $this->tax->withhold($grossPrizeKobo, $bet->player);

            CrashBet::where('id', $bet->id)->update([
                'grossPrizeKobo' => $grossPrizeKobo,
                'taxWithheldKobo' => $withholding->taxWithheldKobo,
                'netCreditKobo' => $withholding->netCreditKobo,
            ]);

            $this->wallet->settleWin(
                $bet->player,
                $bet->stakeKobo,
                $grossPrizeKobo,
                $withholding->taxWithheldKobo,
                $withholding->netCreditKobo,
                'crash_bet',
                $bet->id,
                $bet->stateCode,
            );

            return true;
        });
    }

    /** Returns false if the bet was already settled by a concurrent request/tick. */
    public function settleLoss(CrashBet $bet): bool
    {
        return DB::transaction(function () use ($bet) {
            $affected = CrashBet::where('id', $bet->id)->where('status', 'PLACED')
                ->update(['status' => 'LOST', 'settledAt' => now()]);
            if ($affected !== 1) {
                return false;
            }

            $this->wallet->settleLoss($bet->stakeKobo, 'crash_bet', $bet->id, $bet->stateCode);

            return true;
        });
    }
}
