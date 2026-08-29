<?php

declare(strict_types=1);

namespace App\Domain\Games\Heritage;

use RuntimeException;

/** Thrown when the engine-heritage service is unreachable or rejects a request. */
final class HeritageEngineException extends RuntimeException
{
}
