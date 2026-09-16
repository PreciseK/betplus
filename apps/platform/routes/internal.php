<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\OpayCallbackController;
use App\Http\Controllers\Internal\UssdGatewayController;
use Illuminate\Support\Facades\Route;

Route::middleware('opay.payout-callback')->group(function () {
    Route::post('/internal/opay/callback/payout', [OpayCallbackController::class, 'payout']);
});

// Story 8.1/8.2 — apps/ussd, never the telco aggregator directly (see
// UssdGatewayController's doc comment).
Route::middleware('ussd.gateway')->group(function () {
    Route::post('/internal/ussd/identify', [UssdGatewayController::class, 'identify']);
    Route::post('/internal/ussd/register/complete', [UssdGatewayController::class, 'registerComplete']);
    Route::post('/internal/ussd/session', [UssdGatewayController::class, 'sessionSync']);
});
