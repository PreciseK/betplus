<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Caged;

use InvalidArgumentException;
use RuntimeException;

/**
 * A pure function of (seed, targetBirds, stake, tiers): no database, no external
 * call, no filesystem write, no internally generated randomness — same purity
 * contract as BlackRedEngine (REQ-GEC-001/002/003), swept by the same
 * tests/Unit/EnginePurityTest.php. One fresh seed is issued per TICKET (see
 * CreateCagedTicket), matching BlackRedEngine's per-ticket-seed shape rather
 * than BirdEscapeEngine's per-round shape.
 */
final class CagedEngine
{
    public const VERSION = 'caged-1.0.0';

    /** @param list<CagedTier> $tiers exactly 5 tiers, targetBirds 1..5, no gaps */
    public function resolve(string $seedHex, int $targetBirds, int $stakeKobo, array $tiers): CagedEngineResult
    {
        if ($targetBirds < 1 || $targetBirds > 5) {
            throw new InvalidArgumentException('targetBirds must be between 1 and 5.');
        }

        $this->validateTiers($tiers);

        $tier = $this->tierFor($tiers, $targetBirds);

        $seed = hex2bin($seedHex);
        if ($seed === false) {
            throw new InvalidArgumentException('seedHex must be valid hex.');
        }

        $digest = hash_hmac('sha256', 'CAGED', $seed, true);
        $unpacked = unpack('N', substr($digest, 0, 4));
        $h = $unpacked[1]; // uint32, 0..4294967295

        // Normalized uniform draw [0.0, 1.0) — same normalization style as
        // BirdEscapeEngine::resolve().
        $r = ($h % 100_000) / 100_000.0;

        $escapedBirds = $this->escapedBirdsFor($r, $tiers);
        $won = $escapedBirds >= $targetBirds;
        $grossPrizeKobo = $won ? intdiv($stakeKobo * $tier->multiplierHundredths, 100) : 0;

        return new CagedEngineResult(
            escapedBirds: $escapedBirds,
            targetBirds: $targetBirds,
            won: $won,
            grossPrizeKobo: $grossPrizeKobo,
            digest: Digest::of($seedHex, $targetBirds, $escapedBirds),
            engineVersion: self::VERSION,
        );
    }

    /** Same inputs, byte-identical output — resolve() is already pure. */
    public function replay(string $seedHex, int $targetBirds, int $stakeKobo, array $tiers): CagedEngineResult
    {
        return $this->resolve($seedHex, $targetBirds, $stakeKobo, $tiers);
    }

    /** @param list<CagedTier> $tiers */
    private function tierFor(array $tiers, int $targetBirds): CagedTier
    {
        foreach ($tiers as $tier) {
            if ($tier->targetBirds === $targetBirds) {
                return $tier;
            }
        }

        throw new RuntimeException("No prize table tier configured for target $targetBirds birds.");
    }

    /**
     * Buckets the uniform draw into an escaped-bird count (0-5) using the
     * boundaries implied by the tiers' cumulative "at least N birds"
     * probabilities — see the design spec §3 for the derivation. cumulative[0]
     * (100%) and cumulative[6] (0%) are the fixed edges of the distribution;
     * cumulative[1..5] come from the tiers, so there is nothing configured
     * separately that could drift out of sync with them.
     *
     * @param list<CagedTier> $tiers
     */
    private function escapedBirdsFor(float $r, array $tiers): int
    {
        $cumulative = [0 => 1.0, 6 => 0.0];
        foreach ($tiers as $tier) {
            $cumulative[$tier->targetBirds] = $tier->probabilityNumerator / $tier->probabilityDenominator;
        }
        ksort($cumulative);

        for ($escapedBirds = 0; $escapedBirds <= 5; $escapedBirds++) {
            $lower = 1 - $cumulative[$escapedBirds];
            $upper = 1 - $cumulative[$escapedBirds + 1];
            if ($r >= $lower && $r < $upper) {
                return $escapedBirds;
            }
        }

        // Floating-point edge as $r approaches 1.0 — falls in the top bucket.
        return 5;
    }

    /** @return array{gameCode:string,engineVersion:string} */
    public function describe(): array
    {
        return [
            'gameCode' => 'CAGED',
            'engineVersion' => self::VERSION,
        ];
    }

    /** @param list<CagedTier> $tiers */
    private function validateTiers(array $tiers): void
    {
        $foundTargets = [];
        foreach ($tiers as $tier) {
            $foundTargets[$tier->targetBirds] = true;
        }

        // Check all required targets 1-5 are present
        for ($target = 1; $target <= 5; $target++) {
            if (!isset($foundTargets[$target])) {
                throw new InvalidArgumentException("Tiers must include all targets 1-5; missing target $target.");
            }
        }

        // Check exactly 5 entries (no duplicates or extras)
        if (count($tiers) !== 5) {
            throw new InvalidArgumentException('Tiers must contain exactly 5 entries, got ' . count($tiers) . '.');
        }
    }
}
