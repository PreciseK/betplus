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
    public const ABSOLUTE_MAX_MULTIPLIER_HUNDREDTHS = 3500; // 35.00x absolute ceiling
    public const DEFAULT_MAX_CAP_HUNDREDTHS = 2500; // 25.00x regular cap
    public const MAX_MULTIPLIER_HUNDREDTHS = 3500;

    /**
     * Resolves the crash point from the seed adhering to:
     * - 70% of rounds: 1.00x - 2.50x
     * - 15% of rounds: 2.51x - 3.50x
     * - 10% of rounds: 3.51x - 5.00x
     * - 5% of rounds: 5.01x - 35.00x
     *
     * Daily Tier Limits (24-hour window):
     * - Max 2 rounds per day can go past 25.00x (and never > 35.00x).
     * - Max 5 rounds per day can reach between 20.00x - 25.00x.
     * - Max 10 rounds per day can reach between 15.00x - 20.00x.
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

        // Base distribution
        if ($r < 0.70) {
            // 70% of rounds: 1.00x - 2.50x (100 - 250)
            $m = 100 + (int) floor(($r / 0.70) * 150);
        } elseif ($r < 0.85) {
            // 15% of rounds: 2.51x - 3.50x (251 - 350)
            $m = 251 + (int) floor((($r - 0.70) / 0.15) * 100);
        } elseif ($r < 0.95) {
            // 10% of rounds: 3.51x - 5.00x (351 - 500)
            $m = 351 + (int) floor((($r - 0.85) / 0.10) * 150);
        } else {
            // 5% of rounds: 5.01x - 35.00x (501 - 3500)
            $m = 501 + (int) floor((($r - 0.95) / 0.05) * 3000);
        }

        // Apply Daily Tier Quota Rules:
        // Rule 1: Only 2 rounds in 24h can go past 25.00x (and strictly capped at <= 35.00x)
        if ($m > 2500) {
            if ($dailyCountAbove25x < 2) {
                $m = min(self::ABSOLUTE_MAX_MULTIPLIER_HUNDREDTHS, $m);
            } else {
                // Quota reached: step down into 20x - 25x
                $m = 2000 + ($m % 501);
            }
        }

        // Rule 2: Only 5 rounds in 24h can reach between 20.00x - 25.00x
        if ($m >= 2000 && $m <= 2500) {
            if ($dailyCountBetween20xAnd25x >= 5) {
                // Quota reached: step down into 15x - 20x
                $m = 1500 + ($m % 500);
            }
        }

        // Rule 3: Only 10 rounds in 24h can reach between 15.00x - 20.00x
        if ($m >= 1500 && $m < 2000) {
            if ($dailyCountBetween15xAnd20x >= 10) {
                // Quota reached: step down into sub-15x (5.01x - 14.99x)
                $m = 501 + ($m % 999);
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
