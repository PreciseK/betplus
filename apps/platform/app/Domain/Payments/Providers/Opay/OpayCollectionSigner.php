<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers\Opay;

/**
 * HMAC-SHA512 signer for OPay's Collections Server-Side API endpoints (REQ-PAY-010).
 * Deliberately a separate class from OpayPayoutSigner (RSA-SHA256, 2048-bit key) —
 * the PRD explicitly warns these two schemes must not be confused.
 *
 * OPay documentation (https://documentation.opaycheckout.com/api-signature) specifies
 * standard hex-encoded HMAC-SHA512 of the exact raw JSON request body for outbound calls.
 *
 * For inbound payment callbacks (https://documentation.opaycheckout.com/callback-signature),
 * OPay signs a canonical payload string using HMAC-SHA3-512 with the merchant private key.
 */
final class OpayCollectionSigner
{
    public function __construct(private readonly string $merchantSecretKey)
    {
    }

    /**
     * Signs outbound API request body using hex-encoded HMAC-SHA512.
     *
     * @param string $jsonBody Exact bytes sent as the request body.
     */
    public function sign(string $jsonBody): string
    {
        return hash_hmac('sha512', $jsonBody, $this->merchantSecretKey);
    }

    /**
     * Verifies an outbound-style signature against raw JSON.
     */
    public function verify(string $jsonBody, string $signature): bool
    {
        return hash_equals($this->sign($jsonBody), $signature);
    }

    /**
     * Verifies an inbound callback's sha512 signature (REQ-PAY-014 / OPay Callback Doc).
     * Reconstructs the canonical template string and checks HMAC-SHA3-512.
     *
     * @param array<string, mixed> $payload The `payload` object inside the callback JSON.
     * @param string $signature The `sha512` field from the root callback JSON.
     */
    public function verifyCallback(array $payload, string $signature): bool
    {
        $amount = (string) ($payload['amount'] ?? '');
        $currency = (string) ($payload['currency'] ?? '');
        $reference = (string) ($payload['reference'] ?? '');
        $refunded = !empty($payload['refunded']) ? 't' : 'f';
        $status = (string) ($payload['status'] ?? '');
        $timestamp = (string) ($payload['timestamp'] ?? '');
        $token = (string) ($payload['token'] ?? '');
        $transactionId = (string) ($payload['transactionId'] ?? '');

        $canonicalString = sprintf(
            '{Amount:"%s",Currency:"%s",Reference:"%s",Refunded:%s,Status:"%s",Timestamp:"%s",Token:"%s",TransactionID:"%s"}',
            $amount,
            $currency,
            $reference,
            $refunded,
            $status,
            $timestamp,
            $token,
            $transactionId
        );

        $expected = hash_hmac('sha3-512', $canonicalString, $this->merchantSecretKey);

        return hash_equals($expected, $signature);
    }
}
