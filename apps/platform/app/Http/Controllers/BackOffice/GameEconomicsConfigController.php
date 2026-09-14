<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Games\Economics\GameEconomicsModelGate;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\CreateGameEconomicsConfigDraftRequest;
use App\Http\Requests\BackOffice\UpdateGameEconomicsConfigDraftRequest;
use App\Models\AuditLog;
use App\Models\GameEconomicsConfig;
use App\Models\InstitutionUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Same maker-checker-gated shape as CrashConfigController/GameRegistryController's
 * prize-table section. Publication itself does not happen here — a draft is
 * proposed for publication through ReviewableChangeController
 * (change_type='game_economics_config_publish'), which runs GameEconomicsModelGate
 * at approval time (GameEconomicsConfigPublishApplier).
 */
class GameEconomicsConfigController extends Controller
{
    public function __construct(private readonly GameEconomicsModelGate $gate)
    {
    }

    /** GET /backoffice/v1/game-economics-configs?game_code=BLACKRED */
    public function index(Request $request): JsonResponse
    {
        $configs = GameEconomicsConfig::when($request->query('game_code'), fn ($q, $code) => $q->where('gameCode', $code))
            ->orderByDesc('id')
            ->get();

        return response()->json(['game_economics_configs' => $configs->map(fn (GameEconomicsConfig $c) => $this->shape($c))->values()]);
    }

    /** GET /backoffice/v1/game-economics-configs/{id} */
    public function show(int $id): JsonResponse
    {
        return response()->json($this->shape(GameEconomicsConfig::findOrFail($id)));
    }

    /** POST /backoffice/v1/game-economics-configs — creates a DRAFT; does not publish. */
    public function store(CreateGameEconomicsConfigDraftRequest $request): JsonResponse
    {
        $config = GameEconomicsConfig::create([
            'gameCode' => $request->string('game_code')->toString(),
            'version' => $request->string('version')->toString(),
            'status' => 'draft',
            'activeModel' => $request->string('active_model')->toString(),
            'paramsJson' => $request->input('params', []),
            'effectiveAt' => $request->string('effective_at')->toString(),
        ]);

        $this->audit($request, 'game_economics_config_draft_created', $config, null);

        $errors = $this->gate->validate($config);

        return response()->json(array_merge($this->shape($config), ['gate_errors' => $errors]));
    }

    /** PATCH /backoffice/v1/game-economics-configs/{id} — only status=draft is editable. */
    public function update(UpdateGameEconomicsConfigDraftRequest $request, int $id): JsonResponse
    {
        $config = GameEconomicsConfig::findOrFail($id);
        if ($config->status !== 'draft') {
            return response()->json(['message' => 'Only a draft game economics config may be edited.'], 409);
        }
        $before = $this->shape($config);

        $config->update([
            'version' => $request->string('version')->toString(),
            'activeModel' => $request->string('active_model')->toString(),
            'paramsJson' => $request->input('params', []),
            'effectiveAt' => $request->string('effective_at')->toString(),
        ]);

        $this->audit($request, 'game_economics_config_draft_updated', $config, $before);

        $errors = $this->gate->validate($config);

        return response()->json(array_merge($this->shape($config), ['gate_errors' => $errors]));
    }

    /** DELETE /backoffice/v1/game-economics-configs/{id} — a draft only. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $config = GameEconomicsConfig::findOrFail($id);
        if ($config->status !== 'draft') {
            return response()->json(['message' => 'Only a draft game economics config may be deleted.'], 409);
        }
        $before = $this->shape($config);

        $config->delete();

        $this->audit($request, 'game_economics_config_draft_deleted', $config, $before, deleted: true);

        return response()->json(['id' => $id, 'deleted' => true]);
    }

    /** @param array<string, mixed>|null $before */
    private function audit(Request $request, string $action, GameEconomicsConfig $config, ?array $before, bool $deleted = false): void
    {
        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => $action,
            'targetTable' => 'gameEconomicsConfig',
            'targetId' => $config->id,
            'before' => $before,
            'after' => $deleted ? null : $this->shape($config),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(GameEconomicsConfig $config): array
    {
        return [
            'id' => $config->id,
            'game_code' => $config->gameCode,
            'version' => $config->version,
            'status' => $config->status,
            'active_model' => $config->activeModel,
            'params' => $config->paramsJson,
            'effective_at' => $config->effectiveAt->toIso8601String(),
            'published_at' => $config->publishedAt?->toIso8601String(),
        ];
    }
}
