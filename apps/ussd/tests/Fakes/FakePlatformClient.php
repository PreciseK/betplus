<?php

declare(strict_types=1);

namespace Betplus\Ussd\Tests\Fakes;

use Betplus\Ussd\PlatformClientInterface;

/** Records every call and returns pre-programmed responses — no HTTP, no platform. */
final class FakePlatformClient implements PlatformClientInterface
{
    /** @var array<string, list<array<string, mixed>>> queue per method — each programResponse() call appends one */
    public array $responses = [];

    /** @var array<string, array<string, mixed>> the last response shifted off each method's queue, repeated once the queue is empty */
    private array $lastResponse = [];

    /** @var list<array{method:string, args:array<int, mixed>}> */
    public array $calls = [];

    /**
     * Program the next response a method call returns. Call it more than once for
     * the same method to hand out different responses to successive calls (e.g. a
     * flow that calls createDeposit() twice, once before and once after identity
     * verification) — once the queue is exhausted, the last-programmed response
     * repeats, so a single call still behaves as it always has.
     *
     * @param array<string, mixed> $response
     */
    public function programResponse(string $method, array $response): void
    {
        $this->responses[$method][] = $response;
    }

    /** @return array<string, mixed> */
    private function respond(string $method, array $args): array
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        if (($this->responses[$method] ?? []) !== []) {
            $this->lastResponse[$method] = array_shift($this->responses[$method]);
        }

        return $this->lastResponse[$method] ?? [];
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

    public function collectFromOpay(string $token, int $amountKobo, string $reference): array
    {
        return $this->respond(__FUNCTION__, [$token, $amountKobo, $reference]);
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
