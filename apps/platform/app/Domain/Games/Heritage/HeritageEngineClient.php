<?php

declare(strict_types=1);

namespace App\Domain\Games\Heritage;

use App\Domain\Games\Engine\Heritage\HeritageEngine;
use App\Domain\Games\Engine\Heritage\HeritageTier;
use App\Models\PrizeTable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Platform adapter to the Heritage game engine.
 *
 * Runs natively and deterministically in PHP via HeritageEngine (PRD §6.2, REQ-GEC-001..003).
 * When an external engine URL is configured or mocked via Http::fake() in tests,
 * it safely attempts the HTTP contract first and falls through to the native in-process engine.
 */
final class HeritageEngineClient
{
    private readonly HeritageEngine $engine;

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly int $timeoutSeconds = 5,
        ?HeritageEngine $engine = null,
    ) {
        $this->engine = $engine ?? new HeritageEngine();
    }

    /** @param list<int> $selectedPositions */
    public function resolve(
        string $ticketId,
        string $seedHex,
        int $stakeKobo,
        PrizeTable $prizeTable,
        array $selectedPositions,
        string $tradition,
        string $leaderType,
    ): HeritageEngineResult {
        return $this->call(
            '/engine/v1/resolve',
            $ticketId,
            $seedHex,
            $stakeKobo,
            $prizeTable,
            $selectedPositions,
            $tradition,
            $leaderType
        );
    }

    /**
     * Same request, byte-identical response (REQ-GEC-001) — used by audit replay.
     *
     * @param list<int> $selectedPositions
     */
    public function replay(
        string $ticketId,
        string $seedHex,
        int $stakeKobo,
        PrizeTable $prizeTable,
        array $selectedPositions,
        string $tradition,
        string $leaderType,
    ): HeritageEngineResult {
        return $this->call(
            '/engine/v1/replay',
            $ticketId,
            $seedHex,
            $stakeKobo,
            $prizeTable,
            $selectedPositions,
            $tradition,
            $leaderType
        );
    }

    /**
     * Model 4 (pari-mutuel pool) — the one shared winning combination a pool's
     * settlement compares every pooled entry's own selected_positions against.
     *
     * @return list<int>
     */
    public function drawPoolOutcome(string $seedHex): array
    {
        if ($this->shouldAttemptHttp()) {
            try {
                $response = Http::timeout($this->timeoutSeconds)
                    ->post($this->baseUrl . '/engine/v1/draw-pool', ['seed' => $seedHex]);

                if ($response->successful()) {
                    return $response->json('winning_positions');
                }
            } catch (ConnectionException) {
                // HTTP unreachable — fall through to in-process pure engine
            }
        }

        return $this->engine->drawPoolOutcome($seedHex);
    }

    /**
     * Dispatches to HTTP when configured or intercepted by Http::fake(),
     * falling through directly to the pure in-process HeritageEngine on connection refusal.
     *
     * @param list<int> $selectedPositions
     */
    private function call(
        string $path,
        string $ticketId,
        string $seedHex,
        int $stakeKobo,
        PrizeTable $prizeTable,
        array $selectedPositions,
        string $tradition,
        string $leaderType,
    ): HeritageEngineResult {
        if ($this->shouldAttemptHttp()) {
            $body = [
                'ticket_id' => $ticketId,
                'game_code' => 'HERITAGE',
                'stake_kobo' => $stakeKobo,
                'prize_table_version' => $prizeTable->version,
                'prize_table' => $prizeTable->heritageTiers->map(fn ($tier) => [
                    'name' => $tier->tierName,
                    'probability_basis_points' => $tier->probabilityBasisPoints,
                    'multiplier_hundredths' => $tier->multiplierHundredths,
                    'outcome_type' => $tier->outcomeType,
                ])->values()->all(),
                'seed' => $seedHex,
                'player_input' => [
                    'selected_positions' => $selectedPositions,
                    'tradition' => $tradition,
                    'leader_type' => $leaderType,
                ],
            ];

            try {
                $response = Http::timeout($this->timeoutSeconds)->post($this->baseUrl . $path, $body);
                if ($response->successful()) {
                    return HeritageEngineResult::fromArray($response->json());
                }
            } catch (ConnectionException) {
                // Loopback HTTP unavailable — execute in-process
            }
        }

        return $this->resolveInProcess(
            $ticketId,
            $seedHex,
            $stakeKobo,
            $prizeTable,
            $selectedPositions,
            $tradition,
            $leaderType
        );
    }

    private function shouldAttemptHttp(): bool
    {
        return $this->baseUrl !== null
            && $this->baseUrl !== ''
            && $this->baseUrl !== 'in_process'
            && $this->baseUrl !== 'native';
    }

    /**
     * In-process deterministic resolution using the pure HeritageEngine.
     *
     * @param list<int> $selectedPositions
     */
    private function resolveInProcess(
        string $ticketId,
        string $seedHex,
        int $stakeKobo,
        PrizeTable $prizeTable,
        array $selectedPositions,
        string $tradition,
        string $leaderType,
    ): HeritageEngineResult {
        $tiers = $prizeTable->heritageTiers->map(fn ($tier) => new HeritageTier(
            name: (string) $tier->tierName,
            probabilityBasisPoints: (int) $tier->probabilityBasisPoints,
            multiplierHundredths: (int) $tier->multiplierHundredths,
            outcomeType: (string) $tier->outcomeType,
        ))->values()->all();

        $engineResult = $this->engine->resolve(
            $ticketId,
            $seedHex,
            $stakeKobo,
            $tiers,
            $selectedPositions,
            $tradition,
            $leaderType
        );

        return new HeritageEngineResult(
            outcomeTier: $engineResult->outcomeTier,
            grossPrizeKobo: $engineResult->grossPrizeKobo,
            board: $engineResult->board,
            winningPositions: $engineResult->winningPositions,
            selectedPositions: $engineResult->selectedPositions,
            matchCount: $engineResult->matchCount,
            tradition: $engineResult->tradition,
            leaderType: $engineResult->leaderType,
            secondChanceStakeKobo: $engineResult->secondChanceStakeKobo,
            engineVersion: $engineResult->engineVersion,
            digest: $engineResult->digest,
        );
    }
}
