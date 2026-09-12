<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Games\BirdEscape\CrashConfigPresetLibrary;
use App\Domain\Games\BirdEscape\CrashConfigPublicationGate;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\CreateCrashConfigDraftRequest;
use App\Http\Requests\BackOffice\UpdateCrashConfigDraftRequest;
use App\Models\AuditLog;
use App\Models\CrashConfig;
use App\Models\InstitutionUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BirdEscape's analogue of GameRegistryController's prize-table section. Publication
 * itself does not happen here — a draft is proposed for publication through
 * ReviewableChangeController (change_type='crash_config_publish'), which is what runs
 * CrashConfigPublicationGate at approval time (CrashConfigPublishApplier).
 */
class CrashConfigController extends Controller
{
    public function __construct(
        private readonly CrashConfigPublicationGate $gate,
        private readonly CrashConfigPresetLibrary $presets,
    ) {
    }

    /** GET /backoffice/v1/crash-configs?game_code=BIRDESCAPE */
    public function index(Request $request): JsonResponse
    {
        $configs = CrashConfig::when($request->query('game_code'), fn ($q, $code) => $q->where('gameCode', $code))
            ->orderByDesc('id')
            ->get();

        return response()->json(['crash_configs' => $configs->map(fn (CrashConfig $c) => $this->shape($c))->values()]);
    }

    /**
     * GET /backoffice/v1/crash-configs/presets?game_code=BIRDESCAPE — named starting
     * points (Fair/Good/Best), ranked by house revenue ratio. Registered before
     * /crash-configs/{id} in routes so "presets" isn't swallowed by the {id} wildcard.
     */
    public function presets(Request $request): JsonResponse
    {
        if ($request->query('game_code') !== 'BIRDESCAPE') {
            return response()->json(['presets' => []]);
        }

        return response()->json(['presets' => $this->presets->forBirdEscape()]);
    }

    /** GET /backoffice/v1/crash-configs/{id} */
    public function show(int $id): JsonResponse
    {
        return response()->json($this->shape(CrashConfig::findOrFail($id)));
    }

    /** POST /backoffice/v1/crash-configs — creates a DRAFT; does not publish. */
    public function store(CreateCrashConfigDraftRequest $request): JsonResponse
    {
        $config = CrashConfig::create([
            'gameCode' => $request->string('game_code')->toString(),
            'version' => $request->string('version')->toString(),
            'status' => 'draft',
            'houseEdgeBasisPoints' => $request->integer('house_edge_basis_points'),
            'bettingWindowSeconds' => $request->integer('betting_window_seconds'),
            'postCrashIntervalSeconds' => $request->integer('post_crash_interval_seconds'),
            'growthRateConstant' => $request->integer('growth_rate_constant'),
            'effectiveAt' => $request->string('effective_at')->toString(),
            'actuarialCertRef' => $request->input('actuarial_cert_ref'),
        ]);

        $this->audit($request, 'crash_config_draft_created', $config, null);

        // Computed and displayed BEFORE approval, so a maker sees the gate result
        // while drafting, not only when a checker later rejects it.
        $errors = $this->gate->validate($config);

        return response()->json(array_merge($this->shape($config), ['gate_errors' => $errors]));
    }

    /**
     * PATCH /backoffice/v1/crash-configs/{id} — only status=draft is editable: a
     * published config's rounds already resolved against it and must never change
     * under them (each round snapshots its config fields at creation).
     */
    public function update(UpdateCrashConfigDraftRequest $request, int $id): JsonResponse
    {
        $config = CrashConfig::findOrFail($id);
        if ($config->status !== 'draft') {
            return response()->json(['message' => 'Only a draft crash config may be edited.'], 409);
        }
        $before = $this->shape($config);

        $config->update([
            'version' => $request->string('version')->toString(),
            'houseEdgeBasisPoints' => $request->integer('house_edge_basis_points'),
            'bettingWindowSeconds' => $request->integer('betting_window_seconds'),
            'postCrashIntervalSeconds' => $request->integer('post_crash_interval_seconds'),
            'growthRateConstant' => $request->integer('growth_rate_constant'),
            'effectiveAt' => $request->string('effective_at')->toString(),
            'actuarialCertRef' => $request->input('actuarial_cert_ref'),
        ]);

        $this->audit($request, 'crash_config_draft_updated', $config, $before);

        $errors = $this->gate->validate($config);

        return response()->json(array_merge($this->shape($config), ['gate_errors' => $errors]));
    }

    /** DELETE /backoffice/v1/crash-configs/{id} — a draft only. Published configs are permanent history. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $config = CrashConfig::findOrFail($id);
        if ($config->status !== 'draft') {
            return response()->json(['message' => 'Only a draft crash config may be deleted.'], 409);
        }
        $before = $this->shape($config);

        $config->delete();

        $this->audit($request, 'crash_config_draft_deleted', $config, $before, deleted: true);

        return response()->json(['id' => $id, 'deleted' => true]);
    }

    /** @param array<string, mixed>|null $before */
    private function audit(Request $request, string $action, CrashConfig $config, ?array $before, bool $deleted = false): void
    {
        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => $action,
            'targetTable' => 'crashConfig',
            'targetId' => $config->id,
            'before' => $before,
            'after' => $deleted ? null : $this->shape($config),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(CrashConfig $config): array
    {
        return [
            'id' => $config->id,
            'game_code' => $config->gameCode,
            'version' => $config->version,
            'status' => $config->status,
            'house_edge_basis_points' => $config->houseEdgeBasisPoints,
            'betting_window_seconds' => $config->bettingWindowSeconds,
            'post_crash_interval_seconds' => $config->postCrashIntervalSeconds,
            'growth_rate_constant' => $config->growthRateConstant,
            'effective_at' => $config->effectiveAt->toIso8601String(),
            'actuarial_cert_ref' => $config->actuarialCertRef,
            'published_at' => $config->publishedAt?->toIso8601String(),
        ];
    }
}
