<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers\Opay;

/**
 * Verifies the payout order-notification callback (OPay Payout API Developer Guide
 * §2.3.3): body shape is {"payload": {...}, "sha512": "...", "type": "..."}, a
 * genuinely different envelope from the collections callback this codebase already
 * verifies (Authorization: Bearer header over the whole body). The guide names the
 * "sha512" field but the pages available in this repo don't fully specify the
 * canonicalization or encoding — HMAC-SHA512 over the JSON-encoded payload object,
 * base64-encoded, mirroring OpayCollectionSigner's scheme, is this build's best-effort
 * construction. Confirm against a real OPay sandbox payout callback before this goes
 * near production, same caveat as OpayGateway's collection field shapes.
 */
final class OpayPayoutCallbackVerifier
{
    public function __construct(private readonly string $callbackSecret)
    {
    }

    /** @param array<string, mixed> $payload */
    public function verify(array $payload, string $sha512): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expected = base64_encode(hash_hmac('sha512', $json, $this->callbackSecret, true));

        return hash_equals($expected, $sha512);
    }
}
