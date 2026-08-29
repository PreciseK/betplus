<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Deposits — real Collection rows, no back-office read existed before this. */
class CollectionController extends Controller
{
    /** GET /backoffice/v1/deposits?status=paid&date_from=&date_to= */
    public function index(Request $request): JsonResponse
    {
        $collections = Collection::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('date_from'), fn ($q, $from) => $q->where('createdAt', '>=', $from))
            ->when($request->query('date_to'), fn ($q, $to) => $q->where('createdAt', '<=', $to))
            ->orderByDesc('createdAt')
            ->limit(200)
            ->get();

        $players = Player::whereIn('id', $collections->pluck('playerId'))->get()->keyBy('id');

        return response()->json([
            'deposits' => $collections->map(fn (Collection $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'player_id' => $c->playerId,
                'player_reference' => 'BP-' . $c->playerId,
                'registered_name' => $players->get($c->playerId)?->registeredName,
                'amount_kobo' => $c->amountKobo,
                'fee_kobo' => $c->feeKobo,
                'provider_collection_id' => $c->providerCollectionId,
                'status' => $c->status,
                'paid_at' => $c->paidAt?->toIso8601String(),
                'created_at' => $c->createdAt->toIso8601String(),
            ])->values(),
        ]);
    }
}
