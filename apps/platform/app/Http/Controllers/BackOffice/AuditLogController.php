<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-BO-002 — read surface for the auditLog table (immutable at the DB level, see
 * migration 2026_08_19_000400_add_audit_log_immutability_triggers). Tamper-evidence
 * here is the DB trigger refusing UPDATE/DELETE, not a hash chain — "shipped off-server"
 * is explicitly deferred infra per that migration's own comment, so this endpoint does
 * not claim either.
 */
class AuditLogController extends Controller
{
    /** GET /backoffice/v1/audit-log?actor_id=&action=&date_from=&date_to= */
    public function index(Request $request): JsonResponse
    {
        $events = AuditLog::query()
            ->when($request->query('actor_id'), fn ($q, $actorId) => $q->where('actorId', $actorId))
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('date_from'), fn ($q, $from) => $q->where('createdAt', '>=', $from))
            ->when($request->query('date_to'), fn ($q, $to) => $q->where('createdAt', '<=', $to))
            ->orderByDesc('createdAt')
            ->limit(200)
            ->get();

        return response()->json([
            'events' => $events->map(fn (AuditLog $e) => [
                'id' => $e->id,
                'actor_type' => $e->actorType,
                'actor_id' => $e->actorId,
                'action' => $e->action,
                'target_table' => $e->targetTable,
                'target_id' => $e->targetId,
                'before' => $e->before,
                'after' => $e->after,
                'reason' => $e->reason,
                'ip_address' => $e->ipAddress,
                'user_agent' => $e->userAgent,
                'created_at' => $e->createdAt->toIso8601String(),
            ])->values(),
        ]);
    }
}
