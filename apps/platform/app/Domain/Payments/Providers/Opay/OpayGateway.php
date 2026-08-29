<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers\Opay;

use App\Domain\Identity\PhoneNumber;
use App\Models\OpayApiCallLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Wraps OPay's payout- and payment-namespace endpoints (REQ-PAY-016 — OPay is the only
 * provider implementation; nothing outside this class should know OPay's request shape).
 * Endpoint paths are from Betplus_PRD.md §12.6. Collection request/response field names
 * beyond what REQ-PAY-002..004 specify (payMethod, bankAccountNumber, bankCode, bvn,
 * dob, customerName) are a best-effort construction, not confirmed against a real OPay
 * Collections API doc (none exists in this repo, unlike the Payout one) — verify against
 * sandbox before this goes near real money.
 */
final class OpayGateway
{
    private const NAME_LOOKUP_PATH = '/api/v1/international/payout/opay-wallet-validate';
    private const COLLECTION_CREATE_PATH = '/api/v1/international/payment/create';
    private const COLLECTION_OTP_PATH = '/api/v1/international/payment/input-otp';
    private const COLLECTION_STATUS_PATH = '/api/v1/international/payment/status';
    private const PAYOUT_CREATE_PATH = '/api/v1/international/payout/createSingleOrder';
    private const PAYOUT_STATUS_PATH = '/api/v1/international/payout/queryorder';
    private const PAYOUT_BALANCE_PATH = '/api/v1/international/payout/balance';

    public function __construct(
        private readonly OpayPayoutSigner $payoutSigner,
        private readonly OpayCollectionSigner $collectionSigner,
        private readonly string $baseUrl,
        private readonly string $merchantId,
    ) {
    }

    /** @return array{status: 'found', firstName: string, lastName: string}|array{status: 'no_wallet'|'error'} */
    public function nameLookup(string $e164Phone): array
    {
        $body = ['phone' => PhoneNumber::toLocalDigits($e164Phone)];
        $payload = $this->call(self::NAME_LOOKUP_PATH, $body, $this->payoutSigner, 'rsa_sha256');

        if ($payload === null) {
            return ['status' => 'error'];
        }

        $data = $payload['data'] ?? null;
        $found = $payload['_successful'] && $payload['code'] === '00000' && !empty($data['firstName']) && !empty($data['lastName']);

        if (!$payload['_successful']) {
            return ['status' => 'error'];
        }

        return $found
            ? ['status' => 'found', 'firstName' => $data['firstName'], 'lastName' => $data['lastName']]
            : ['status' => 'no_wallet'];
    }

    /**
     * @return array{status: 'otp_required', providerCollectionId: ?string}|array{status: 'error'}
     */
    public function createCollection(
        string $reference,
        string $phoneE164,
        int $amountKobo,
        string $bankCode,
        string $bvn,
        string $dateOfBirth,
        string $customerName,
    ): array {
        $dob = CarbonImmutable::parse($dateOfBirth);
        $body = [
            'reference' => $reference,
            'country' => 'NG',
            'payAmount' => ['currency' => 'NGN', 'total' => $amountKobo],
            'payMethod' => 'BankAccount',
            'bankAccount' => [
                'bankAccountNumber' => PhoneNumber::toLocalDigits($phoneE164),
                'bankCode' => $bankCode,
                'bvn' => $bvn,
                'dobDay' => $dob->format('d'),
                'dobMonth' => $dob->format('m'),
                'dobYear' => $dob->format('Y'),
                'customerName' => $customerName,
            ],
        ];

        $payload = $this->call(self::COLLECTION_CREATE_PATH, $body, $this->collectionSigner, 'hmac_sha512', $reference);
        if ($payload === null || !$payload['_successful']) {
            return ['status' => 'error'];
        }

        return ['status' => 'otp_required', 'providerCollectionId' => $payload['data']['orderNo'] ?? null];
    }

    /** @return array{status: 'paid'|'processing'|'failed'|'error'} */
    public function submitCollectionOtp(string $reference, string $otp): array
    {
        $payload = $this->call(
            self::COLLECTION_OTP_PATH,
            ['reference' => $reference, 'otp' => $otp],
            $this->collectionSigner,
            'hmac_sha512',
            $reference,
        );

        if ($payload === null || !$payload['_successful']) {
            return ['status' => 'error'];
        }

        return ['status' => $this->mapCollectionStatus($payload['data']['status'] ?? null)];
    }

    /** @return array{status: 'paid'|'processing'|'failed'|'error'} */
    public function queryCollectionStatus(string $reference): array
    {
        $payload = $this->call(self::COLLECTION_STATUS_PATH, ['reference' => $reference], $this->collectionSigner, 'hmac_sha512', $reference);

        if ($payload === null || !$payload['_successful']) {
            return ['status' => 'error'];
        }

        return ['status' => $this->mapCollectionStatus($payload['data']['status'] ?? null)];
    }

    /**
     * §12.6 / OPay Payout API Developer Guide §2.1. $merchantOrderNo must be digits only
     * per the guide's field description — the payout's own ULID reference isn't
     * digit-only, so DispatchPayout derives a numeric order number from the payout's
     * row id rather than reusing it directly.
     *
     * @return array{status: 'accepted', opayOrderNo: ?string, providerStatus: string}|array{status: 'error'}
     */
    public function createPayout(
        string $merchantOrderNo,
        int $amountKobo,
        string $customerName,
        string $phoneE164,
        string $notifyUrl,
    ): array {
        $body = [
            'payoutType' => 'OpayWalletNg',
            'notifyUrl' => $notifyUrl,
            'merchantOrderNo' => $merchantOrderNo,
            'country' => 'NG',
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'language' => 'en_US',
            'metaData' => ['customerName' => $customerName, 'phone' => $phoneE164],
        ];

        $payload = $this->call(self::PAYOUT_CREATE_PATH, $body, $this->payoutSigner, 'rsa_sha256', $merchantOrderNo);
        if ($payload === null || !$payload['_successful']) {
            return ['status' => 'error'];
        }

        return [
            'status' => 'accepted',
            'opayOrderNo' => $payload['data']['orderNo'] ?? null,
            'providerStatus' => $payload['data']['orderStatus'] ?? 'INITIAL',
        ];
    }

    /** @return array{status: 'ok', providerStatus: string}|array{status: 'error'} */
    public function queryPayoutStatus(string $merchantOrderNo): array
    {
        $payload = $this->call(self::PAYOUT_STATUS_PATH, ['reference' => $merchantOrderNo, 'country' => 'NG'], $this->payoutSigner, 'rsa_sha256', $merchantOrderNo);
        if ($payload === null || !$payload['_successful']) {
            return ['status' => 'error'];
        }

        return ['status' => 'ok', 'providerStatus' => $payload['data']['orderStatus'] ?? 'UNKNOWN'];
    }

    /** REQ-FLOAT-001 — polled for the OPAY_FLOAT reconciliation and dashboard headline. */
    public function floatBalanceKobo(): ?int
    {
        $payload = $this->call(self::PAYOUT_BALANCE_PATH, ['country' => 'NG', 'currency' => 'NGN', 'type' => 'CASH_ACCOUNT'], $this->payoutSigner, 'rsa_sha256');
        if ($payload === null || !$payload['_successful']) {
            return null;
        }

        return $payload['data']['balance']['total'] ?? null;
    }

    /** @return 'paid'|'processing'|'failed' */
    private function mapCollectionStatus(?string $providerStatus): string
    {
        // Field name and values unconfirmed (no Collections API doc) — SUCCESS/PENDING/
        // FAILED is a reasonable guess pending sandbox confirmation.
        return match ($providerStatus) {
            'SUCCESS' => 'paid',
            'FAILED', 'CLOSE' => 'failed',
            default => 'processing',
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null Null only on transport failure (unreachable).
     */
    private function call(
        string $path,
        array $body,
        OpayPayoutSigner|OpayCollectionSigner $signer,
        string $scheme,
        ?string $reference = null,
    ): ?array {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $signer->sign($json),
                'MerchantId' => $this->merchantId,
                'Content-Type' => 'application/json',
            ])->withBody($json, 'application/json')
                ->timeout(10)
                ->post($this->baseUrl . $path);
        } catch (ConnectionException) {
            $this->log($path, $scheme, $body, null, null, null, 'unreachable', $startedAt, $reference);
            return null;
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload = $response->json() ?? [];
        $code = $payload['code'] ?? null;

        $this->log($path, $scheme, $body, $response->status(), $code, $payload, $response->successful() ? 'success' : 'failed', $startedAt, $reference, $latencyMs);

        $payload['_successful'] = $response->successful();

        return $payload;
    }

    /**
     * @param array<string, mixed> $requestBody
     * @param array<string, mixed>|null $responseBody
     */
    private function log(
        string $endpoint,
        string $scheme,
        array $requestBody,
        ?int $httpStatus,
        ?string $opayCode,
        ?array $responseBody,
        string $outcome,
        float $startedAt,
        ?string $reference,
        ?int $latencyMs = null,
    ): void {
        OpayApiCallLog::create([
            'endpoint' => $endpoint,
            'signatureScheme' => $scheme,
            'reference' => $reference,
            'httpStatus' => $httpStatus,
            'opayCode' => $opayCode,
            'outcome' => $outcome,
            'latencyMs' => $latencyMs ?? (int) round((microtime(true) - $startedAt) * 1000),
            'requestBody' => $requestBody,
            'responseBody' => $responseBody,
        ]);
    }
}
