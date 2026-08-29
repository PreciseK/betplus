<?php

declare(strict_types=1);

namespace BlackRed\Integrations;

/**
 * ANM Orchard request signature builder.
 *
 * ANM authentication scheme:
 *   1. Build the JSON body of the request
 *   2. Compute HMAC-SHA256 of the JSON body using the secret_key as the key
 *   3. Authorization header: "{client_key}:{signature}"
 *
 * Note: ANM uses the literal string "client_key" (NOT "client_id") in the
 * Authorization header. Their documentation is inconsistent on this point;
 * we follow what their working sample code does.
 *
 * This class is separated from AnmClient so we can unit-test the signing
 * logic without making HTTP calls.
 */
final class AnmSignatureBuilder
{
    public function __construct(
        private readonly string $clientKey,
        private readonly string $secretKey,
    ) {
        if ($clientKey === '' || $secretKey === '') {
            throw new \InvalidArgumentException('ANM credentials cannot be empty');
        }
    }

    /**
     * Sign a request body. Returns the ready-to-use Authorization header value.
     */
    public function authHeader(string $jsonBody): string
    {
        $signature = hash_hmac('sha256', $jsonBody, $this->secretKey);
        return $this->clientKey . ':' . $signature;
    }
}
