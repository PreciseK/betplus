<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers\Opay;

use App\Domain\Identity\PhoneNumber;
use App\Models\OpayApiCallLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Wraps OPay's Payout API endpoints exclusively (REQ-PAY-016 — OPay is the only
 * provider implementation; nothing outside this class should know OPay's request
 * shape). Every endpoint here is documented in the OPay Payout API Developer Guide
 * (repo root) — there is no Collections API doc in this repo, and no Collections
 * endpoint is called from here: that document has no way to pull money from a
 * customer at all (every endpoint in it is merchant-to-customer). A prior build
 * called invented `/payment/*` endpoints for that; they weren't in any doc
 * available here and have been removed — see FundingService::collect()'s doc
 * comment for what replaced them.
 */
final class OpayGateway
{
    private const NAME_LOOKUP_PATH = '/api/v1/international/payout/opay-wallet-validate';
    private const PAYOUT_CREATE_PATH = '/api/v1/international/payout/createSingleOrder';
    private const PAYOUT_STATUS_PATH = '/api/v1/international/payout/queryorder';
    private const PAYOUT_BALANCE_PATH = '/api/v1/international/payout/balance';

    public function __construct(
        private readonly OpayPayoutSigner $payoutSigner,
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

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null Null only on transport failure (unreachable).
     */
    private function call(
        string $path,
        array $body,
        OpayPayoutSigner $signer,
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
