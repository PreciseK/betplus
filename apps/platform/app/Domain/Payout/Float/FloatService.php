<?php

declare(strict_types=1);

namespace App\Domain\Payout\Float;

use App\Domain\Payments\Providers\Opay\OpayGateway;
use App\Models\FloatSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Story 4.7/4.8 — REQ-FLOAT-001..004. Threshold tiers are multiples of expected daily
 * payout (config), plus a halt floor covering the largest theoretical single prize
 * (REQ-FLOAT-004) so a maximum-stake maximum-multiplier win is always payable.
 *
 * No real paging integration exists (PagerDuty etc.) — "pages Finance and Ops" /
 * "pages executives" resolve to a structured log entry at critical/emergency level.
 * Wiring a real on-call pager is ops integration work, not application logic.
 */
final class FloatService
{
    public function __construct(private readonly OpayGateway $opay)
    {
    }

    public function snapshot(): FloatSnapshot
    {
        $balanceKobo = $this->opay->floatBalanceKobo();
        $alertState = $balanceKobo === null ? 'critical' : $this->classify($balanceKobo);

        $snapshot = FloatSnapshot::create([
            'opayBalanceKobo' => $balanceKobo ?? 0,
            'alertState' => $alertState,
            'polledAt' => now(),
        ]);

        $this->alertIfNeeded($snapshot);

        return $snapshot;
    }

    public function latestAlertState(): string
    {
        $latest = FloatSnapshot::orderByDesc('polledAt')->first();

        return $latest !== null ? $latest->alertState : 'ok';
    }

    /**
     * Model 1's Kelly-style stake/exposure caps read this — the current OPay float, in
     * kobo. A zero result (no snapshot ever taken) makes every Kelly-style cap 0,
     * failing every stake closed — deliberate fail-closed behaviour for a house-side
     * risk control, not a bug, but worth knowing if this ever surfaces as "every bet
     * rejected" in an environment where FloatService::snapshot() has never run.
     */
    public function currentFloatKobo(): int
    {
        $latest = FloatSnapshot::orderByDesc('polledAt')->first();

        return $latest->opayBalanceKobo ?? 0;
    }

    /** REQ-FLOAT-006 — true means automatic disbursement should queue rather than call OPay. */
    public function isHalted(): bool
    {
        return $this->latestAlertState() === 'halt';
    }

    private function classify(int $balanceKobo): string
    {
        $expectedDaily = (int) config('payout.expected_daily_payout_kobo');
        $largestPrize = (int) config('payout.largest_theoretical_prize_kobo');

        if ($balanceKobo < $largestPrize) {
            return 'halt';
        }
        if ($balanceKobo < $expectedDaily) {
            return 'critical';
        }
        if ($balanceKobo < $expectedDaily * 3) {
            return 'warning';
        }

        return 'ok';
    }

    private function alertIfNeeded(FloatSnapshot $snapshot): void
    {
        match ($snapshot->alertState) {
            'warning' => Log::warning('Float below 3x expected daily payout — notify Finance.', ['balanceKobo' => $snapshot->opayBalanceKobo]),
            'critical' => Log::critical('Float below 1x expected daily payout — page Finance and Ops; initiate top-up.', ['balanceKobo' => $snapshot->opayBalanceKobo]),
            'halt' => Log::emergency('Float below largest theoretical prize — automatic disbursement suspended, page executives.', ['balanceKobo' => $snapshot->opayBalanceKobo]),
            default => null,
        };
    }
}
