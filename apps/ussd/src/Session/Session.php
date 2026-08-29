<?php

declare(strict_types=1);

namespace Betplus\Ussd\Session;

/**
 * Mutable per-USSD-session state, one row of "where is this caller and what have
 * they entered so far" — screen is the current menu node; data holds whatever that
 * screen needs remembered for the next turn (a stake amount, a partial pick, a
 * pending idempotency key, ...).
 */
final class Session
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $sessionId,
        public string $msisdn,
        public string $screen,
        public array $data,
        public ?string $accessToken,
        public int $lastTouchedAt,
    ) {
    }

    public static function begin(string $sessionId, string $msisdn): self
    {
        return new self($sessionId, $msisdn, 'welcome', [], null, time());
    }

    /** @return array{sessionId:string,msisdn:string,screen:string,data:array<string,mixed>,accessToken:?string,lastTouchedAt:int} */
    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'msisdn' => $this->msisdn,
            'screen' => $this->screen,
            'data' => $this->data,
            'accessToken' => $this->accessToken,
            'lastTouchedAt' => $this->lastTouchedAt,
        ];
    }

    /** @param array{sessionId:string,msisdn:string,screen:string,data:array<string,mixed>,accessToken:?string,lastTouchedAt:int} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['sessionId'], $row['msisdn'], $row['screen'], $row['data'], $row['accessToken'], $row['lastTouchedAt']);
    }
}
