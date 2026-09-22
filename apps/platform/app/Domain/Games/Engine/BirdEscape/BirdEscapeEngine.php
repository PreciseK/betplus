<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\BirdEscape;

use InvalidArgumentException;

/**
 * A pure function of (seed, round number, house edge): no database, no external call,
 * no filesystem write, no internally generated randomness — same purity contract as
 * BlackRedEngine (REQ-GEC-001/002/003), swept by the same tests/Unit/EnginePurityTest.php.
 *
 * One seed is issued per ROUND, not per bet — many players' bets share one round's
 * crash point, unlike BlackRed where the engine resolves once per ticket.
 */
final class BirdEscapeEngine
{
    public const VERSION = 'birdescape-1.0.0';

    /**
     * Cap closes a real overflow risk: an unlucky (1-in-2^32) draw would otherwise
     * produce an astronomically large multiplier, and stakeKobo * crashMultiplierHundredths
     * in the settlement math could silently overflow into float. With maxStakeKobo
     * realistically <= ~10,000,000 kobo, the largest possible product
     * (10,000,000 * 1,000,000) stays safely inside PHP_INT_MAX on a 64-bit build.
     */
    public const ABSOLUTE_MAX_MULTIPLIER_HUNDREDTHS = 1500; // 15.00x absolute ceiling
    public const DEFAULT_MAX_CAP_HUNDREDTHS = 1500; // 15.00x regular cap
    public const MAX_MULTIPLIER_HUNDREDTHS = 1500;

    /**
     * Worst-case liability a single bet could ever create for the house — stake paid
     * out at the absolute maximum crash multiplier, regardless of what this round
     * actually crashes at (which the bettor never sees before it happens). Used by
     * BalancedHybridCrashStrategy to project a round's aggregate exposure — real
     * disclosed math, not privileged information about the actual crash point.
     */
    public static function worstCaseLiabilityKobo(int $stakeKobo): int
    {
        return intdiv($stakeKobo * self::ABSOLUTE_MAX_MULTIPLIER_HUNDREDTHS, 100);
    }

    /**
     * Resolves the crash point from the seed adhering to:
     * - 40% of rounds: 1.00x - 1.20x (100 - 120 hundredths)
     * - 20% of rounds: 1.20x - 1.50x (121 - 150 hundredths)
     * - 20% of rounds: 1.51x - 2.50x (151 - 250 hundredths)
     * - 10% of rounds: 2.51x - 4.00x (251 - 400 hundredths)
     * - 7% of rounds:  4.00x - 5.00x (401 - 500 hundredths)
     * - 3% of rounds:  5.10x - 15.00x (501 - 1500 hundredths)
     *
     * Maximum multiplier is strictly 15.00x (1500 hundredths).
     */
    public function resolve(
        string $seedHex,
        int $roundNumber,
        int $houseEdgeBasisPoints,
        int $dailyCountAbove25x = 0,
        int $dailyCountBetween20xAnd25x = 0,
        int $dailyCountBetween15xAnd20x = 0
    ): CrashEngineResult {
        if ($houseEdgeBasisPoints < 0 || $houseEdgeBasisPoints > 10_000) {
            throw new InvalidArgumentException('houseEdgeBasisPoints must be between 0 and 10000.');
        }

        $seed = hex2bin($seedHex);
        if ($seed === false) {
            throw new InvalidArgumentException('seedHex must be valid hex.');
        }

        $digest = hash_hmac('sha256', "ROUND:$roundNumber", $seed, true);
        $unpacked = unpack('N', substr($digest, 0, 4));
        $h = $unpacked[1]; // uint32, 0..4294967295

        // Normalized uniform draw [0.0, 1.0)
        $r = ($h % 100_000) / 100_000.0;

        // Configured fall distribution
        if ($r < 0.40) {
            // 40% falls btw 1.00 - 1.20 (100 - 120 hundredths)
            $m = 100 + (int) floor(($r / 0.40) * 21);
            if ($m > 120) {
                $m = 120;
            }
        } elseif ($r < 0.60) {
            // 20% falls on 1.20 - 1.50 (121 - 150 hundredths)
            $m = 121 + (int) floor((($r - 0.40) / 0.20) * 30);
            if ($m > 150) {
                $m = 150;
            }
        } elseif ($r < 0.80) {
            // 20% falls on 1.51 - 2.50 (151 - 250 hundredths)
            $m = 151 + (int) floor((($r - 0.60) / 0.20) * 100);
            if ($m > 250) {
                $m = 250;
            }
        } elseif ($r < 0.90) {
            // 10% falls on 2.51 - 4.00 (251 - 400 hundredths)
            $m = 251 + (int) floor((($r - 0.80) / 0.10) * 150);
            if ($m > 400) {
                $m = 400;
            }
        } elseif ($r < 0.97) {
            // 7% falls btw 4.00 - 5.00 (401 - 500 hundredths)
            $m = 401 + (int) floor((($r - 0.90) / 0.07) * 100);
            if ($m > 500) {
                $m = 500;
            }
        } else {
            // 3% falls btw 5.10 - 15.00 (501 - 1500 hundredths)
            $m = 501 + (int) floor((($r - 0.97) / 0.03) * 1000);
            if ($m > 1500) {
                $m = 1500;
            }
        }

        $crashHundredths = max(100, min(self::ABSOLUTE_MAX_MULTIPLIER_HUNDREDTHS, $m));

        return new CrashEngineResult(
            crashMultiplierHundredths: $crashHundredths,
            digest: Digest::of($seedHex, $roundNumber, $crashHundredths),
            engineVersion: self::VERSION,
        );
    }

    /** Same inputs, byte-identical output — resolve() is already pure, so replay is not a separate implementation. */
    public function replay(
        string $seedHex,
        int $roundNumber,
        int $houseEdgeBasisPoints,
        int $dailyCountAbove25x = 0,
        int $dailyCountBetween20xAnd25x = 0,
        int $dailyCountBetween15xAnd20x = 0
    ): CrashEngineResult {
        return $this->resolve(
            $seedHex,
            $roundNumber,
            $houseEdgeBasisPoints,
            $dailyCountAbove25x,
            $dailyCountBetween20xAnd25x,
            $dailyCountBetween15xAnd20x
        );
    }

    public const DEFAULT_MS_PER_MULTIPLIER = 4000;

    /**
     * The public, non-secret growth curve mapping elapsed flight time to a multiplier.
     * Floor is 100 (1.00x) at t=0 — flight always starts at break-even, never 0.00x.
     * From there, constant/uniform rate: every full 1.00x step (1.00x->2.00x,
     * 2.00x->3.00x, ...) takes the same duration (growthRateConstant ms, default 4000ms).
     */
    public static function multiplierHundredthsAtElapsedMs(int $elapsedMs, int $growthRateConstant = self::DEFAULT_MS_PER_MULTIPLIER): int
    {
        if ($elapsedMs <= 0) {
            return 100;
        }

        $rate = max(1, $growthRateConstant);
        return 100 + intdiv($elapsedMs * 100, $rate);
    }

    /** @return array{gameCode:string,engineVersion:string} */
    public function describe(): array
    {
        return [
            'gameCode' => 'BIRDESCAPE',
            'engineVersion' => self::VERSION,
        ];
    }
}
