<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Games\Engine\BirdEscape\BirdEscapeEngine;
use App\Http\Controllers\Controller;
use App\Models\CrashRound;
use Illuminate\Http\JsonResponse;

/**
 * BirdEscape's analogue of TicketAuditController::replay — "given a round number,
 * call the engine's replay and display the full deterministic derivation from the
 * sealed seed." If a round's persisted crash multiplier and a fresh replay ever
 * disagree, that is exactly the fairness-challenge scenario this exists to answer.
 */
class BirdEscapeRoundAuditController extends Controller
{
    public function __construct(private readonly BirdEscapeEngine $engine)
    {
    }

    /** GET /backoffice/v1/birdescape/rounds/{roundNumber}/replay */
    public function replay(string $roundNumber): JsonResponse
    {
        $round = CrashRound::with('fairnessSeed')->where('gameCode', 'BIRDESCAPE')->where('roundNumber', $roundNumber)->firstOrFail();

        $replayed = $this->engine->replay($round->fairnessSeed->seedHex, $round->roundNumber, $round->houseEdgeBasisPoints);

        return response()->json([
            'round_number' => $round->roundNumber,
            'seed_hex' => $round->fairnessSeed->seedHex,
            'seed_algorithm' => $round->rngAlgorithm,
            'engine_version' => $round->engineVersion,
            'stored' => [
                'crash_multiplier_hundredths' => $round->crashMultiplierHundredths,
                'digest' => $round->commitmentDigest,
            ],
            'replayed' => [
                'crash_multiplier_hundredths' => $replayed->crashMultiplierHundredths,
                'digest' => $replayed->digest,
            ],
            'matches' => $round->commitmentDigest === $replayed->digest,
        ]);
    }
}
