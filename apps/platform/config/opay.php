<?php

declare(strict_types=1);

return [
    // Base & Common
    'base_url' => env('OPAY_BASE_URL', 'https://testapi.opaycheckout.com'),
    'merchant_id' => env('OPAY_MERCHANT_ID'),
    'public_key' => env('OPAY_PUBLIC_KEY'),

    // Collections (Server-Side APIs)
    'collection_base_url' => env('OPAY_COLLECTION_BASE_URL', env('OPAY_BASE_URL', 'https://testapi.opaycheckout.com')),
    'collection_merchant_id' => env('OPAY_COLLECTION_MERCHANT_ID', env('OPAY_MERCHANT_ID')),
    'collection_secret_key' => env('OPAY_COLLECTION_SECRET_KEY', env('OPAY_SECRET_KEY')),
    'collection_public_key' => env('OPAY_COLLECTION_PUBLIC_KEY', env('OPAY_PUBLIC_KEY')),
    'collection_callback_url' => env('OPAY_COLLECTION_CALLBACK_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/internal/opay/callback/collection'),

    // Payouts & Disbursements (RSA-SHA256)
    'payout_base_url' => env('OPAY_PAYOUT_BASE_URL', env('OPAY_BASE_URL', 'https://testapi.opaycheckout.com')),
    'payout_merchant_id' => env('OPAY_PAYOUT_MERCHANT_ID', env('OPAY_MERCHANT_ID')),
    'payout_private_key' => env('OPAY_PAYOUT_PRIVATE_KEY'), // PEM, PKCS8
    'payout_public_key' => env('OPAY_PAYOUT_PUBLIC_KEY'),
    'payout_callback_secret' => env('OPAY_PAYOUT_CALLBACK_SECRET'),
    'payout_notify_url' => env('OPAY_PAYOUT_NOTIFY_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/internal/opay/callback/payout'),

    // Security & Callback Allowlist (comma-separated CIDR/IPs)
    'callback_ip_allowlist' => env('OPAY_CALLBACK_IP_ALLOWLIST', ''),
];
