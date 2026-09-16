<?php

declare(strict_types=1);

// Deposits are credited off a wallet-ownership + house-float check, not a verified
// inbound payment — the OPay Payout API has no endpoint that confirms a specific
// customer's specific transfer landed (its one balance-related endpoint returns a
// single aggregate total, with no transaction list or sender info to attribute a
// balance change to any one deposit — see FundingService::collect()'s doc comment).
// Above this threshold, a deposit is held for back-office review instead of
// credited immediately, bounding how much can be advanced on trust alone in one
// request. This default makes the gate LOGIC exercisable, not a risk-modelled
// number — needs Finance/Compliance sign-off before production launch, same
// caveat as payout.manual_review_threshold_kobo.
return [
    'manual_review_threshold_kobo' => (int) env('FUNDING_MANUAL_REVIEW_THRESHOLD_KOBO', 5_000_000), // ₦50,000
];
