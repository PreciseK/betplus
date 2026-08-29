<?php

declare(strict_types=1);

namespace App\Domain\Games\Draw;

/** REQ-HG-035 — an entry without a verifiable partnerReference is never lodged. */
final readonly class SubmitEntryResult
{
    public function __construct(
        public bool $success,
        public ?string $partnerReference = null,
        public ?string $failureReason = null,
    ) {
    }
}
