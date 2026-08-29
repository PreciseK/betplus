<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Models\Player;

/**
 * §7.6 — withholding on winnings only (the GGR levy and corporate income tax layers of
 * REQ-TAX-001 are operator-cost accounting, not computed per-ticket, and are out of
 * scope here). REQ-TAX-010 (replayable, cites ruleset version) holds because this is a
 * pure function of (grossPrizeKobo, residencyStatus, config) — feed it the same
 * ruleset config in force at settlement and it reproduces the same deduction.
 */
final class TaxEngine
{
    public function withhold(int $grossPrizeKobo, Player $player): WithholdingResult
    {
        // REQ-TAX-004 — from the KYC-derived record, never inferred.
        $isResident = $player->residencyStatus === 'resident';
        $rateBasisPoints = $isResident
            ? (int) config('tax.withholding.resident_rate_basis_points')
            : (int) config('tax.withholding.non_resident_rate_basis_points');

        // REQ-TAX-003 — basis is a ruleset property. This build's configured basis is
        // 'gross_prize'; a state ruleset defining 'net_winnings' (gross less stake)
        // would need that distinct computation added here when such a state is
        // licensed — not fabricated ahead of a real ruleset saying so.
        $basis = (string) config('tax.withholding.basis');
        $taxableKobo = match ($basis) {
            'gross_prize' => $grossPrizeKobo,
            default => throw new \RuntimeException("Unsupported withholding basis: $basis"),
        };

        $taxWithheldKobo = intdiv($taxableKobo * $rateBasisPoints, 10_000);

        return new WithholdingResult(
            taxWithheldKobo: $taxWithheldKobo,
            netCreditKobo: $grossPrizeKobo - $taxWithheldKobo,
            rateBasisPoints: $rateBasisPoints,
            basisLabel: (string) config('tax.withholding.basis_label'),
            rulesetVersion: (string) config('tax.withholding.ruleset_version'),
        );
    }
}
