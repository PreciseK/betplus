<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\ResponsibleGaming\PlayerProtectionOverviewService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InstitutionUser;
use App\Models\VelocityFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerProtectionController extends Controller
{
    public function __construct(private readonly PlayerProtectionOverviewService $overview)
    {
    }

    /** GET /backoffice/v1/player-protection/reviews?status=open */
    public function reviews(Request $request): JsonResponse
    {
        return response()->json(['reviews' => $this->overview->reviews($request->query('status', 'open'))]);
    }

    /** GET /backoffice/v1/player-protection/limits */
    public function limits(): JsonResponse
    {
        return response()->json(['limits' => $this->overview->limitsNearOrAtThreshold()]);
    }

    /** GET /backoffice/v1/player-protection/exclusions */
    public function exclusions(): JsonResponse
    {
        return response()->json(['exclusions' => $this->overview->exclusions()]);
    }

    /** POST /backoffice/v1/velocity-flags/{id}/resolve — applies immediately (no maker-checker gate). */
    public function resolveReview(Request $request, int $id): JsonResponse
    {
        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');

        $flag = VelocityFlag::findOrFail($id);
        $before = $flag->getAttributes();
        $flag->update(['status' => 'resolved', 'resolvedAt' => now(), 'resolvedByInstitutionUserId' => $actor->id]);

        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => 'velocity_flag_resolved',
            'targetTable' => 'velocityFlag',
            'targetId' => $flag->id,
            'before' => $before,
            'after' => $flag->getAttributes(),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);

        return response()->json(['id' => $flag->id, 'status' => $flag->status]);
    }
}
