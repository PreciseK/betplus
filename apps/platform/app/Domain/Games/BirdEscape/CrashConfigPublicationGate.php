<?php

declare(strict_types=1);

namespace App\Domain\Games\BirdEscape;

use App\Domain\Games\Economics\RtpCeiling;
use App\Models\CrashConfig;

/**
 * BirdEscape's analogue of PrizeTablePublicationGate — the structural half of the
 * publication gate for a crash game's single house-edge parameter (no per-tier
 * probability check applies here; the crash-point distribution's expected RTP is a
 * direct function of houseEdgeBasisPoints by construction of BirdEscapeEngine's
 * formula, not something separately measured per config).
 *
 * Not built here, deliberately, for the same reasons as PrizeTablePublicationGate:
 * the maker-checker workflow itself, a Monte Carlo run against modelled frequencies,
 * and a real actuarial certification. What IS enforced: no config can be marked
 * published without passing the checks below.
 */
final class CrashConfigPublicationGate
{
    private const MIN_BETTING_WINDOW_SECONDS = 3;
    private const MAX_BETTING_WINDOW_SECONDS = 30;

    /** @return list<string> validation errors; empty means the config may publish */
    public function validate(CrashConfig $config): array
    {
        $errors = [];

        $rtpBasisPoints = 10_000 - $config->houseEdgeBasisPoints;
        if ($rtpBasisPoints > RtpCeiling::BASIS_POINTS) {
            $errors[] = "Modelled RTP {$rtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
        }
        if ($config->houseEdgeBasisPoints < 0 || $config->houseEdgeBasisPoints > 10_000) {
            $errors[] = "houseEdgeBasisPoints {$config->houseEdgeBasisPoints} is outside the valid 0-10000 range.";
        }

        if ($config->bettingWindowSeconds < self::MIN_BETTING_WINDOW_SECONDS || $config->bettingWindowSeconds > self::MAX_BETTING_WINDOW_SECONDS) {
            $errors[] = "bettingWindowSeconds {$config->bettingWindowSeconds} is outside the sane 3-30 second range.";
        }

        if ($config->growthRateConstant <= 0) {
            $errors[] = 'growthRateConstant must be a positive integer.';
        }

        if ($config->actuarialCertRef === null) {
            $errors[] = 'No actuarial certification reference recorded (REQ-GEC-025).';
        }

        return $errors;
    }
}
