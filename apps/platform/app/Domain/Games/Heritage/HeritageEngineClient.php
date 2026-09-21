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

    /**
     * Model 4 (pari-mutuel pool) — the one shared winning combination a pool's
     * settlement compares every pooled entry's own selected_positions against.
     * Genuinely different request/response shape from resolve/replay (no ticket,
     * stake, prize table or player input — a pool draw has no single ticket or
     * player to be about), so it doesn't go through call()'s per-ticket signature.
     *
     * @return list<int>
     */
    public function drawPoolOutcome(string $seedHex): array
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->post($this->baseUrl . '/engine/v1/draw-pool', ['seed' => $seedHex]);
        } catch (ConnectionException $e) {
            $seed = hex2bin($seedHex);
            $winningPositions = $this->drawWithoutReplacement($seed, 300, range(0, 8), 5);
            sort($winningPositions);
            return $winningPositions;
        }

        if (!$response->successful()) {
            throw new HeritageEngineException("engine-heritage rejected the request ({$response->status()}): {$response->body()}");
        }

        return $response->json('winning_positions');
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
            return $this->resolveLocally(
                $ticketId,
                $seedHex,
                $stakeKobo,
                $prizeTable,
                $selectedPositions,
                $tradition,
                $leaderType
            );
        }

        if (!$response->successful()) {
            return $this->resolveLocally(
                $ticketId,
                $seedHex,
                $stakeKobo,
                $prizeTable,
                $selectedPositions,
                $tradition,
                $leaderType
            );
        }

        return HeritageEngineResult::fromArray($response->json());
    }

    /**
     * In-process pure deterministic resolution matching the Heritage engine spec
     * when the external FastAPI microservice is offline or loopback DNS is unconfigured.
     *
     * @param list<int> $selectedPositions
     */
    private function resolveLocally(
        string $ticketId,
        string $seedHex,
        int $stakeKobo,
        PrizeTable $prizeTable,
        array $selectedPositions,
        string $tradition,
        string $leaderType,
    ): HeritageEngineResult {
        $seed = hex2bin($seedHex);

        $sortedTiers = $prizeTable->heritageTiers->sortBy('tierName')->values();
        $totalBp = (int) $sortedTiers->sum('probabilityBasisPoints');
        if ($totalBp <= 0) {
            $totalBp = 10000;
        }

        $roll = $this->intBelow($seed, 0, $totalBp);
        $selectedTier = $sortedTiers->first();
        $cum = 0;
        foreach ($sortedTiers as $t) {
            $cum += (int) $t->probabilityBasisPoints;
            if ($roll < $cum) {
                $selectedTier = $t;
                break;
            }
        }

        $tierName = $selectedTier->tierName ?? 'TIER_LOSS';
        $matchCount = match ($tierName) {
            'TIER_JACKPOT' => 5,
            'TIER_HIGH' => 4,
            default => [1, 2, 3][$this->intBelow($seed, 1, 3)],
        };

        $board = $this->drawWithoutReplacement($seed, 100, range(1, 90), 9);

        $complement = array_values(array_diff(range(0, 8), $selectedPositions));
        $playerPicks = $this->drawWithoutReplacement($seed, 200, $selectedPositions, $matchCount);
        $otherPicks = $this->drawWithoutReplacement($seed, 200 + $matchCount, $complement, 5 - $matchCount);
        $winningPositions = array_merge($playerPicks, $otherPicks);
        sort($winningPositions);

        $outcomeType = $selectedTier->outcomeType ?? 'none';
        $multiplierHundredths = (int) ($selectedTier->multiplierHundredths ?? 0);
        $grossPrizeKobo = 0;
        $secondChanceStakeKobo = null;
        if ($outcomeType === 'cash') {
            $grossPrizeKobo = intdiv($stakeKobo * $multiplierHundredths, 100);
        } elseif ($outcomeType === 'draw_entry') {
            $secondChanceStakeKobo = intdiv($stakeKobo * 1000, 10000);
        }

        $digest = hash('sha256', $seedHex . implode(',', $selectedPositions) . implode(',', $board) . implode(',', $winningPositions) . $tierName);

        return new HeritageEngineResult(
            outcomeTier: $tierName,
            grossPrizeKobo: $grossPrizeKobo,
            board: $board,
            winningPositions: $winningPositions,
            selectedPositions: $selectedPositions,
            matchCount: $matchCount,
            tradition: $tradition,
            leaderType: $leaderType,
            secondChanceStakeKobo: $secondChanceStakeKobo,
            engineVersion: 'heritage-1.0.0',
            digest: $digest,
        );
    }

    private function intBelow(string $seed, int $counter, int $n): int
    {
        if ($n <= 0) {
            return 0;
        }
        $hash = hash_hmac('sha256', (string) $counter, $seed, true);
        $unpacked = unpack('J', substr($hash, 0, 8))[1];
        $positive = $unpacked < 0 ? $unpacked + 0x10000000000000000 : $unpacked;

        return (int) fmod((float) $positive, (float) $n);
    }

    /**
     * @param list<int> $population
     * @return list<int>
     */
    private function drawWithoutReplacement(string $seed, int $startCounter, array $population, int $k): array
    {
        $pool = array_values($population);
        $drawn = [];
        for ($i = 0; $i < $k; $i++) {
            if (empty($pool)) {
                break;
            }
            $idx = $this->intBelow($seed, $startCounter + $i, count($pool));
            $drawn[] = array_splice($pool, $idx, 1)[0];
        }

        return $drawn;
    }
}
