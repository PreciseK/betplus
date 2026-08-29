<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

final readonly class LedgerLine
{
    public function __construct(
        public string $accountType,
        public ?string $scope,
        public string $direction, // 'debit' | 'credit'
        public int $amountKobo,
        public ?string $stateCode = null,
    ) {
    }
}
