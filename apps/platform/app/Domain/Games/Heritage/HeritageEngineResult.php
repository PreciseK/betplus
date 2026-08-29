<?php

declare(strict_types=1);

namespace App\Domain\Games\Heritage;

/** PRD §6.2 resolve/replay response shape, as a plain value object. */
final readonly class HeritageEngineResult
{
    /**
     * @param list<int> $board
     * @param list<int> $winningPositions
     * @param list<int> $selectedPositions
     */
    public function __construct(
        public string $outcomeTier,
        public int $grossPrizeKobo,
        public array $board,
        public array $winningPositions,
        public array $selectedPositions,
        public int $matchCount,
        public string $tradition,
        public string $leaderType,
        public ?int $secondChanceStakeKobo,
        public string $engineVersion,
        public string $digest,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $state = $payload['engine_state'];

        return new self(
            outcomeTier: $payload['outcome_tier'],
            grossPrizeKobo: $payload['gross_prize_kobo'],
            board: $state['board'],
            winningPositions: $state['winning_positions'],
            selectedPositions: $state['selected_positions'],
            matchCount: $state['match_count'],
            tradition: $state['tradition'],
            leaderType: $state['leader_type'],
            secondChanceStakeKobo: $state['second_chance_stake_kobo'],
            engineVersion: $payload['engine_version'],
            digest: $payload['digest'],
        );
    }

    public function won(): bool
    {
        // REQ-HG-015 — a cash tier is the only outcome the player calls "a win";
        // second-chance is disclosed as a draw entry, never worded as a win.
        return $this->grossPrizeKobo > 0;
    }
}
