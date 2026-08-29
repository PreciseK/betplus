<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Payments\Providers\Opay\OpayCollectionSigner;
use App\Domain\Payments\Providers\Opay\OpayPayoutSigner;
use PHPUnit\Framework\TestCase;

/**
 * Story 2.5 (REQ-PAY-010, REQ-QA-015) — collections (HMAC-SHA512) and payouts
 * (RSA-SHA256) must not be interchangeable. They're separate classes with
 * incompatible constructors by construction (a TypeError, not a silent wrong-signature
 * bug, if one is used where the other is expected) — this proves the two algorithms
 * genuinely diverge, not just that the classes are named differently.
 */
class OpaySignerSeparationTest extends TestCase
{
    private const TEST_RSA_KEY_PEM = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDU3Q79BRHGVwj1
    lbo9nrFPsvydFlVME8cgRCy0BG0Hpw4EPOEpWFlFItdvEoo/sEwiVm/epi4AEud/
    h5n2iTnM/Z46ZFqESgm4Q+g3UENkAWta2hUWNKHlTPukjWP6L0nkFGGZpnKrfkIQ
    BgMEpUSyQEyUb6Tk3nEPTS2zilrMFtU0d/lJVAzXGkFLtymNkIZwUS60QQkYbeNr
    FVJbwI3z0K0miUPdTm5nYu0NtJd60pE2VzoNUxvZ3X4c4RtFSCdyx0xyduuKDuQk
    R/qHEuO9j07FFqVyR1F4juZmwLxl6DjGkLI56jte/NOckQxief3Kf8NrTmC0fh4j
    /gIWwqI9AgMBAAECggEAHJBKW9kDjNguh1fvbSfflriHreOqkAIiaRnE3uYuJEX+
    O0LZGwV0OzME8i5sd0XmvX/YVKn7h76BqosNdbft1exdgGvpepF90uhn345JcMDB
    AWi8xiVLaTvmk6r2dMLGOVEj1KyxfAI+Bqzr2EJ+IKZAsHV3zM9tn/ZFEO/apcKW
    6fNiq51o18DKPRUYa5smPBOp5JLzrx/wFfr+cKXX/f381Y0vJTC5GfLWYnjsZO3V
    VCjDzhi0FhiiPGy0wwjK+tLHbt4eL6TPEUMcME7TKX595vnEU39ESnbqVg462xN7
    aWXhVLalKPGYDwEeAgaBXk9ZK4mmQql03T168/fSmQKBgQDyJISIoYUuVaRgfdNq
    LMi0TrONVBFjuwCpN2+Qm9jDKPjprZi7ThuunLkglxxidSNUw5g/IlB+9kkFT09r
    YPgc/uY5rV1ihC7HTf2UdcRgb9EJb9P3t5TX4avOQxtNl54J+anE4cgyA3KOVvun
    OzWb0wi/mHqfuwsF7f/IEY/MBQKBgQDhC5cdSN75uABW+s+jyl/yWXOYtWcV6LMX
    otZ7XW7OpZyf4IqR9yzrMIcpqC5/j/5koJ7bvsybzZ4Nzsk+xzkUeGmtwu/VbcxM
    FJaFyQa+RWfyxzpqzEFJg9BnfInrVEXf+WU2wby5HulK6LQGlA7NOkIz7HpvlM2P
    aQyUWZSK2QKBgDErcS49fknWYjal1lRtG6RhhtxgAdf6lTvHYgQ/YVjf7QumkKkY
    R07BzGXtyXnEx5Pi0/ueADKH2HQXksz/N+LLb/yuU5Q5uzYFhEStVV8v1YbRCn32
    7WaZEMYlolmzPAhShkLQhlKBmLWGvDtNLqmhxNkDIYNl++sMVTBPQJ/xAoGADrbQ
    SZTjJ1a1hvpdKytnPJRGr5xkwhT16Ly341cHkLFZXUa0KLkNkc8Zd0rMx4BltLSf
    zmRaQnGePO7hT559B+6bkkXlooHMUskh0luDeltVYZVPJ351YlYhATMuXVmkO/G1
    gXAHY982h7RRWQDDOv3tKDH1C2iiTBclQGnfAXkCgYBp6rRXMhdaA/EwiF0EHvfQ
    roww+6AM1m8ysaot4ujMDOUHBdP7mI2uAVpPx4FbZBHPaCzqRzaPdp0uP9+E5eBM
    bT2M4K3XNGl/027YXA1G616PBWVyA+Kat2uYt+9nzsqS9zLEg9KfUY/gknzN89YV
    /y+2JPqGSeGn0LPGFFk79g==
    -----END PRIVATE KEY-----
    PEM;

    public function test_the_two_signers_produce_different_signatures_for_identical_input(): void
    {
        $body = '{"amount":10000,"reference":"BP-TEST-1"}';

        $collectionSig = (new OpayCollectionSigner('shared-secret-material'))->sign($body);
        $payoutSig = (new OpayPayoutSigner(self::TEST_RSA_KEY_PEM))->sign($body);

        $this->assertNotSame($collectionSig, $payoutSig);
    }

    public function test_collection_signature_is_genuinely_hmac_sha512(): void
    {
        $body = '{"amount":10000}';
        $secret = 'shared-secret-material';

        $signature = (new OpayCollectionSigner($secret))->sign($body);

        $this->assertSame(base64_encode(hash_hmac('sha512', $body, $secret, true)), $signature);
        // HMAC-SHA512 raw output is 64 bytes -> 88 chars base64 (with padding). An
        // RSA-SHA256 2048-bit signature is 256 bytes -> 344 chars — structurally
        // distinct lengths, not just different values, so the two cannot be silently
        // swapped and coincidentally verify.
        $this->assertSame(88, strlen($signature));
    }

    public function test_payout_signature_is_genuinely_rsa_sha256_and_verifiable_with_the_public_key(): void
    {
        $body = '{"amount":10000}';
        $signature = (new OpayPayoutSigner(self::TEST_RSA_KEY_PEM))->sign($body);

        $privateKey = openssl_pkey_get_private(self::TEST_RSA_KEY_PEM);
        $details = openssl_pkey_get_details($privateKey);
        $publicKey = openssl_pkey_get_public($details['key']);

        $this->assertSame(
            1,
            openssl_verify($body, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256),
        );
    }
}
