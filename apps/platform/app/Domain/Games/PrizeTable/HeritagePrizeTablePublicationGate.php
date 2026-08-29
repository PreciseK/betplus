<?php

declare(strict_types=1);

namespace App\Domain\Games\PrizeTable;

use App\Models\PrizeTable;

/**
 * Heritage's counterpart to PrizeTablePublicationGate — same publication-time
 * math checks (REQ-GEC-022..025), against heritageTiers instead of the
 * position-keyed BlackRed shape. The canonical tier-name set and the "0 matches is
 * structurally impossible" combinatorics live in the engine (apps/engine-heritage/
 * src/engine_heritage/tiers.py, board.py) — this only checks what a back-office
 * approver needs verified before publishing: the four required tiers are present
 * exactly once, probabilities sum to 10000bp, and the RTP ceiling holds.
 */
final class HeritagePrizeTablePublicationGate
{
    private const RTP_CEILING_BASIS_POINTS = 9_500; // 95% (REQ-GEC-023)
    private const REQUIRED_TIER_NAMES = ['TIER_JACKPOT', 'TIER_HIGH', 'TIER_SECOND_CHANCE', 'TIER_LOSS'];

    // REQ-HG-030's "sc_stake_ratio x player stake (default 0.10)" — must match
    // engine_heritage.engine.SECOND_CHANCE_STAKE_RATIO_BASIS_POINTS exactly, since
    // this gate approximates the engine's real cost before publication. Genuinely
    // configurable sc_stake_ratio (varying it without redeploying the engine) is not
    // built; both sides hardcode today's only value, the PRD's stated default.
    private const SECOND_CHANCE_STAKE_RATIO_BASIS_POINTS = 1_000;

    /** @return list<string> validation errors; empty means the table may publish */
    public function validate(PrizeTable $table, int $withholdingRateBasisPoints): array
    {
        $errors = [];
        $tiers = $table->heritageTiers;

        if ($tiers->isEmpty()) {
            return ['Prize table has no Heritage tiers.'];
        }

        $names = $tiers->pluck('tierName')->all();
        sort($names);
        $expected = self::REQUIRED_TIER_NAMES;
        sort($expected);
        if ($names !== $expected) {
            $errors[] = 'Prize table must contain exactly ' . implode(', ', self::REQUIRED_TIER_NAMES) . ', got ' . implode(', ', $names) . '.';
        }

        $totalBp = (int) $tiers->sum('probabilityBasisPoints');
        if ($totalBp !== 10_000) {
            $errors[] = "Tier probabilities must sum to exactly 10000 basis points, got {$totalBp}.";
        }

        $grossRtpBasisPoints = 0;
        foreach ($tiers as $tier) {
            if ($tier->outcomeType === 'cash') {
                $grossRtpBasisPoints += (int) round($tier->probabilityBasisPoints * $tier->multiplierHundredths / 100);
            } elseif ($tier->outcomeType === 'draw_entry') {
                // A second-chance entry is money that leaves the house on the
                // player's behalf (REQ-HG-030) — it's a real cost even though it's
                // never credited to the player's own wallet, so it counts toward the
                // RTP ceiling exactly like PRD §9.4's "cost per unit stake" column
                // does (its 0.0160 for TIER_SECOND_CHANCE is included in the 81.6%
                // modelled RTP total, not carved out).
                $grossRtpBasisPoints += (int) round($tier->probabilityBasisPoints * self::SECOND_CHANCE_STAKE_RATIO_BASIS_POINTS / 10_000);
            }
        }
        $netRtpBasisPoints = (int) round($grossRtpBasisPoints * (10_000 - $withholdingRateBasisPoints) / 10_000);

        if ($grossRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
            $errors[] = "Gross RTP {$grossRtpBasisPoints}bp exceeds the 9500bp ceiling.";
        }
        if ($netRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
            $errors[] = "Net-of-WHT RTP {$netRtpBasisPoints}bp exceeds the 9500bp ceiling.";
        }

        if ($table->actuarialCertRef === null) {
            $errors[] = 'No actuarial certification reference recorded (REQ-GEC-025).';
        }

        return $errors;
    }
}
