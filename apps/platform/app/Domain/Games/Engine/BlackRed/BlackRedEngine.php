<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\BlackRed;

use InvalidArgumentException;
use RuntimeException;

/**
 * §8.3 — the whole engine. A pure function of (seed, prediction, stake, tiers):
 * no database, no external call, no filesystem write, no internally generated
 * randomness (REQ-GEC-001, REQ-GEC-002, REQ-GEC-003).
 */
final class BlackRedEngine
{
    public const VERSION = 'blackred-1.0.0';

    /**
     * $prediction is typed as list<string> here, not list<'B'|'R'> — the runtime check
     * right below is real validation of untrusted input reaching this pure boundary,
     * not a redundant assertion of something the type system already guarantees.
     *
     * @param list<string> $prediction
     * @param list<EngineTier> $tiers
     */
    public function resolve(string $seedHex, array $prediction, int $stakeKobo, array $tiers): EngineResult
    {
        $length = count($prediction);
        if ($length < 1 || $length > 5) {
            throw new InvalidArgumentException('Prediction must be 1 to 5 positions (REQ-BR-001).');
        }
        foreach ($prediction as $choice) {
            if ($choice !== 'B' && $choice !== 'R') {
                throw new InvalidArgumentException("Invalid prediction symbol: $choice");
            }
        }

        $tier = $this->tierFor($tiers, $length);
        $result = DeckDraw::draw($seedHex, $length);
        $won = $prediction === $result;
        $grossPrizeKobo = $won ? intdiv($stakeKobo * $tier->multiplierHundredths, 100) : 0;

        return new EngineResult(
            result: $result,
            won: $won,
            grossPrizeKobo: $grossPrizeKobo,
            digest: Digest::of($seedHex, $prediction, $result),
            engineVersion: self::VERSION,
        );
    }

    /**
     * Same inputs, byte-identical output — because resolve() is already pure and
     * deterministic, replay is not a separate implementation (REQ-GEC-001). Audit
     * tooling (REQ-BO-005) calls this name; it exists so that intent reads clearly at
     * the call site.
     *
     * @param list<string> $prediction
     * @param list<EngineTier> $tiers
     */
    public function replay(string $seedHex, array $prediction, int $stakeKobo, array $tiers): EngineResult
    {
        return $this->resolve($seedHex, $prediction, $stakeKobo, $tiers);
    }

    /** @return array{gameCode:string,engineVersion:string,minPositions:int,maxPositions:int} */
    public function describe(): array
    {
        return [
            'gameCode' => 'BLACKRED',
            'engineVersion' => self::VERSION,
            'minPositions' => 1,
            'maxPositions' => 5,
        ];
    }

    /** @param list<EngineTier> $tiers */
    private function tierFor(array $tiers, int $positions): EngineTier
    {
        foreach ($tiers as $tier) {
            if ($tier->positions === $positions) {
                return $tier;
            }
        }

        throw new RuntimeException("No prize table tier configured for $positions positions.");
    }
}
