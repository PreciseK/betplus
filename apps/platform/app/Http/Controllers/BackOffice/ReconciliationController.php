<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InstitutionUser;
use App\Models\LedgerDiscrepancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 6.6 (REQ-BO-019) — leads with the exceptions, not decorative totals. Wraps
 * the existing ledgerDiscrepancy table (Story 2.7's ReconciliationService); no new
 * detection logic here, only the operator-facing read/resolve surface.
 */
class ReconciliationController extends Controller
{
    /** GET /backoffice/v1/reconciliation/exceptions?status=open */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'open');

        $exceptions = LedgerDiscrepancy::where('status', $status)
            ->orderByDesc('detectedAt')
            ->limit(200)
            ->get();

        return response()->json([
            'exceptions' => $exceptions->map(fn (LedgerDiscrepancy $d) => [
                'id' => $d->id,
                'check_type' => $d->checkType,
                'subject_id' => $d->subjectId,
                'expected_kobo' => $d->expectedKobo,
                'actual_kobo' => $d->actualKobo,
                'difference_kobo' => $d->differenceKobo,
                'severity' => $d->severity,
                'status' => $d->status,
                'detected_at' => $d->detectedAt->toIso8601String(),
                'resolved_at' => $d->resolvedAt?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** POST /backoffice/v1/reconciliation/exceptions/{id}/resolve — applies immediately (no maker-checker gate). */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $discrepancy = LedgerDiscrepancy::findOrFail($id);
        $before = $discrepancy->getAttributes();
        $discrepancy->update(['status' => 'resolved', 'resolvedAt' => now()]);

        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => 'reconciliation_exception_resolved',
            'targetTable' => 'ledgerDiscrepancy',
            'targetId' => $discrepancy->id,
            'before' => $before,
            'after' => $discrepancy->getAttributes(),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);

        return response()->json(['id' => $discrepancy->id, 'status' => $discrepancy->status]);
    }
}
