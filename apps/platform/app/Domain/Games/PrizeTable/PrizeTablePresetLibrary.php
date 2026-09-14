<?php

declare(strict_types=1);

namespace App\Domain\Games\PrizeTable;

/**
 * Named starting points for a BlackRed prize-table draft (REQ-GEC-020: prize tables are
 * configuration, not code — these are pre-filled menu options for whoever drafts one,
 * not the live table itself. Every preset still goes through the same draft ->
 * PrizeTablePublicationGate -> maker-checker -> publish pipeline as a hand-typed table).
 *
 * - "fair" is the zero-margin baseline (multiplier = 2^positions, gross RTP 100%). It
 *   exists for comparison and will always fail the 95% ceiling in
 *   PrizeTablePublicationGate — that failure is the point, not a bug.
 * - "good" is the approved Betplus design target (Betplus_PRD.md §8.4): margin rises
 *   with variance, 7.5%-18.7% house edge.
 *   Tiers 1-2 were recalibrated 2026-09-14 (was 92.50%/90.00% RTP, now 85.00%/85.00%)
 *   to clear the tightened 88% RTP ceiling (RtpCeiling::BASIS_POINTS) — an interim
 *   placeholder pending real Finance/actuarial redesign, matching BlackRedGameSeeder's
 *   own seeded values. Tiers 3-5 (87.5%, 84.375%, 81.25%) are unaffected.
 * - "best" is a flat 30% house edge across every tier — still inside the 95% ceiling,
 *   but roughly double "good"'s steepest tier. Model it against real volume before
 *   publishing: smaller headline payouts are a retention risk, not a math risk.
 */
final class PrizeTablePresetLibrary
{
    /** @var array<string, array<int, int>> preset key => [positions => multiplierHundredths] */
    private const PRESETS = [
        'good' => [1 => 170, 2 => 340, 3 => 700, 4 => 1350, 5 => 2600],
        'best' => [1 => 140, 2 => 280, 3 => 560, 4 => 1120, 5 => 2240],
    ];

    /** @return list<array{key: string, label: string, tiers: list<array{positions:int, multiplier_hundredths:int, probability_numerator:int, probability_denominator:int, gross_rtp_basis_points:int}>}> */
    public function forBlackRed(): array
    {
        $presets = [
            ['key' => 'fair', 'label' => 'Fair (zero margin, reference only)', 'multipliers' => $this->fairMultipliers()],
            ['key' => 'good', 'label' => 'Good (approved design target)', 'multipliers' => self::PRESETS['good']],
            ['key' => 'best', 'label' => 'Best (highest house revenue ratio)', 'multipliers' => self::PRESETS['best']],
        ];

        return array_map(fn (array $preset) => [
            'key' => $preset['key'],
            'label' => $preset['label'],
            'tiers' => $this->buildTiers($preset['multipliers']),
        ], $presets);
    }

    /** @return array<int, int> positions => multiplierHundredths at exactly 2^positions (zero margin) */
    private function fairMultipliers(): array
    {
        $multipliers = [];
        for ($positions = 1; $positions <= 5; $positions++) {
            $multipliers[$positions] = 100 * (2 ** $positions);
        }

        return $multipliers;
    }

    /**
     * @param array<int, int> $multipliers positions => multiplierHundredths
     * @return list<array{positions:int, multiplier_hundredths:int, probability_numerator:int, probability_denominator:int, gross_rtp_basis_points:int}>
     */
    private function buildTiers(array $multipliers): array
    {
        $tiers = [];
        foreach ($multipliers as $positions => $multiplierHundredths) {
            $denominator = 2 ** $positions;
            $tiers[] = [
                'positions' => $positions,
                'multiplier_hundredths' => $multiplierHundredths,
                'probability_numerator' => 1,
                'probability_denominator' => $denominator,
                // Same formula as PrizeTablePublicationGate::validate() so the preview
                // an admin sees before drafting matches what the gate will compute after.
                'gross_rtp_basis_points' => (int) round((1 / $denominator) * $multiplierHundredths * 100),
            ];
        }

        return $tiers;
    }
}
