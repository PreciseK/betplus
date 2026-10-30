<?php

declare(strict_types=1);

namespace App\Domain\Games\Doors;

use InvalidArgumentException;

/** Pure, deterministic, seed-only board construction. Exactly one treasure per board. */
final class DoorsEngine
{
    public const VERSION = 'doors-1.0.0';

    /**
     * @param array{board_size:int,treasures:list<array{multiplier:int,weight_bps:int}>} $config
     * @return array{board:list<int>,commitment:string}
     */
    public function board(string $seedHex, string $reference, array $config): array
    {
        if (!ctype_xdigit($seedHex) || strlen($seedHex) !== 64) {
            throw new InvalidArgumentException('Doors requires a 256-bit seed.');
        }
        $roll = $this->bounded($seedHex, $reference . ':treasure', 10_000);
        $cumulative = 0;
        $multiplier = 0;
        foreach ($config['treasures'] as $treasure) {
            $cumulative += $treasure['weight_bps'];
            if ($roll < $cumulative) { $multiplier = $treasure['multiplier']; break; }
        }
        $board = array_fill(0, $config['board_size'], 0);
        $board[$this->bounded($seedHex, $reference . ':position', count($board))] = $multiplier;
        return ['board' => $board, 'commitment' => hash('sha256', $reference . ':' . $seedHex . ':' . json_encode($board, JSON_THROW_ON_ERROR))];
    }

    private function bounded(string $seed, string $domain, int $bound): int
    {
        // Rejection sampling avoids modulo bias. No platform randomness or mutable state.
        $limit = intdiv(4_294_967_296, $bound) * $bound;
        for ($counter = 0; ; $counter++) {
            $digest = hash_hmac('sha256', $domain . ':' . $counter, hex2bin($seed), true);
            $value = unpack('N', substr($digest, 0, 4))[1];
            if ($value < $limit) { return $value % $bound; }
        }
    }
}
