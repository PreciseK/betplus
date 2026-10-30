<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Games\Engine\BlackRed\BlackRedEngine;
use App\Domain\Games\Engine\BlackRed\EngineTier;
use App\Domain\Games\PrizeTable\PrizeTableResolver;
use App\Http\Controllers\Controller;
use App\Models\FairnessSeed;
use App\Models\Ticket;
use App\Models\DoorsRound;
use App\Domain\Games\Doors\DoorsEngine;
use Illuminate\Http\JsonResponse;

/**
 * Story 6.5 (REQ-BO-005) — "given a ticket reference, call the engine's replay and
 * display the full deterministic derivation from the sealed seed." The comparison
 * against the stored outcome is the point: if a ticket's persisted result and a fresh
 * replay ever disagree, that is exactly the fairness-challenge scenario this exists
 * to answer, so the response surfaces both rather than trusting either silently.
 */
class TicketAuditController extends Controller
{
    public function __construct(
        private readonly BlackRedEngine $engine,
        private readonly PrizeTableResolver $prizeTableResolver,
    ) {
    }

    /** GET /backoffice/v1/tickets/{reference}/replay */
    public function replay(string $reference): JsonResponse
    {
        $ticket = Ticket::with('outcome')->where('reference', $reference)->firstOrFail();
        $seed = FairnessSeed::findOrFail($ticket->rngSeedRef);
        if ($ticket->gameCode === 'DOORS') { return $this->doorsReplay($ticket, $seed); }

        $prizeTable = $this->prizeTableResolver->resolveFor($ticket->gameCode, $ticket->stateCode, $ticket->createdAt);
        $tiers = $prizeTable?->tiers->map(fn ($t) => new EngineTier((int) $t->positions, (int) $t->multiplierHundredths))->all() ?? [];

        $replayed = $this->engine->replay($seed->seedHex, $ticket->predictionJson, $ticket->stakeKobo, $tiers);

        return response()->json([
            'reference' => $ticket->reference,
            'seed_hex' => $seed->seedHex,
            'seed_algorithm' => $seed->algorithm,
            'engine_version' => $ticket->engineVersion,
            'prediction' => $ticket->predictionJson,
            'stored' => [
                'result' => $ticket->outcome?->resultJson,
                'won' => $ticket->outcome?->won,
                'digest' => $ticket->outcome?->digest,
            ],
            'replayed' => [
                'result' => $replayed->result,
                'won' => $replayed->won,
                'digest' => $replayed->digest,
            ],
            'matches' => $ticket->outcome !== null
                && $ticket->outcome->digest === $replayed->digest,
        ]);
    }

    private function doorsReplay(Ticket $ticket, FairnessSeed $seed): JsonResponse
    {
        $round = DoorsRound::where('ticketId', $ticket->id)->firstOrFail();
        if ($round->status === 'IN_PLAY') {
            return response()->json(['message' => 'Active Doors rounds cannot be replayed before settlement.'], 409);
        }
        $replayed = (new DoorsEngine())->board($seed->seedHex, $ticket->reference, $round->configJson['rules']);
        $opened = []; $multiplier = 0; $valid = true; $seen = [];
        foreach ($round->openedJson as $pick) {
            $index = $pick['index'];
            if ($multiplier > 0 || isset($seen[$index]) || !array_key_exists($index, $replayed['board'])) { $valid = false; break; }
            $seen[$index] = true;
            $multiplier = $replayed['board'][$index];
            $opened[] = ['index' => $index, 'multiplier' => $multiplier];
        }
        $valid = $valid && count($opened) <= $round->configJson['rules']['max_attempts'];
        $result = ['opened' => $opened, 'multiplier' => $multiplier];
        $digest = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
        $outcome = $ticket->outcome;
        return response()->json([
            'reference' => $ticket->reference, 'seed_hex' => $seed->seedHex, 'seed_algorithm' => $seed->algorithm,
            'engine_version' => $ticket->engineVersion, 'prediction' => [],
            'stored' => ['result' => $outcome->resultJson ?? null, 'won' => $outcome->won ?? null, 'digest' => $outcome->digest ?? null],
            'replayed' => ['result' => $result, 'won' => $multiplier > 0, 'digest' => $digest],
            'matches' => $valid && $outcome !== null && $outcome->digest === $digest
                && $outcome->grossPrizeKobo === $ticket->stakeKobo * $multiplier
                && $round->boardEncrypted === $replayed['board'] && hash_equals($round->commitment, $replayed['commitment']),
        ]);
    }
}
