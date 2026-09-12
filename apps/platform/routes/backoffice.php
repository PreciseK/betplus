<?php

declare(strict_types=1);

use App\Http\Controllers\BackOffice\AnalyticsController;
use App\Http\Controllers\BackOffice\AuditLogController;
use App\Http\Controllers\BackOffice\BirdEscapeRoundAuditController;
use App\Http\Controllers\BackOffice\CollectionController;
use App\Http\Controllers\BackOffice\CrashConfigController;
use App\Http\Controllers\BackOffice\DailySummaryController;
use App\Http\Controllers\BackOffice\GameRegistryController;
use App\Http\Controllers\BackOffice\HeritageCatalogueController;
use App\Http\Controllers\BackOffice\InstitutionAuthController;
use App\Http\Controllers\BackOffice\InstitutionUserController;
use App\Http\Controllers\BackOffice\JurisdictionController;
use App\Http\Controllers\BackOffice\PayoutManagementController;
use App\Http\Controllers\BackOffice\PlayerProfileController;
use App\Http\Controllers\BackOffice\PlayerProtectionController;
use App\Http\Controllers\BackOffice\ReconciliationController;
use App\Http\Controllers\BackOffice\ReportExportController;
use App\Http\Controllers\BackOffice\ReviewableChangeController;
use App\Http\Controllers\BackOffice\TicketAuditController;
use Illuminate\Support\Facades\Route;

// REQ-BO-014 — a network surface distinct from the player API (/v1). Nothing under
// /backoffice ever shares a route, guard or token namespace with /v1.
Route::prefix('backoffice/v1')->group(function () {
    Route::post('/auth/sign-in', [InstitutionAuthController::class, 'signIn']);
    Route::post('/auth/mfa', [InstitutionAuthController::class, 'verifyMfa']);

    // Story 6.9 / REQ-BO-023 — the download link is bearer-free by design: possession
    // of the signed URL IS the authorization, exactly like every other signed route in
    // Laravel. It deliberately sits outside institution.auth so a link handed to
    // Finance's browser works without a second bearer token attached.
    Route::middleware('signed')->get('/reports/exports/{id}/download', [ReportExportController::class, 'download'])
        ->name('backoffice.reports.download');

    Route::middleware('institution.auth')->group(function () {
        // REQ-BO-004 — Player 360. Support's core task; open to any authenticated role.
        Route::get('/players/{id}', [PlayerProfileController::class, 'show']);

        // REQ-BO-005 — ticket audit/replay tool.
        Route::get('/tickets/{reference}/replay', [TicketAuditController::class, 'replay']);

        // REQ-BO-006 / Story 6.6 — reconciliation exception queue (Finance/Compliance).
        Route::middleware('institution.role:finance,compliance,system_admin')->group(function () {
            Route::get('/reconciliation/exceptions', [ReconciliationController::class, 'index']);
            Route::post('/reconciliation/exceptions/{id}/resolve', [ReconciliationController::class, 'resolve']);
        });

        // Story 6.7 — jurisdiction/licence console (Compliance).
        Route::get('/jurisdictions', [JurisdictionController::class, 'index']);
        Route::middleware('institution.role:compliance,system_admin')->group(function () {
            Route::post('/jurisdictions', [JurisdictionController::class, 'upsert']);
        });

        // Story 6.8 — game registry and prize tables (Game Ops).
        Route::get('/games', [GameRegistryController::class, 'index']);
        Route::get('/prize-tables', [GameRegistryController::class, 'indexPrizeTables']);
        // Registered before the {id} wildcard below — same-verb GET routes match in
        // registration order, so "presets" would otherwise be swallowed as an {id}.
        Route::get('/prize-tables/presets', [GameRegistryController::class, 'presets']);
        Route::get('/prize-tables/{id}', [GameRegistryController::class, 'showPrizeTable']);
        Route::middleware('institution.role:game_ops,system_admin')->group(function () {
            Route::patch('/games/{gameCode}', [GameRegistryController::class, 'update']);
            Route::post('/prize-tables', [GameRegistryController::class, 'createPrizeTable']);
            Route::patch('/prize-tables/{id}', [GameRegistryController::class, 'updatePrizeTable']);
            Route::delete('/prize-tables/{id}', [GameRegistryController::class, 'destroyPrizeTable']);
        });

        // BirdEscape crash config — same maker-checker-gated shape as prize tables above.
        Route::get('/crash-configs', [CrashConfigController::class, 'index']);
        Route::get('/crash-configs/presets', [CrashConfigController::class, 'presets']);
        Route::get('/crash-configs/{id}', [CrashConfigController::class, 'show']);
        Route::get('/birdescape/rounds/{roundNumber}/replay', [BirdEscapeRoundAuditController::class, 'replay']);
        Route::middleware('institution.role:game_ops,system_admin')->group(function () {
            Route::post('/crash-configs', [CrashConfigController::class, 'store']);
            Route::patch('/crash-configs/{id}', [CrashConfigController::class, 'update']);
            Route::delete('/crash-configs/{id}', [CrashConfigController::class, 'destroy']);
        });

        // Story 6.3 — maker-checker. Any role may propose; approval eligibility
        // (REQ-BO-017, not-your-own-change) is enforced in MakerCheckerService itself,
        // not by role — a Game Ops maker and a Game Ops checker are still two people.
        Route::get('/changes', [ReviewableChangeController::class, 'index']);
        Route::post('/changes', [ReviewableChangeController::class, 'propose']);
        Route::post('/changes/{id}/approve', [ReviewableChangeController::class, 'approve']);
        Route::post('/changes/{id}/reject', [ReviewableChangeController::class, 'reject']);

        // Story 6.9 (REQ-BO-007/008) — financial/regulatory reporting, Finance/Compliance.
        Route::middleware('institution.role:finance,compliance,system_admin')->group(function () {
            Route::post('/reports/financial', [ReportExportController::class, 'request']);
            Route::get('/reports/exports/{id}', [ReportExportController::class, 'show']);
        });

        // Story 6.10 (REQ-ANL-006/007) — reads pre-aggregated rollups/funnels only,
        // never raw analyticsEvent rows, open to any authenticated role like Player 360.
        Route::get('/analytics/rollups', [AnalyticsController::class, 'rollups']);
        Route::get('/analytics/funnels', [AnalyticsController::class, 'funnels']);

        // REQ-BO-002 — audit trail read surface (Compliance/system admin).
        Route::middleware('institution.role:compliance,system_admin')->group(function () {
            Route::get('/audit-log', [AuditLogController::class, 'index']);
        });

        // Daily operating summary — open to any authenticated role, same as analytics/Player 360.
        Route::get('/daily-summary', [DailySummaryController::class, 'show']);

        // Player protection overview — Support/Compliance's cross-player RG queues.
        Route::middleware('institution.role:support_agent,support_lead,compliance,system_admin')->group(function () {
            Route::get('/player-protection/reviews', [PlayerProtectionController::class, 'reviews']);
            Route::get('/player-protection/limits', [PlayerProtectionController::class, 'limits']);
            Route::get('/player-protection/exclusions', [PlayerProtectionController::class, 'exclusions']);
            Route::post('/velocity-flags/{id}/resolve', [PlayerProtectionController::class, 'resolveReview']);
        });

        // FocusedManagementConsole's real subset — Deposits/Payouts (Finance/Compliance).
        Route::middleware('institution.role:finance,compliance,system_admin')->group(function () {
            Route::get('/deposits', [CollectionController::class, 'index']);
            Route::get('/payouts', [PayoutManagementController::class, 'index']);
        });

        // Content — Heritage catalogue editing/publishing (content_editor/cultural_reviewer).
        Route::get('/heritage-catalogue', [HeritageCatalogueController::class, 'index']);
        Route::middleware('institution.role:content_editor,cultural_reviewer,system_admin')->group(function () {
            Route::patch('/heritage-catalogue/{itemNumber}', [HeritageCatalogueController::class, 'update']);
            Route::post('/heritage-catalogue/{itemNumber}/publish', [HeritageCatalogueController::class, 'publish']);
        });

        // Users — account management (system_admin only).
        Route::middleware('institution.role:system_admin')->group(function () {
            Route::get('/institution-users', [InstitutionUserController::class, 'index']);
            Route::post('/institution-users', [InstitutionUserController::class, 'store']);
            Route::patch('/institution-users/{id}', [InstitutionUserController::class, 'update']);
        });
    });
});
