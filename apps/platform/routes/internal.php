<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\OpayCallbackController;
use App\Http\Controllers\Internal\UssdGatewayController;
use App\Http\Middleware\VerifyOpayCollectionCallback;
use Illuminate\Support\Facades\Route;

Route::middleware('opay.payout-callback')->group(function () {
    Route::post('/internal/opay/callback/payout', [OpayCallbackController::class, 'payout']);
});

Route::middleware(VerifyOpayCollectionCallback::class)->group(function () {
    Route::post('/internal/opay/callback/collection', [OpayCallbackController::class, 'collection']);
});

// Story 8.1/8.2 — apps/ussd, never the telco aggregator directly (see
// UssdGatewayController's doc comment).
Route::middleware('ussd.gateway')->group(function () {
    Route::post('/internal/ussd/identify', [UssdGatewayController::class, 'identify']);
    Route::post('/internal/ussd/register/complete', [UssdGatewayController::class, 'registerComplete']);
    Route::post('/internal/ussd/session', [UssdGatewayController::class, 'sessionSync']);
});

// Engine Contract endpoints (PRD §6.2) — native PHP implementation of the Heritage engine
Route::post('/engine/v1/resolve', [\App\Http\Controllers\Api\V1\EngineHeritageController::class, 'resolve']);
Route::post('/engine/v1/replay', [\App\Http\Controllers\Api\V1\EngineHeritageController::class, 'replay']);
Route::post('/engine/v1/draw-pool', [\App\Http\Controllers\Api\V1\EngineHeritageController::class, 'drawPool']);
Route::get('/engine/v1/describe', [\App\Http\Controllers\Api\V1\EngineHeritageController::class, 'describe']);
