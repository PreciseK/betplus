<?php

declare(strict_types=1);

namespace App\Domain\Ussd;

/**
 * REQ-SEC-009 / REQ-ID-004 — "the adapter validates the gateway signature before
 * trusting [the MSISDN]." No aggregator is contracted yet (PRD's C-xx items are
 * unconfirmed, same situation as OpayGateway's Collections API — see that class's
 * doc comment), so there is no real vendor scheme to implement. This is a plain
 * shared-secret HMAC-SHA256 over the raw request body — the same technique the
 * OPay signers use, generalised — swap for whichever aggregator's actual scheme
 * once one is contracted; the verification call site (VerifyUssdGatewaySignature)
 * doesn't change.
 */
final class UssdGatewaySigner
{
    public function __construct(private readonly string $sharedSecret)
    {
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->sharedSecret);
    }

    public function verify(string $body, string $signature): bool
    {
        return hash_equals($this->sign($body), $signature);
    }
}
