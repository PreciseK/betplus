<?php

declare(strict_types=1);

return [
    'base_url' => env('OPAY_BASE_URL', 'https://testapi.opaycheckout.com'),
    'merchant_id' => env('OPAY_MERCHANT_ID'),

    'payout_private_key' => env('OPAY_PAYOUT_PRIVATE_KEY'), // PEM, PKCS8
    // Comma-separated. Empty = no IP restriction (dev only — production must set this
    // from OPay's published callback source ranges before going live, REQ-PAY-014).
    'callback_ip_allowlist' => env('OPAY_CALLBACK_IP_ALLOWLIST', ''),

    // Payout callback verification key (§2.3.3's "sha512" field) — the developer guide
    // names the field but doesn't fully specify the algorithm in the parts this repo
    // has (see OpayPayoutCallbackVerifier's doc comment). Kept separate from
    // payout_private_key (that's for OUR outgoing RSA-SHA256 signatures).
    'payout_callback_secret' => env('OPAY_PAYOUT_CALLBACK_SECRET'),
    'payout_notify_url' => env('OPAY_PAYOUT_NOTIFY_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/internal/opay/callback/payout'),
];
