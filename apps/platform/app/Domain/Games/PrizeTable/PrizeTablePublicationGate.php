<?php

declare(strict_types=1);

namespace App\Domain\Games\PrizeTable;

use App\Domain\Games\Economics\RtpCeiling;
use App\Models\PrizeTable;

/**
 * §6.4 (REQ-GEC-022..025) — the structural half of the publication gate.
 *
 * REQ-GEC-022 ("tier probabilities must sum to exactly 1.0000") reads naturally for a
 * prize table whose tiers PARTITION one round's outcome space (e.g. "TIER_WIN_1 .. TIER_
 * WIN_5, TIER_LOSE" for one fixed draw). BlackRed's tiers aren't that: the player
 * CHOOSES the draw length (1-5 positions) up front, so each tier is an independently
 * selectable two-outcome game (win at 1/2^positions, lose otherwise) — win+lose already
 * sums to 1.0000 by construction for every tier individually. Summing probabilities
 * ACROSS tiers (what an earlier version of this gate did) is a category error and was
 * caught by the seeder actually failing on real numbers: 1/2+1/4+1/8+1/16+1/32 =
 * 31/32, not 1. What this gate checks instead, per tier, is that the win probability
 * recorded IS the fair-coin value for that many positions (REQ-BR-002/003) — the
 * thing that would actually be wrong if someone hand-edited a tier's odds — plus the
 * RTP ceiling, gross and net of withholding (REQ-GEC-023/024).
 *
 * NOT built here, deliberately: the actual maker-checker publication workflow
 * (REQ-BO-003/015..018, Epic 6 back office), the Monte Carlo run of >=10,000,000
 * tickets against modelled frequencies (REQ-QA-001/002), and recording a real
 * actuarial certification (REQ-GEC-025 requires a reference to exist, not that this
 * gate produce one). Those are compliance/ops processes, not something to fabricate
 * a passing result for. What IS enforced: no table can be marked published in this
 * codebase without passing the math checks below.
 */
final class PrizeTablePublicationGate
{
    /**
     * @return list<string> validation errors; empty means the table may publish
     */
    public function validate(PrizeTable $table, int $withholdingRateBasisPoints): array
    {
        $errors = [];
        $tiers = $table->tiers;

        if ($tiers->isEmpty()) {
            return ['Prize table has no tiers.'];
        }

        foreach ($tiers as $tier) {
            // REQ-BR-002/003 — each position is an independent, unbiased 50/50 draw, so
            // an N-position tier's win probability must be exactly 1/2^N.
            $expectedDenominator = 2 ** $tier->positions;
            if ($tier->probabilityNumerator !== 1 || $tier->probabilityDenominator !== $expectedDenominator) {
                $errors[] = "Tier {$tier->positions}: probability {$tier->probabilityNumerator}/{$tier->probabilityDenominator} is not the fair-coin value 1/{$expectedDenominator}.";
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
