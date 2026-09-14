<?php

declare(strict_types=1);

namespace App\Domain\Games\PrizeTable;

use App\Domain\Games\Economics\RtpCeiling;
use App\Models\PrizeTable;

/**
 * Caged's counterpart to PrizeTablePublicationGate/HeritagePrizeTablePublicationGate.
 * Reuses the generic prizeTableTier table (positions column holds targetBirds,
 * 1-5) — same table BlackRed uses, scoped by this table's own prizeTableId.
 *
 * Unlike BlackRed's fair-coin check, Caged's tier probabilities are empirically
 * chosen game-design frequencies (docs/caged-ussd-complete-flows.md's "Escape
 * Count" table), not something derivable from a combinatorial formula — so this
 * gate checks each tier's configured probability against the documented
 * constant directly, plus the same RTP-ceiling and actuarial-cert checks every
 * other gate in this codebase enforces.
 *
 * NOT built here, deliberately, for the same reasons as the other gates: the
 * real maker-checker publication workflow, the Monte Carlo validation run, and
 * a real actuarial certification.
 */
final class CagedPrizeTablePublicationGate
{
    /** targetBirds => [numerator, denominator] — the documented cumulative "at least N birds" frequencies. */
    private const EXPECTED_PROBABILITIES = [
        1 => [7180, 10_000],
        2 => [4620, 10_000],
        3 => [2310, 10_000],
        4 => [1140, 10_000],
        5 => [480, 10_000],
    ];

    /** @return list<string> validation errors; empty means the table may publish */
    public function validate(PrizeTable $table, int $withholdingRateBasisPoints): array
    {
        $errors = [];
        $tiers = $table->tiers;

        if ($tiers->isEmpty()) {
            return ['Prize table has no tiers.'];
        }

        $seenTargets = [];
        foreach ($tiers as $tier) {
            $seenTargets[] = $tier->positions;

            $expected = self::EXPECTED_PROBABILITIES[$tier->positions] ?? null;
            if ($expected === null) {
                $errors[] = "Tier {$tier->positions}: not a valid Caged target (must be 1-5).";
                continue;
            }

            [$expectedNumerator, $expectedDenominator] = $expected;
            $actualFraction = $tier->probabilityNumerator / $tier->probabilityDenominator;
            $expectedFraction = $expectedNumerator / $expectedDenominator;
            if (abs($actualFraction - $expectedFraction) > 0.00005) {
                $errors[] = "Tier {$tier->positions}: probability {$tier->probabilityNumerator}/{$tier->probabilityDenominator} does not match the documented {$expectedNumerator}/{$expectedDenominator}.";
            }
        }

        foreach (array_keys(self::EXPECTED_PROBABILITIES) as $target) {
            if (!in_array($target, $seenTargets, true)) {
                $errors[] = "Missing tier for target $target birds.";
            }
        }

        foreach ($tiers as $tier) {
            $probability = $tier->probabilityNumerator / $tier->probabilityDenominator;
            $grossRtpBasisPoints = (int) round($probability * $tier->multiplierHundredths * 100);
            $netRtpBasisPoints = (int) round($grossRtpBasisPoints * (10_000 - $withholdingRateBasisPoints) / 10_000);

            if ($grossRtpBasisPoints > RtpCeiling::BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: gross RTP {$grossRtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
            }
            if ($netRtpBasisPoints > RtpCeiling::BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: net-of-WHT RTP {$netRtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
            }
        }

        if ($table->actuarialCertRef === null) {
            $errors[] = 'No actuarial certification reference recorded (REQ-GEC-025).';
        }

        return $errors;
    }
}
