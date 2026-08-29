<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\BlackRed;

/**
 * §8.3 (REQ-BR-002, REQ-BR-003) — seed to result sequence, deterministic.
 *
 * ponytail note on the wider engine: this class and its siblings under
 * Domain/Games/Engine/ hold zero Eloquent/DB/Illuminate\Support\Facades imports —
 * checked by tests/Unit/EnginePurityTest.php — which is what actually makes them a
 * "pure function of its inputs" (REQ-GEC-001/002) today. The architecture doc's
 * apps/engine-blackred/ as its own Composer package behind a loopback HTTP boundary
 * with mTLS (REQ-SEC-011, D-07) is real infra work, deferred alongside Story 1.4 —
 * promote this directory to that shape when a second engine (Heritage, Epic 7) exists
 * or before a production deployment, whichever comes first.
 */
final class DeckDraw
{
    /**
     * Each position is an independent, unbiased 50/50 draw (REQ-BR-002) derived only
     * from the platform-issued seed — no PHP randomness function is called here
     * (REQ-RNG-007 prohibits rand()/mt_rand()/shuffle()/array_rand()/str_shuffle() on
     * this path; HMAC expansion of a CSPRNG seed isn't on that list and is what
     * REQ-RNG-001 actually asks for).
     *
     * @return list<'B'|'R'>
     */
    public static function draw(string $seedHex, int $length): array
    {
        $seed = hex2bin($seedHex);
        if ($seed === false) {
            throw new \InvalidArgumentException('seedHex must be valid hex.');
        }

        $sequence = [];
        for ($position = 0; $position < $length; $position++) {
            $digest = hash_hmac('sha256', (string) $position, $seed, true);
            $sequence[] = (ord($digest[0]) & 1) === 0 ? 'B' : 'R';
        }

        return $sequence;
    }
}
