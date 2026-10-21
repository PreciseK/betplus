<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Heritage;

/**
 * PRD §6.2 resolve/replay response shape, as a pure value object.
 */
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
        $state = $payload['engine_state'] ?? [];

        return new self(
            outcomeTier: (string) ($payload['outcome_tier'] ?? 'TIER_LOSS'),
            grossPrizeKobo: (int) ($payload['gross_prize_kobo'] ?? 0),
            board: (array) ($state['board'] ?? []),
            winningPositions: (array) ($state['winning_positions'] ?? []),
            selectedPositions: (array) ($state['selected_positions'] ?? []),
            matchCount: (int) ($state['match_count'] ?? 1),
            tradition: (string) ($state['tradition'] ?? ''),
            leaderType: (string) ($state['leader_type'] ?? 'king'),
            secondChanceStakeKobo: isset($state['second_chance_stake_kobo']) ? (int) $state['second_chance_stake_kobo'] : null,
            engineVersion: (string) ($payload['engine_version'] ?? HeritageEngine::VERSION),
            digest: (string) ($payload['digest'] ?? ''),
        );
    }

    public function won(): bool
    {
        return $this->grossPrizeKobo > 0;
    }

    /**
     * Serializes to the Engine Contract JSON response shape.
     *
     * @return array<string, mixed>
     */
    public function toContractArray(string $ticketId): array
    {
        return [
            'ticket_id' => $ticketId,
            'outcome_tier' => $this->outcomeTier,
            'gross_prize_kobo' => $this->grossPrizeKobo,
            'engine_state' => [
                'board' => $this->board,
                'winning_positions' => $this->winningPositions,
                'selected_positions' => $this->selectedPositions,
                'match_count' => $this->matchCount,
                'tradition' => $this->tradition,
                'leader_type' => $this->leaderType,
                'second_chance_stake_kobo' => $this->secondChanceStakeKobo,
            ],
            'engine_version' => $this->engineVersion,
            'digest' => $this->digest,
        ];
    }
}
