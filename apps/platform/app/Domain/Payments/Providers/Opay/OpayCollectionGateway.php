<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers\Opay;

use App\Domain\Identity\PhoneNumber;
use App\Models\OpayApiCallLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gateway client for OPay's Collections Server-Side APIs (REQ-PAY-001, REQ-PAY-016).
 *
 * Implements:
 * - Payment initiation: POST /api/v1/international/payment/create (BankAccount)
 * - In-session PIN submission: POST /api/v1/payment/action/input-pin (for USSD & Fast Web)
 * - SMS OTP submission: POST /api/v1/international/payment/input-otp
 * - Status query: POST /api/v1/international/cashier/status
 *
 * Uses HMAC-SHA512 request body signing via OpayCollectionSigner.
 * Strictly redacts raw PINs and OTPs from all persistent call logs.
 */
class OpayCollectionGateway
{
    private const PAYMENT_CREATE_PATH = '/api/v1/international/payment/create';
    private const PIN_ACTION_PATH = '/api/v1/payment/action/input-pin';
    private const OTP_ACTION_PATH = '/api/v1/international/payment/input-otp';
    private const STATUS_QUERY_PATH = '/api/v1/international/cashier/status';

    public function __construct(
        private readonly OpayCollectionSigner $signer,
        private readonly string $baseUrl,
        private readonly string $merchantId,
        private readonly string $callbackUrl,
    ) {
    }

    /**
     * Initiates a BankAccount debit against the player's OPay wallet.
     *
     * @return array{
     *     status: 'initiated'|'error',
     *     orderNo?: string,
     *     actionType?: string,
     *     providerStatus?: string,
     *     message?: string
     * }
     */
    public function createPayment(
        string $reference,
        int $amountKobo,
        string $phoneE164,
        string $customerName,
        ?string $callbackUrl = null,
    ): array {
        $body = [
            'reference' => $reference,
            'country' => 'NG',
            'payAmount' => [
                'currency' => 'NGN',
                'total' => $amountKobo,
            ],
            'payMethod' => 'BankAccount',
            'bankAccount' => [
                'bankAccountNumber' => PhoneNumber::toLocalDigits($phoneE164),
                'bankCode' => '033', // OPay institution code
                'customerName' => $customerName,
            ],
            'product' => [
                'name' => 'Play Balance Funding',
                'description' => 'Betplus Deposit',
            ],
            'callbackUrl' => $callbackUrl ?? $this->callbackUrl,
            'userPhone' => $phoneE164,
        ];

        $payload = $this->call(self::PAYMENT_CREATE_PATH, $body, $reference);

        if ($payload === null || !($payload['_successful'] ?? false)) {
            return [
                'status' => 'error',
                'message' => $payload['message'] ?? 'Could not initiate OPay payment request.',
            ];
        }

        $code = (string) ($payload['code'] ?? '');
        if ($code !== '00000') {
            return [
                'status' => 'error',
                'message' => $payload['message'] ?? "OPay rejected payment initiation (code {$code})",
            ];
        }

        $data = (array) ($payload['data'] ?? []);

        return [
            'status' => 'initiated',
            'orderNo' => (string) ($data['orderNo'] ?? ''),
            'actionType' => (string) ($data['nextAction']['actionType'] ?? 'NONE'),
            'providerStatus' => (string) ($data['status'] ?? 'PENDING'),
        ];
    }

    /**
     * Submits customer PIN to authorize payment in real-time (USSD / Fast In-App).
     *
     * @return array{
     *     status: 'paid'|'processing'|'failed'|'error',
     *     providerStatus?: string,
     *     orderNo?: string,
     *     reference?: string,
     *     message?: string
     * }
     */
    public function submitPin(string $orderNo, string $pin): array
    {
        $body = [
            'country' => 'NG',
            'orderNo' => $orderNo,
            'pin' => $pin,
        ];

        $payload = $this->call(self::PIN_ACTION_PATH, $body, $orderNo, ['pin' => '[REDACTED]']);

        if ($payload === null || !($payload['_successful'] ?? false)) {
            return [
                'status' => 'error',
                'message' => $payload['message'] ?? 'Unable to reach OPay for PIN verification.',
            ];
        }

        $code = (string) ($payload['code'] ?? '');
        if ($code !== '00000') {
            return [
                'status' => 'failed',
                'message' => $payload['message'] ?? 'PIN authorization failed.',
            ];
        }

        $data = (array) ($payload['data'] ?? []);
        $providerStatus = strtoupper((string) ($data['status'] ?? 'UNKNOWN'));

        return [
            'status' => $this->mapProviderStatus($providerStatus),
            'providerStatus' => $providerStatus,
            'orderNo' => (string) ($data['orderNo'] ?? $orderNo),
            'reference' => (string) ($data['reference'] ?? ''),
        ];
    }

    /**
     * Submits SMS OTP to authorize payment (Web / Browser fallback).
     *
     * @return array{
     *     status: 'paid'|'processing'|'failed'|'error',
     *     providerStatus?: string,
     *     orderNo?: string,
     *     reference?: string,
     *     message?: string
     * }
     */
    public function submitOtp(string $orderNo, string $otp): array
    {
        $body = [
            'country' => 'NG',
            'orderNo' => $orderNo,
            'otp' => $otp,
        ];

        $payload = $this->call(self::OTP_ACTION_PATH, $body, $orderNo, ['otp' => '[REDACTED]']);

        if ($payload === null || !($payload['_successful'] ?? false)) {
            return [
                'status' => 'error',
                'message' => $payload['message'] ?? 'Unable to reach OPay for OTP verification.',
            ];
        }

        $code = (string) ($payload['code'] ?? '');
        if ($code !== '00000') {
            return [
                'status' => 'failed',
                'message' => $payload['message'] ?? 'OTP authorization failed.',
            ];
        }

        $data = (array) ($payload['data'] ?? []);
        $providerStatus = strtoupper((string) ($data['status'] ?? 'UNKNOWN'));

        return [
            'status' => $this->mapProviderStatus($providerStatus),
            'providerStatus' => $providerStatus,
            'orderNo' => (string) ($data['orderNo'] ?? $orderNo),
            'reference' => (string) ($data['reference'] ?? ''),
        ];
    }

    /**
     * Queries payment status directly from OPay cashier status endpoint.
     *
     * @return array{
     *     status: 'paid'|'processing'|'failed'|'error',
     *     providerStatus?: string,
     *     orderNo?: string,
     *     amountKobo?: int,
     *     message?: string
     * }
     */
    public function queryStatus(string $reference): array
    {
        $body = [
            'country' => 'NG',
            'reference' => $reference,
        ];

        $payload = $this->call(self::STATUS_QUERY_PATH, $body, $reference);

        if ($payload === null || !($payload['_successful'] ?? false)) {
            return ['status' => 'error', 'message' => 'Status query unreachable.'];
        }

        $data = (array) ($payload['data'] ?? []);
        $providerStatus = strtoupper((string) ($data['status'] ?? 'UNKNOWN'));

        return [
            'status' => $this->mapProviderStatus($providerStatus),
            'providerStatus' => $providerStatus,
            'orderNo' => (string) ($data['orderNo'] ?? ''),
            'amountKobo' => isset($data['amount']['total']) ? (int) $data['amount']['total'] : null,
        ];
    }

    /**
     * Maps OPay provider status to internal status representation.
     */
    private function mapProviderStatus(string $providerStatus): string
    {
        return match ($providerStatus) {
            'SUCCESS', 'SUCCESSFUL' => 'paid',
            'FAIL', 'FAILED', 'CLOSE' => 'failed',
            default => 'processing',
        };
    }

    /**
     * Executes HTTP POST call with HMAC-SHA512 signature header and logs the request.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $redactedFields Fields to mask in OpayApiCallLog.
     * @return array<string, mixed>|null
     */
    private function call(
        string $path,
        array $body,
        ?string $reference = null,
        array $redactedFields = [],
    ): ?array {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = $this->signer->sign($json);
        $startedAt = microtime(true);

        $loggedBody = array_merge($body, $redactedFields);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $signature,
                'MerchantId' => $this->merchantId,
                'Content-Type' => 'application/json',
            ])->withBody($json, 'application/json')
                ->timeout(10)
                ->post($this->baseUrl . $path);
        } catch (ConnectionException) {
            $this->log($path, 'hmac_sha512', $loggedBody, null, null, null, 'unreachable', $startedAt, $reference);
            return null;
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload = $response->json() ?? [];
        $code = (string) ($payload['code'] ?? '');

        $outcome = $response->successful() && $code === '00000' ? 'success' : 'failed';

        $this->log(
            $path,
            'hmac_sha512',
            $loggedBody,
            $response->status(),
            $code !== '' ? $code : null,
            $payload,
            $outcome,
            $startedAt,
            $reference,
            $latencyMs
        );

        $payload['_successful'] = $response->successful();

        return $payload;
    }

    /**
     * Immutably logs the API transaction in `opayApiCallLog`.
     *
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
