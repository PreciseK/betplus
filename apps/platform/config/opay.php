<?php

declare(strict_types=1);

return [
    'base_url' => env('OPAY_BASE_URL', 'https://testapi.opaycheckout.com'),
    'merchant_id' => env('OPAY_MERCHANT_ID'),

    // Payouts (RSA-SHA256) and Collections (HMAC-SHA512) use separate key material,
    // separate signer classes (REQ-PAY-010).
    'payout_private_key' => env('OPAY_PAYOUT_PRIVATE_KEY'), // PEM, PKCS8
    'collection_secret_key' => env('OPAY_COLLECTION_SECRET_KEY'),
    // OPay's constant bank code for BankAccount collections (REQ-PAY-003) — not
    // confirmed against a real OPay Collections doc; fill in from the merchant
    // dashboard/onboarding pack before this goes near a real sandbox.
    'collection_bank_code' => env('OPAY_COLLECTION_BANK_CODE'),
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
