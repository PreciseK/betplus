<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Heritage;

use InvalidArgumentException;

/**
 * Deterministic byte/int stream from a platform-issued seed.
 * REQ-RNG-008: HMAC-SHA256 keyed by the seed, counter as the message.
 */
final class HeritageSeed
{
    public static function intBelow(string $seed, int $counter, int $n): int
    {
        if ($n <= 0) {
            throw new InvalidArgumentException('n must be positive.');
        }
        $hash = hash_hmac('sha256', (string) $counter, $seed, true);
        $unpacked = unpack('J', substr($hash, 0, 8))[1];
        $positive = $unpacked < 0 ? $unpacked + 0x10000000000000000 : $unpacked;

        return (int) fmod((float) $positive, (float) $n);
    }

    public static function chooseIndex(string $seed, int $counter, int $n): int
    {
        return self::intBelow($seed, $counter, $n);
    }

    /**
     * @param list<int> $population
     * @return list<int>
     */
    public static function drawWithoutReplacement(string $seed, int $startCounter, array $population, int $k): array
    {
        if ($k > count($population)) {
            throw new InvalidArgumentException('k exceeds population size.');
        }

        $pool = $population;
        $drawn = [];
        for ($i = 0; $i < $k; $i++) {
            if (empty($pool)) {
                break;
            }
            $idx = self::intBelow($seed, $startCounter + $i, count($pool));
            $drawn[] = array_splice($pool, $idx, 1)[0];
        }

        return $drawn;
    }
}
