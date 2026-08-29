<?php

declare(strict_types=1);

namespace App\Domain\Identity\Verification;

final readonly class IdentityVendorResult
{
    public function __construct(
        public bool $verified,
        public ?string $name,
        public string $reference,
        public ?string $rejectionReason = null,
    ) {
    }
}
