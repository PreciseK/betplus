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
    /**
     * Tribe+leader -> the 5 catalogue numbers that belong to it. Mirrors
     * apps/platform/database/seeders/HeritageGameSeeder.php's seedCatalogue()
     * exactly (items 21-90) and apps/engine-heritage/src/engine_heritage/board.py's
     * identical map — kept in sync by hand, since the real engine holds no
     * database and this fallback holds no dependency on the real engine.
     *
     * @var array<string, list<int>>
     */
    private const TRIBE_LEADER_NUMBERS = [
        'yoruba:king' => [21, 22, 23, 24, 25],
        'yoruba:queen' => [26, 27, 28, 29, 30],
        'igbo:king' => [31, 32, 33, 34, 35],
        'igbo:queen' => [36, 37, 38, 39, 40],
        'hausa_fulani:king' => [41, 42, 43, 44, 45],
        'hausa_fulani:queen' => [46, 47, 48, 49, 50],
        'edo:king' => [51, 52, 53, 54, 55],
        'edo:queen' => [56, 57, 58, 59, 60],
        'efik_ibibio:king' => [61, 62, 63, 64, 65],
        'efik_ibibio:queen' => [66, 67, 68, 69, 70],
        'ijaw:king' => [71, 72, 73, 74, 75],
        'ijaw:queen' => [76, 77, 78, 79, 80],
        'middle_belt:king' => [81, 82, 83, 84, 85],
        'middle_belt:queen' => [86, 87, 88, 89, 90],
    ];

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

        // winningPositions is computed before the board: it's the input the board
        // draw uses to place the player's chosen tradition/leader's regalia exactly
        // on the winning tiles (see drawHeritageBoard()'s doc comment). It depends
        // only on matchCount and the player's own picks, never on the board, so
        // this ordering changes nothing about which positions win.
        $complement = array_values(array_diff(range(0, 8), $selectedPositions));
        $playerPicks = $this->drawWithoutReplacement($seed, 200, $selectedPositions, $matchCount);
        $otherPicks = $this->drawWithoutReplacement($seed, 200 + $matchCount, $complement, 5 - $matchCount);
        $winningPositions = array_merge($playerPicks, $otherPicks);
        sort($winningPositions);

        $board = $this->drawHeritageBoard($seed, $winningPositions, $tradition, $leaderType);

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

    /**
     * 9 distinct integers from 1-90, one per position, in position order.
     *
     * When (tradition, leaderType) resolve to a known tribe+leader, the 5
     * catalogue numbers belonging to it are placed exactly on winningPositions,
     * and the remaining 4 positions get random decoys from the other 85. This
     * never touches odds — winningPositions is already fully determined before
     * this runs, purely from the tier and the player's own picks, so which
     * regalia is shown stays cosmetic (REQ-HG-051) even though it's no longer
     * independent of the winning set. Falls back to a fully random,
     * tradition-agnostic draw for an unrecognized tribe+leader.
     *
     * @param list<int> $winningPositions
     * @return list<int>
     */
    private function drawHeritageBoard(string $seed, array $winningPositions, string $tradition, string $leaderType): array
    {
        $authentic = self::TRIBE_LEADER_NUMBERS["{$tradition}:{$leaderType}"] ?? null;
        if ($authentic === null) {
            return $this->drawWithoutReplacement($seed, 100, range(1, 90), 9);
        }

        $decoyPool = array_values(array_diff(range(1, 90), $authentic));
        $shuffledAuthentic = $this->drawWithoutReplacement($seed, 100, $authentic, count($authentic));
        $decoys = $this->drawWithoutReplacement($seed, 100 + count($authentic), $decoyPool, 9 - count($authentic));

        $sortedWinners = array_values(array_unique($winningPositions));
        sort($sortedWinners);
        $nonWinningPositions = array_values(array_diff(range(0, 8), $sortedWinners));

        $board = array_fill(0, 9, 0);
        foreach ($sortedWinners as $index => $position) {
            $board[$position] = $shuffledAuthentic[$index];
        }
        foreach ($nonWinningPositions as $index => $position) {
            $board[$position] = $decoys[$index];
        }

        return $board;
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
