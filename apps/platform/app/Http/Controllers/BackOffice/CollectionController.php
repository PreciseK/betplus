<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\RejectDepositRequest;
use App\Models\AuditLog;
use App\Models\Collection;
use App\Models\InstitutionUser;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Deposits — real Collection rows, no back-office read existed before this. */
class CollectionController extends Controller
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly AnalyticsEventRecorder $analytics,
    ) {
    }

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
            'deposits' => $collections->map(fn (Collection $c) => $this->shape($c, $players->get($c->playerId)))->values(),
        ]);
    }

    /**
     * POST /backoffice/v1/deposits/{id}/approve — applies immediately (no
     * maker-checker gate: a pending_review deposit was flagged by the system's
     * own threshold, not proposed by an institutionUser, so there is no "maker"
     * to require a distinct checker from — same shape as velocity-flag resolution).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');

        $collection = Collection::findOrFail($id);
        if ($collection->status !== 'pending_review') {
            return response()->json(['message' => 'Only a pending_review deposit can be approved.'], 422);
        }
        $before = $collection->getAttributes();

        $player = Player::findOrFail($collection->playerId);
        $this->wallet->creditPlayBalanceFromOpay(
            $player,
            (int) $collection->amountKobo,
            'collection',
            $collection->id,
            (string) config('jurisdiction.stub_state_code'),
        );
        $collection->update(['status' => 'paid', 'paidAt' => now()]);
        $this->analytics->record('deposit_completed', $player, 'backoffice', properties: ['amount_kobo' => $collection->amountKobo]);

        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => 'deposit_approved',
            'targetTable' => 'collection',
            'targetId' => $collection->id,
            'before' => $before,
            'after' => $collection->getAttributes(),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);

        return response()->json($this->shape($collection->refresh(), $player));
    }

    /** POST /backoffice/v1/deposits/{id}/reject — never credits; the reference stays taken. */
    public function reject(RejectDepositRequest $request, int $id): JsonResponse
    {
        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');

        $collection = Collection::findOrFail($id);
        if ($collection->status !== 'pending_review') {
            return response()->json(['message' => 'Only a pending_review deposit can be rejected.'], 422);
        }
        $before = $collection->getAttributes();

        $collection->update(['status' => 'failed']);

        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => 'deposit_rejected',
            'targetTable' => 'collection',
            'targetId' => $collection->id,
            'before' => $before,
            'after' => $collection->getAttributes(),
            'reason' => $request->string('reason')->toString(),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);

        return response()->json($this->shape($collection->refresh(), Player::find($collection->playerId)));
    }

    /** @return array<string, mixed> */
    private function shape(Collection $c, ?Player $player): array
    {
        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'player_id' => $c->playerId,
            'player_reference' => 'BP-' . $c->playerId,
            'registered_name' => $player?->registeredName,
            'amount_kobo' => $c->amountKobo,
            'fee_kobo' => $c->feeKobo,
            'provider_collection_id' => $c->providerCollectionId,
            'status' => $c->status,
            'paid_at' => $c->paidAt?->toIso8601String(),
            'created_at' => $c->createdAt->toIso8601String(),
        ];
    }
}
