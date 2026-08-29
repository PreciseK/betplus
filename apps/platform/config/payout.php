<?php

declare(strict_types=1);

// §7.5. REQ-PO-006 default. Float thresholds are expressed as multiples of expected
// daily payout (REQ-FLOAT-003/004) — real values need Finance input on actual payout
// volume; these are placeholder defaults that make the threshold LOGIC exercisable,
// not a modelled forecast.
return [
    'manual_review_threshold_kobo' => (int) env('PAYOUT_MANUAL_REVIEW_THRESHOLD_KOBO', 100_000_000), // ₦1,000,000
    'expected_daily_payout_kobo' => (int) env('PAYOUT_EXPECTED_DAILY_KOBO', 500_000_000), // ₦5,000,000 — placeholder
    'largest_theoretical_prize_kobo' => (int) env('PAYOUT_LARGEST_PRIZE_KOBO', 52_000_000), // 2,000,000 max stake * 26.00x
];
