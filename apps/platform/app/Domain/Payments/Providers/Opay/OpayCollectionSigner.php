<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers\Opay;

/**
 * HMAC-SHA512 signer for OPay's collections-namespace endpoints (REQ-PAY-010). Deliberately
 * a separate class from OpayPayoutSigner (RSA-SHA256, a different key entirely) — the PRD
 * explicitly warns these two schemes must not be confused, and Story 2.5 requires a test
 * that signing with the wrong signer fails loudly rather than silently. It would fail loudly
 * here regardless: this class cannot produce an RSA signature and vice versa, by construction.
 */
final class OpayCollectionSigner
{
    public function __construct(private readonly string $merchantSecretKey)
    {
    }

    /** @param string $jsonBody Exact bytes sent as the request body. */
    public function sign(string $jsonBody): string
    {
        return base64_encode(hash_hmac('sha512', $jsonBody, $this->merchantSecretKey, true));
    }

    /**
     * Verifies an inbound callback's signature (REQ-PAY-014). Same HMAC-SHA512 scheme —
     * OPay signs callbacks with the same merchant secret it expects requests signed with.
     */
    public function verify(string $jsonBody, string $signature): bool
    {
        return hash_equals($this->sign($jsonBody), $signature);
    }
}
