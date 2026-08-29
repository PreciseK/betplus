<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers\Opay;

use RuntimeException;

/**
 * RSA-SHA256 signer for OPay's payout-namespace endpoints (REQ-PAY-010, REQ-PAY-011).
 * Deliberately separate from any future collections signer (HMAC-SHA512, different key) —
 * the PRD explicitly warns these two schemes must not be confused.
 */
final class OpayPayoutSigner
{
    public function __construct(private readonly string $privateKeyPem)
    {
    }

    /** @param string $jsonBody Exact bytes sent as the request body. */
    public function sign(string $jsonBody): string
    {
        $key = openssl_pkey_get_private($this->privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('Invalid OPay payout private key.');
        }

        if (!openssl_sign($jsonBody, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign OPay payout request.');
        }

        return base64_encode($signature);
    }
}
