<?php

declare(strict_types=1);

// §7.6 Tax Engine. REQ-TAX-002 wants rates as configuration, effective-dated and
// per-jurisdiction, changed through back office maker-checker. The back office
// maker-checker workflow (REQ-BO-015..018) is Epic 6 and doesn't exist yet, so this
// is config-file configuration rather than a versioned DB table for now — still not a
// constant in application code, and swapping it for a DB-backed ruleset later doesn't
// change TaxEngine's interface.
return [
    'withholding' => [
        'ruleset_version' => env('WHT_RULESET_VERSION', 'LAG-WHT-2026.1'),
        'basis' => env('WHT_BASIS', 'gross_prize'), // 'gross_prize' | 'net_winnings' — per-state in the real ruleset
        'resident_rate_basis_points' => (int) env('WHT_RESIDENT_RATE_BP', 500),
        'non_resident_rate_basis_points' => (int) env('WHT_NON_RESIDENT_RATE_BP', 1500),
        'basis_label' => env('WHT_BASIS_LABEL', 'Gross prize'),
    ],
];
