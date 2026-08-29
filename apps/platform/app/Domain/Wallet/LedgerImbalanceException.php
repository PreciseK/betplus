<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use RuntimeException;

/** REQ-WAL-001 — a non-zero imbalance raises a P0. Thrown before anything is written. */
final class LedgerImbalanceException extends RuntimeException
{
}
