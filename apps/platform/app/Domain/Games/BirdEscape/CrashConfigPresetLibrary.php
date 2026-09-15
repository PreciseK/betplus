<?php

declare(strict_types=1);

namespace App\Domain\Games\BirdEscape;

/**
 * Named starting points for a BirdEscape crash-config draft, same spirit as
 * PrizeTablePresetLibrary — pre-filled menu options for whoever drafts a config, not
 * the live config itself. Every preset still goes through the same draft ->
 * CrashConfigPublicationGate -> maker-checker -> publish pipeline as a hand-typed one.
 *
 * - "fair" is the zero-margin baseline (0bp house edge, 100% RTP). Exists for
 *   comparison; a real operator would never publish it (there'd be no house revenue).
 * - "good" was recalibrated 2026-09-14 (750bp -> 1500bp house edge, i.e. 92.50% ->
 *   85.00% RTP) to clear the tightened 8800bp RTP ceiling (RtpCeiling::BASIS_POINTS) —
 *   the old 750bp value produced 9250bp RTP, which this same gate now rejects, matching
 *   BirdEscapeGameSeeder's own already-recalibrated seeded value. Interim placeholder
 *   pending real Finance/actuarial redesign, same category as BlackRed's and Caged's
 *   recalibrations.
 * - "best" is a flat 30% house edge — highest house revenue ratio, still inside the
 *   88% ceiling. Same caution as BlackRed's "best": model it against real volume
 *   before publishing — a crash game with an aggressive edge burns through the
 *   climbing multiplier faster, which is a retention risk, not a math risk.
 */
final class CrashConfigPresetLibrary
{
    /** @var array<string, int> preset key => houseEdgeBasisPoints */
    private const PRESETS = [
        'fair' => 0,
        'good' => 1_500,
        'best' => 3_000,
    ];

    /** @return list<array{key:string, label:string, house_edge_basis_points:int, modelled_rtp_basis_points:int}> */
    public function forBirdEscape(): array
    {
        $labels = [
            'fair' => 'Fair (zero margin, reference only)',
            'good' => 'Good (conservative default)',
            'best' => 'Best (highest house revenue ratio)',
        ];

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => $labels[$key],
            'house_edge_basis_points' => self::PRESETS[$key],
            'modelled_rtp_basis_points' => 10_000 - self::PRESETS[$key],
        ], array_keys(self::PRESETS));
    }
}
