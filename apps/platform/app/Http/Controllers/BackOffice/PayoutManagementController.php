<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payouts — real Payout rows, no back-office read existed before this. Named
 * "PayoutManagement" (not PayoutController) to stay distinct from the player-facing
 * Api\V1\PayoutController. No provider-float view — no table backs one anywhere.
 */
class PayoutManagementController extends Controller
{
    /** GET /backoffice/v1/payouts?provider_status=&manual_review_required=1 */
    public function index(Request $request): JsonResponse
    {
        $payouts = Payout::query()
            ->when($request->query('provider_status'), fn ($q, $status) => $q->where('providerStatus', $status))
            ->when($request->has('manual_review_required'), fn ($q) => $q->where('manualReviewRequired', $request->boolean('manual_review_required')))
            ->orderByDesc('createdAt')
            ->limit(200)
            ->get();

        $players = Player::whereIn('id', $payouts->pluck('playerId'))->get()->keyBy('id');

        return response()->json([
            'payouts' => $payouts->map(fn (Payout $p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'player_id' => $p->playerId,
                'player_reference' => 'BP-' . $p->playerId,
                'registered_name' => $players->get($p->playerId)?->registeredName,
                'kind' => $p->kind,
                'amount_kobo' => $p->amountKobo,
                'destination_label' => $p->destinationLabel,
                'provider_status' => $p->providerStatus,
                'manual_review_required' => $p->manualReviewRequired,
                'dispatched_at' => $p->dispatchedAt?->toIso8601String(),
                'confirmed_at' => $p->confirmedAt?->toIso8601String(),
                'created_at' => $p->createdAt->toIso8601String(),
            ])->values(),
        ]);
    }
}
