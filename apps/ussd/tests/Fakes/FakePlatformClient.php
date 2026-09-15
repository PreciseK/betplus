<?php

declare(strict_types=1);

namespace Betplus\Ussd\Tests\Fakes;

use Betplus\Ussd\PlatformClientInterface;

/** Records every call and returns pre-programmed responses — no HTTP, no platform. */
final class FakePlatformClient implements PlatformClientInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $responses = [];

    /** @var list<array{method:string, args:array<int, mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $response */
    public function programResponse(string $method, array $response): void
    {
        $this->responses[$method] = $response;
    }

    /** @return array<string, mixed> */
    private function respond(string $method, array $args): array
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        return $this->responses[$method] ?? [];
    }

    public function identify(string $msisdn): array
    {
        return $this->respond(__FUNCTION__, [$msisdn]);
    }

    public function completeRegistration(string $msisdn): array
    {
        return $this->respond(__FUNCTION__, [$msisdn]);
    }

    public function syncSession(string $sessionId, string $msisdn, string $screen, string $inputText, string $status): void
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$sessionId, $msisdn, $screen, $inputText, $status]];
    }

    public function wallet(string $token): array
    {
        return $this->respond(__FUNCTION__, [$token]);
    }

    public function directWithdrawFromOpay(string $token, int $amountKobo, string $idempotencyKey): array
    {
        return $this->respond(__FUNCTION__, [$token, $amountKobo, $idempotencyKey]);
    }

    public function verifyNin(string $token, string $dateOfBirth, string $nin): array
    {
        return $this->respond(__FUNCTION__, [$token, $dateOfBirth, $nin]);
    }

    public function blackRedDescriptor(string $token): array
    {
        return $this->respond(__FUNCTION__, [$token]);
    }

    public function purchaseBlackRedTicket(string $token, array $prediction, int $stakeKobo, string $idempotencyKey): array
    {
        return $this->respond(__FUNCTION__, [$token, $prediction, $stakeKobo, $idempotencyKey]);
    }

    public function revealBlackRedTicket(string $token, string $reference): array
    {
        return $this->respond(__FUNCTION__, [$token, $reference]);
    }

    public function heritageDescriptor(string $token): array
    {
        return $this->respond(__FUNCTION__, [$token]);
    }

    public function purchaseHeritageTicket(string $token, array $selectedPositions, int $stakeKobo, string $idempotencyKey): array
    {
        return $this->respond(__FUNCTION__, [$token, $selectedPositions, $stakeKobo, $idempotencyKey]);
    }

    public function revealHeritageTicket(string $token, string $reference): array
    {
        return $this->respond(__FUNCTION__, [$token, $reference]);
    }

    public function cagedDescriptor(string $token): array
    {
        return $this->respond(__FUNCTION__, [$token]);
    }

    public function purchaseCagedTicket(string $token, int $targetBirds, int $stakeKobo, string $idempotencyKey): array
    {
        return $this->respond(__FUNCTION__, [$token, $targetBirds, $stakeKobo, $idempotencyKey]);
    }

    public function revealCagedTicket(string $token, string $reference): array
    {
        return $this->respond(__FUNCTION__, [$token, $reference]);
    }

    public function notifyTicketSms(string $token, string $reference): void
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$token, $reference]];
    }

    public function responsiblePlayStatus(string $token): array
    {
        return $this->respond(__FUNCTION__, [$token]);
    }

    public function startCoolOff(string $token, string $optionId): array
    {
        return $this->respond(__FUNCTION__, [$token, $optionId]);
    }

    public function selfExclude(string $token, string $optionId): array
    {
        return $this->respond(__FUNCTION__, [$token, $optionId]);
    }

    public function updateLimit(string $token, string $key, int $value): array
    {
        return $this->respond(__FUNCTION__, [$token, $key, $value]);
    }
}
