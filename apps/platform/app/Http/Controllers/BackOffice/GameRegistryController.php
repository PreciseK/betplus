<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Games\PrizeTable\PrizeTablePresetLibrary;
use App\Domain\Games\PrizeTable\PrizeTablePublicationGate;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\CreatePrizeTableDraftRequest;
use App\Http\Requests\BackOffice\UpdatePrizeTableDraftRequest;
use App\Models\AuditLog;
use App\Models\GameRegistry;
use App\Models\InstitutionUser;
use App\Models\PrizeTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Story 6.8 (REQ-GEC-010/011). Publication itself does not happen here — creating a
 * draft table is not a reviewable action by itself (REQ-BO-003 lists "prize table
 * publication", not "prize table drafting"); the draft is proposed for publication
 * through ReviewableChangeController, which is what actually runs
 * PrizeTablePublicationGate at approval time (Domain/BackOffice/MakerChecker/
 * PrizeTablePublishApplier).
 */
class GameRegistryController extends Controller
{
    public function __construct(
        private readonly PrizeTablePublicationGate $gate,
        private readonly PrizeTablePresetLibrary $presets,
    ) {
    }

    /** GET /backoffice/v1/games */
    public function index(): JsonResponse
    {
        $games = GameRegistry::orderBy('gameCode')->get();

        return response()->json([
            'games' => $games->map(fn (GameRegistry $g) => [
                'game_code' => $g->gameCode,
                'engine_version' => $g->engineVersion,
                'status' => $g->status,
                'min_stake_kobo' => $g->minStakeKobo,
                'max_stake_kobo' => $g->maxStakeKobo,
                'enabled_channels' => $g->enabledChannels,
                'enabled_states' => $g->enabledStates,
            ])->values(),
        ]);
    }

    /** PATCH /backoffice/v1/games/{gameCode} — status/channel/state suspension without a deploy, applies immediately (no maker-checker gate). */
    public function update(Request $request, string $gameCode): JsonResponse
    {
        $game = GameRegistry::where('gameCode', $gameCode)->firstOrFail();
        $before = $game->getAttributes();
        $game->update($request->only(['status', 'enabledChannels', 'enabledStates', 'minStakeKobo', 'maxStakeKobo']));

        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => 'game_registry_updated',
            'targetTable' => 'gameRegistry',
            'targetId' => $game->id,
            'before' => $before,
            'after' => $game->getAttributes(),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);

        return response()->json(['game_code' => $game->gameCode, 'status' => $game->status]);
    }

    /** GET /backoffice/v1/prize-tables?game_code=BLACKRED */
    public function indexPrizeTables(Request $request): JsonResponse
    {
        $tables = PrizeTable::with('tiers')
            ->when($request->query('game_code'), fn ($q, $code) => $q->where('gameCode', $code))
            ->orderByDesc('id')
            ->get();

        return response()->json(['prize_tables' => $tables->map(fn (PrizeTable $t) => $this->prizeTableShape($t))->values()]);
    }

    /**
     * GET /backoffice/v1/prize-tables/presets?game_code=BLACKRED — named starting
     * points (Fair/Good/Best) an admin can pick to pre-fill a draft's tiers, ranked by
     * house revenue ratio. Registered before /prize-tables/{id} in routes/backoffice.php
     * so "presets" isn't swallowed by the {id} wildcard.
     */
    public function presets(Request $request): JsonResponse
    {
        // Only BlackRed's tier shape (positions 1-5, fair odds = 2^n) fits a
        // formula-generated preset menu; Heritage's combinatorial tiers don't.
        if ($request->query('game_code') !== 'BLACKRED') {
            return response()->json(['presets' => []]);
        }

        return response()->json(['presets' => $this->presets->forBlackRed()]);
    }

    /** GET /backoffice/v1/prize-tables/{id} */
    public function showPrizeTable(int $id): JsonResponse
    {
        $table = PrizeTable::with('tiers')->findOrFail($id);

        return response()->json($this->prizeTableShape($table));
    }

    /** POST /backoffice/v1/prize-tables — creates a DRAFT; does not publish. */
    public function createPrizeTable(CreatePrizeTableDraftRequest $request): JsonResponse
    {
        $table = PrizeTable::create([
            'gameCode' => $request->string('game_code')->toString(),
            'stateCode' => $request->input('state_code'),
            'version' => $request->string('version')->toString(),
            'status' => 'draft',
            'effectiveAt' => $request->string('effective_at')->toString(),
            'actuarialCertRef' => $request->input('actuarial_cert_ref'),
        ]);

        foreach ($request->input('tiers') as $tier) {
            $table->tiers()->create([
                'positions' => $tier['positions'],
                'multiplierHundredths' => $tier['multiplier_hundredths'],
                'probabilityNumerator' => $tier['probability_numerator'],
                'probabilityDenominator' => $tier['probability_denominator'],
            ]);
        }
        $table->load('tiers');

        $this->auditPrizeTableChange($request, 'prize_table_draft_created', $table, null);

        // REQ-GEC-024 — computed and displayed BEFORE approval, so a maker sees the
        // gate result while drafting, not only when a checker later rejects it.
        $errors = $this->gate->validate($table, (int) config('tax.withholding.resident_rate_basis_points'));

        return response()->json(array_merge($this->prizeTableShape($table), ['gate_errors' => $errors]));
    }

    /**
     * PATCH /backoffice/v1/prize-tables/{id} — replaces a DRAFT's tiers and metadata.
     * Only status=draft is editable: a published table's tickets already settled
     * against it (REQ-GEC-021) and must never change under them.
     */
    public function updatePrizeTable(UpdatePrizeTableDraftRequest $request, int $id): JsonResponse
    {
        $table = PrizeTable::with('tiers')->findOrFail($id);
        if ($table->status !== 'draft') {
            return response()->json(['message' => 'Only a draft prize table may be edited.'], 409);
        }
        $before = $this->prizeTableShape($table);

        DB::transaction(function () use ($request, $table): void {
            $table->update([
                'version' => $request->string('version')->toString(),
                'effectiveAt' => $request->string('effective_at')->toString(),
                'actuarialCertRef' => $request->input('actuarial_cert_ref'),
            ]);
            $table->tiers()->delete();
            foreach ($request->input('tiers') as $tier) {
                $table->tiers()->create([
                    'positions' => $tier['positions'],
                    'multiplierHundredths' => $tier['multiplier_hundredths'],
                    'probabilityNumerator' => $tier['probability_numerator'],
                    'probabilityDenominator' => $tier['probability_denominator'],
                ]);
            }
        });
        $table->load('tiers');

        $this->auditPrizeTableChange($request, 'prize_table_draft_updated', $table, $before);

        $errors = $this->gate->validate($table, (int) config('tax.withholding.resident_rate_basis_points'));

        return response()->json(array_merge($this->prizeTableShape($table), ['gate_errors' => $errors]));
    }

    /**
     * DELETE /backoffice/v1/prize-tables/{id} — a draft only. Published tables are
     * permanent history (REQ-GEC-021, REQ-BO-006) and are never deleted.
     */
    public function destroyPrizeTable(Request $request, int $id): JsonResponse
    {
        $table = PrizeTable::with('tiers')->findOrFail($id);
        if ($table->status !== 'draft') {
            return response()->json(['message' => 'Only a draft prize table may be deleted.'], 409);
        }
        $before = $this->prizeTableShape($table);

        $table->delete(); // cascades to prizeTableTier via the FK constraint

        $this->auditPrizeTableChange($request, 'prize_table_draft_deleted', $table, $before, deleted: true);

        return response()->json(['id' => $id, 'deleted' => true]);
    }

    /** @param array<string, mixed>|null $before */
    private function auditPrizeTableChange(Request $request, string $action, PrizeTable $table, ?array $before, bool $deleted = false): void
    {
        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => $action,
            'targetTable' => 'prizeTable',
            'targetId' => $table->id,
            'before' => $before,
            'after' => $deleted ? null : $this->prizeTableShape($table),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);
    }

    /** @return array<string, mixed> */
    private function prizeTableShape(PrizeTable $table): array
    {
        return [
            'id' => $table->id,
            'game_code' => $table->gameCode,
            'state_code' => $table->stateCode,
            'version' => $table->version,
            'status' => $table->status,
            'effective_at' => $table->effectiveAt->toIso8601String(),
            'actuarial_cert_ref' => $table->actuarialCertRef,
            'published_at' => $table->publishedAt?->toIso8601String(),
            'tiers' => $table->tiers->map(fn ($t) => [
                'positions' => $t->positions,
                'multiplier_hundredths' => $t->multiplierHundredths,
                'probability_numerator' => $t->probabilityNumerator,
                'probability_denominator' => $t->probabilityDenominator,
            ])->values(),
        ];
    }
}
