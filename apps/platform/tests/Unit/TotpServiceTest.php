<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\BackOffice\TotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 Appendix B publishes official test vectors using the raw ASCII key
 * "12345678901234567890" and 8-digit output. This build uses 6-digit codes (the
 * industry-standard authenticator app default) — the low-order 6 digits of an 8-digit
 * TOTP are the same value regardless of which digit count the code is truncated to
 * (both truncate the same underlying integer via a different modulus), so the last 6
 * digits of each published vector is exactly what this implementation must produce.
 */
final class TotpServiceTest extends TestCase
{
    private const RFC_KEY = '12345678901234567890';

    /** @return array<string, array{0:int,1:string}> [timestamp, expected8DigitCode] */
    public static function rfcVectors(): array
    {
        return [
            'T=59' => [59, '94287082'],
            'T=1111111109' => [1111111109, '07081804'],
            'T=1111111111' => [1111111111, '14050471'],
            'T=1234567890' => [1234567890, '89005924'],
            'T=2000000000' => [2000000000, '69279037'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_matches_rfc_6238_official_test_vectors(int $timestamp, string $expected8Digit): void
    {
        $service = new TotpService();
        $expected6Digit = substr($expected8Digit, -6);
        $secret = TotpService::base32Encode(self::RFC_KEY);

        $this->assertSame($expected6Digit, $service->currentCode($secret, $timestamp));
    }

    public function test_verify_accepts_the_current_code(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();
        $code = $service->currentCode($secret, 1_700_000_000);

        $this->assertTrue($service->verify($secret, $code, 1_700_000_000));
    }

    public function test_verify_accepts_one_step_of_clock_drift(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();
        $code = $service->currentCode($secret, 1_700_000_000);

        $this->assertTrue($service->verify($secret, $code, 1_700_000_000 + 30));
        $this->assertTrue($service->verify($secret, $code, 1_700_000_000 - 30));
    }

    public function test_verify_rejects_a_code_outside_the_drift_window(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();
        $code = $service->currentCode($secret, 1_700_000_000);

        $this->assertFalse($service->verify($secret, $code, 1_700_000_000 + 90));
    }

    public function test_verify_rejects_a_wrong_code(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();

        $this->assertFalse($service->verify($secret, '000000', 1_700_000_000));
    }

    public function test_base32_round_trips_arbitrary_binary(): void
    {
        $binary = random_bytes(20);
        $this->assertSame($binary, TotpService::base32Decode(TotpService::base32Encode($binary)));
    }
}
