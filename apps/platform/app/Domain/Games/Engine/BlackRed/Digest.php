<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\BlackRed;

/** §6.2 — sha256 of the canonical outcome, returned alongside every resolve/replay. */
final class Digest
{
    /**
     * @param list<'B'|'R'> $prediction
     * @param list<'B'|'R'> $result
     */
    public static function of(string $seedHex, array $prediction, array $result): string
    {
        return hash('sha256', $seedHex . '|' . implode('', $prediction) . '|' . implode('', $result));
    }
}
