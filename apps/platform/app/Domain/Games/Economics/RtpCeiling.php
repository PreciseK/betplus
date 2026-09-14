<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/**
 * The single, platform-wide RTP ceiling every publication gate enforces —
 * PrizeTablePublicationGate, HeritagePrizeTablePublicationGate,
 * CagedPrizeTablePublicationGate, CrashConfigPublicationGate. One shared
 * constant instead of four duplicated literals, so this number is changed in
 * exactly one place. 88%, tightened down from the platform's original 95%.
 */
final class RtpCeiling
{
    public const BASIS_POINTS = 8_800;
}
