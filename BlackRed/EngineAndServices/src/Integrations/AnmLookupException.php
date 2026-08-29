<?php

declare(strict_types=1);

namespace BlackRed\Integrations;

use RuntimeException;

/**
 * Thrown by AnmClient when a name lookup fails. The message is safe to
 * surface to the user — we craft these messages carefully and they don't
 * leak internal details.
 */
final class AnmLookupException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
