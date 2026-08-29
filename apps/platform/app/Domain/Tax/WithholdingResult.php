<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/** REQ-TAX-005/006 — everything the player-visible receipt line needs. */
final readonly class WithholdingResult
{
    public function __construct(
        public int $taxWithheldKobo,
        public int $netCreditKobo,
        public int $rateBasisPoints,
        public string $basisLabel,
        public string $rulesetVersion,
    ) {
    }
}
