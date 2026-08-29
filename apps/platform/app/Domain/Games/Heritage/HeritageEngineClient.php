<?php

declare(strict_types=1);

namespace App\Domain\Games\Heritage;

use App\Models\PrizeTable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The platform's side of the real Engine Contract (PRD §6.2) — loopback HTTP to
 * apps/engine-heritage, Heritage's own runtime and language (Python/FastAPI),
 * unlike BlackRedEngine which still runs in-process (see DeckDraw.php's doc comment:
 * "promote this directory to that shape when a second engine exists" — this class and
 * apps/engine-heritage are that promotion, for Heritage specifically; BlackRed's own
 * promotion to a standalone service remains a separate, not-yet-done piece of work).
 *
 * Deliberately NOT under Domain/Games/Engine/ — that directory is the pure,
 * framework-free engine code tests/Unit/EnginePurityTest.php sweeps for
 * Illuminate/Eloquent imports (REQ-GEC-002). This class calling Illuminate\Http\Client
 * is correct — it's the platform's own adapter to an external engine, not the engine
 * itself — but living inside that swept directory would make the purity check fail
 * for a reason that has nothing to do with the engine's own purity.
 */
final class HeritageEngineClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {
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
        return $this->call('/engine/v1/resolve', $ticketId, $seedHex, $stakeKobo, $prizeTable, $selectedPositions, $tradition, $leaderType);
    }

    /**
     * Same request, byte-identical response (REQ-GEC-001) — used by audit replay
     * (mirrors Domain/Ticket's use of BlackRedEngine::replay()).
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
        return $this->call('/engine/v1/replay', $ticketId, $seedHex, $stakeKobo, $prizeTable, $selectedPositions, $tradition, $leaderType);
    }

    /** @param list<int> $selectedPositions */
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
            $response = Http::timeout($this->timeoutSeconds)
                ->post($this->baseUrl . $path, $body);
        } catch (ConnectionException $e) {
            throw new HeritageEngineException("engine-heritage unreachable at {$this->baseUrl}{$path}: {$e->getMessage()}", previous: $e);
        }

        if (!$response->successful()) {
            throw new HeritageEngineException("engine-heritage rejected the request ({$response->status()}): {$response->body()}");
        }

        return HeritageEngineResult::fromArray($response->json());
    }
}
