<?php

declare(strict_types=1);

namespace Betplus\Ussd;

use Betplus\Ussd\Http\HttpClient;

/**
 * The only route to money and games from USSD: the platform's /v1 HTTP API, plus
 * /internal/ussd/* for the identity bootstrap OTP substitutes and the session audit
 * mirror (see App\Http\Controllers\Internal\UssdGatewayController's doc comment on
 * the platform side). This class holds no game logic, no ledger writes and no
 * database access — see project-context.md rule 19-20.
 */
final class PlatformClient implements PlatformClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $gatewaySharedSecret,
        private readonly HttpClient $http = new HttpClient(10),
    ) {
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    // ── Identity (REQ-ID-004 — gateway-verified, no OTP) ────────────────────────

    /** @return array<string, mixed> */
    public function identify(string $msisdn): array
    {
        return $this->postSigned('/internal/ussd/identify', ['msisdn' => $msisdn]);
    }

    /** @return array<string, mixed> */
    public function completeRegistration(string $msisdn): array
    {
        return $this->postSigned('/internal/ussd/register/complete', ['msisdn' => $msisdn]);
    }

    /** REQ-USSD-003's audit mirror — apps/ussd has no database access of its own. */
    public function syncSession(string $sessionId, string $msisdn, string $screen, string $inputText, string $status): void
    {
        $this->postSigned('/internal/ussd/session', [
            'session_id' => $sessionId, 'msisdn' => $msisdn, 'screen' => $screen,
            'input_text' => $inputText, 'status' => $status,
        ]);
    }

    // ── Player-token /v1 calls — identical surface web/app use ──────────────────

    /** @return array<string, mixed> */
    public function wallet(string $token): array
    {
        return $this->getAuthed('/v1/wallet', $token);
    }

    /** @return array<string, mixed> */
    public function collectFromOpay(string $token, int $amountKobo, string $reference): array
    {
        return $this->postAuthed('/v1/wallet/deposits', $token, [
            'quote_id' => (string) $amountKobo, 'reference' => $reference,
        ]);
    }

    /** @return array<string, mixed> */
    public function blackRedDescriptor(string $token): array
    {
        return $this->getAuthed('/v1/games/blackred', $token);
    }

    /**
     * @param list<string> $prediction
     * @return array<string, mixed>
     */
    public function purchaseBlackRedTicket(string $token, array $prediction, int $stakeKobo, string $idempotencyKey): array
    {
        return $this->postAuthed('/v1/tickets', $token, [
            'prediction' => $prediction, 'stake_kobo' => $stakeKobo, 'idempotency_key' => $idempotencyKey,
        ]);
    }

    /** @return array<string, mixed> */
    public function revealBlackRedTicket(string $token, string $reference): array
    {
        return $this->getAuthed("/v1/tickets/$reference/reveal", $token);
    }

    /** @return array<string, mixed> */
    public function heritageDescriptor(string $token): array
    {
        return $this->getAuthed('/v1/games/heritage', $token);
    }

    /**
     * @param list<int> $selectedPositions
     * @return array<string, mixed>
     */
    public function purchaseHeritageTicket(string $token, array $selectedPositions, int $stakeKobo, string $idempotencyKey): array
    {
        return $this->postAuthed('/v1/heritage/tickets', $token, [
            'selected_positions' => $selectedPositions, 'stake_kobo' => $stakeKobo, 'idempotency_key' => $idempotencyKey,
        ]);
    }

    /** @return array<string, mixed> */
    public function revealHeritageTicket(string $token, string $reference): array
    {
        return $this->getAuthed("/v1/heritage/tickets/$reference/reveal", $token);
    }

    /** @return array<string, mixed> */
    public function cagedDescriptor(string $token): array
    {
        return $this->getAuthed('/v1/games/caged', $token);
    }

    /**
     * @return array<string, mixed>
     */
    public function purchaseCagedTicket(string $token, int $targetBirds, int $stakeKobo, string $idempotencyKey): array
    {
        return $this->postAuthed('/v1/caged/tickets', $token, [
            'target_birds' => $targetBirds, 'stake_kobo' => $stakeKobo, 'idempotency_key' => $idempotencyKey,
        ]);
    }

    /** @return array<string, mixed> */
    public function revealCagedTicket(string $token, string $reference): array
    {
        return $this->getAuthed("/v1/caged/tickets/$reference/reveal", $token);
    }

    public function notifyTicketSms(string $token, string $reference): void
    {
        $this->postAuthed("/v1/tickets/$reference/notify-sms", $token, []);
    }

    /** @return array<string, mixed> */
    public function responsiblePlayStatus(string $token): array
    {
        return $this->getAuthed('/v1/responsible-play', $token);
    }

    /** @return array<string, mixed> */
    public function startCoolOff(string $token, string $optionId): array
    {
        return $this->postAuthed('/v1/responsible-play/cool-off', $token, ['option_id' => $optionId]);
    }

    /** @return array<string, mixed> */
    public function selfExclude(string $token, string $optionId): array
    {
        return $this->postAuthed('/v1/responsible-play/self-exclude', $token, ['option_id' => $optionId]);
    }

    /** @return array<string, mixed> */
    public function updateLimit(string $token, string $key, int $value): array
    {
        return $this->postAuthed('/v1/responsible-play/limits', $token, ['key' => $key, 'value' => $value]);
    }

    // ── transport ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postSigned(string $path, array $body): array
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, $this->gatewaySharedSecret);

        return $this->http->postJson($this->baseUrl . $path, $body, ['X-Ussd-Signature' => $signature])['json'];
    }

    /** @return array<string, mixed> */
    private function getAuthed(string $path, string $token): array
    {
        return $this->http->getJson($this->baseUrl . $path, ['Authorization' => "Bearer $token"])['json'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postAuthed(string $path, string $token, array $body): array
    {
        return $this->http->postJson($this->baseUrl . $path, $body, ['Authorization' => "Bearer $token"])['json'];
    }
}
