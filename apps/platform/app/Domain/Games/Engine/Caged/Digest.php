<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Caged;

/** sha256 of the canonical outcome, returned alongside every resolve/replay. */
final class Digest
{
    public static function of(string $seedHex, int $targetBirds, int $escapedBirds): string
    {
        return hash('sha256', $seedHex . '|' . $targetBirds . '|' . $escapedBirds);
    }
}
