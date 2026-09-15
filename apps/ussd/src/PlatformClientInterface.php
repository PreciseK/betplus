<?php

declare(strict_types=1);

namespace Betplus\Ussd;

/**
 * Extracted so MenuEngine can be tested against a fake without a real platform
 * server running — PlatformClient (curl-backed) is the only production
 * implementation; tests/Fakes/FakePlatformClient is the other.
 */
interface PlatformClientInterface
{
    /** @return array<string, mixed> */
    public function identify(string $msisdn): array;

    /** @return array<string, mixed> */
    public function completeRegistration(string $msisdn): array;

    public function syncSession(string $sessionId, string $msisdn, string $screen, string $inputText, string $status): void;

    /** @return array<string, mixed> */
    public function wallet(string $token): array;

    /** @return array<string, mixed> */
    public function directWithdrawFromOpay(string $token, int $amountKobo, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function verifyNin(string $token, string $dateOfBirth, string $nin): array;

    /** @return array<string, mixed> */
    public function blackRedDescriptor(string $token): array;

    /**
     * @param list<string> $prediction
     * @return array<string, mixed>
     */
    public function purchaseBlackRedTicket(string $token, array $prediction, int $stakeKobo, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function revealBlackRedTicket(string $token, string $reference): array;

    /** @return array<string, mixed> */
    public function heritageDescriptor(string $token): array;

    /**
     * @param list<int> $selectedPositions
     * @return array<string, mixed>
     */
    public function purchaseHeritageTicket(string $token, array $selectedPositions, int $stakeKobo, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function revealHeritageTicket(string $token, string $reference): array;

    /** @return array<string, mixed> */
    public function cagedDescriptor(string $token): array;

    /**
     * @return array<string, mixed>
     */
    public function purchaseCagedTicket(string $token, int $targetBirds, int $stakeKobo, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function revealCagedTicket(string $token, string $reference): array;

    /** REQ-USSD-005/REQ-NOT-008 — a dropped session must never cost the player their result. */
    public function notifyTicketSms(string $token, string $reference): void;

    /** @return array<string, mixed> */
    public function responsiblePlayStatus(string $token): array;

    /** @return array<string, mixed> */
    public function startCoolOff(string $token, string $optionId): array;

    /** @return array<string, mixed> */
    public function selfExclude(string $token, string $optionId): array;

    /** @return array<string, mixed> */
    public function updateLimit(string $token, string $key, int $value): array;
}
