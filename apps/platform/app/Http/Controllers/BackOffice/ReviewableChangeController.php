<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\BackOffice\MakerChecker\MakerCheckerService;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\ProposeChangeRequest;
use App\Http\Requests\BackOffice\RejectChangeRequest;
use App\Models\AuditLog;
use App\Models\InstitutionUser;
use App\Models\ReviewableChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Story 6.3 — the ONE shared endpoint set for every reviewable change type
 * (REQ-BO-015). A checker sees risk context via beforeSnapshot/payload (REQ-BO-016);
 * this controller doesn't compute additional risk scoring — that's a UI-layer
 * presentation concern over the same data.
 */
class ReviewableChangeController extends Controller
{
    public function __construct(private readonly MakerCheckerService $makerChecker)
    {
    }

    /** GET /backoffice/v1/changes?status=AWAITING_APPROVAL&change_type=manual_credit_debit */
    public function index(Request $request): JsonResponse
    {
        $changes = ReviewableChange::when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('change_type'), fn ($q, $type) => $q->where('changeType', $type))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['changes' => $changes->map(fn (ReviewableChange $c) => $this->shape($c))->values()]);
    }

    /** POST /backoffice/v1/changes */
    public function propose(ProposeChangeRequest $request): JsonResponse
    {
        try {
            $change = $this->makerChecker->propose(
                $request->string('change_type')->toString(),
                $request->input('payload'),
                $request->input('before_snapshot'),
                $this->institutionUser(),
                $request->string('justification')->toString(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->shape($change), 201);
    }

    /** POST /backoffice/v1/changes/{id}/approve */
    public function approve(int $id): JsonResponse
    {
        $before = ReviewableChange::findOrFail($id);
        $beforeSnapshot = $before->getAttributes();

        try {
            $change = $this->makerChecker->approve($before, $this->institutionUser());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->logDecision('change_approved', $change, $beforeSnapshot);

        return response()->json($this->shape($change));
    }

    /** POST /backoffice/v1/changes/{id}/reject */
    public function reject(int $id, RejectChangeRequest $request): JsonResponse
    {
        $before = ReviewableChange::findOrFail($id);
        $beforeSnapshot = $before->getAttributes();

        try {
            $change = $this->makerChecker->reject($before, $this->institutionUser(), $request->string('reason')->toString());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->logDecision('change_rejected', $change, $beforeSnapshot);

        return response()->json($this->shape($change));
    }

    /**
     * REQ-BO-002 — every operator action, logged generically at the decision level so
     * every change type (prize table publish, manual credit/debit, whatever comes next)
     * is covered without a per-applier AuditLog call.
     *
     * @param array<string, mixed> $beforeSnapshot
     */
    private function logDecision(string $action, ReviewableChange $change, array $beforeSnapshot): void
    {
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $this->institutionUser()->id,
            'action' => $action,
            'targetTable' => 'reviewableChange',
            'targetId' => $change->id,
            'before' => $beforeSnapshot,
            'after' => $change->getAttributes(),
            'ipAddress' => request()->ip(),
            'userAgent' => request()->userAgent(),
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(ReviewableChange $change): array
    {
        return [
            'id' => $change->id,
            'change_type' => $change->changeType,
            'status' => $change->status,
            'payload' => $change->payload,
            'before_snapshot' => $change->beforeSnapshot,
            'maker_id' => $change->makerId,
            'maker_justification' => $change->makerJustification,
            'submitted_at' => $change->submittedAt?->toIso8601String(),
            'checker_id' => $change->checkerId,
            'checker_decision_at' => $change->checkerDecisionAt?->toIso8601String(),
            'rejection_reason' => $change->rejectionReason,
            'applied_at' => $change->appliedAt?->toIso8601String(),
        ];
    }

    private function institutionUser(): InstitutionUser
    {
        /** @var InstitutionUser $user */
        $user = request()->attributes->get('institutionUser');

        return $user;
    }
}
