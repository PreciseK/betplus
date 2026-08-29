<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\BlackRedController;
use App\Http\Controllers\Api\V1\HeritageController;
use App\Http\Controllers\Api\V1\IdentityVerificationController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\PlayerProfileController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\ResponsiblePlayController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\TicketNotificationController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [RegistrationController::class, 'register']);
    Route::post('/auth/register/verify', [RegistrationController::class, 'verify']);
    Route::post('/auth/register/confirm-identity', [RegistrationController::class, 'confirmIdentity']);
    Route::post('/auth/register/complete', [RegistrationController::class, 'complete']);
    Route::post('/auth/sign-in/complete', [SessionController::class, 'complete']);
    Route::post('/auth/refresh', [SessionController::class, 'refresh']);
    Route::post('/auth/sign-out', [SessionController::class, 'signOut']);

    Route::middleware('auth.token')->group(function () {
        Route::get('/me', [PlayerProfileController::class, 'show']);
        Route::post('/account/deactivate', [PlayerProfileController::class, 'deactivate']);

        Route::post('/identity/verify-nin', [IdentityVerificationController::class, 'verifyNin']);
        Route::post('/identity/verify-bvn', [IdentityVerificationController::class, 'verifyBvn']);

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('/wallet/deposits/quote', [WalletController::class, 'quote']);
        Route::post('/wallet/deposits', [WalletController::class, 'deposit'])->middleware('idempotent');
        Route::post('/wallet/deposits/{id}/otp', [WalletController::class, 'depositOtp'])->middleware('idempotent');

        Route::get('/games/blackred', [BlackRedController::class, 'show']);
        Route::post('/tickets', [BlackRedController::class, 'purchase'])->middleware('idempotent');
        Route::get('/tickets/{reference}/reveal', [BlackRedController::class, 'reveal']);

        // Epic 7 — a second game on its own routes rather than overloading /tickets,
        // since the purchase request shape (selected_positions/tradition/leader_type)
        // is structurally different from BlackRed's (prediction).
        Route::get('/games/heritage', [HeritageController::class, 'show']);
        Route::get('/heritage/catalogue', [HeritageController::class, 'catalogue']);
        Route::post('/heritage/tickets', [HeritageController::class, 'purchase'])->middleware('idempotent');
        Route::get('/heritage/tickets/{reference}/reveal', [HeritageController::class, 'reveal']);

        // Story 8.5 (REQ-USSD-005/REQ-NOT-008) — game-agnostic, USSD-triggered.
        Route::post('/tickets/{reference}/notify-sms', [TicketNotificationController::class, 'notifySms']);

        Route::get('/payouts', [PayoutController::class, 'context']);
        Route::post('/payouts/quote', [PayoutController::class, 'quote']);
        Route::post('/payouts', [PayoutController::class, 'request'])->middleware('idempotent');

        Route::get('/responsible-play', [ResponsiblePlayController::class, 'show']);
        Route::post('/responsible-play/limits', [ResponsiblePlayController::class, 'updateLimit']);
        Route::post('/responsible-play/cool-off', [ResponsiblePlayController::class, 'startCoolOff']);
        Route::post('/responsible-play/self-exclude', [ResponsiblePlayController::class, 'selfExclude']);
        Route::post('/responsible-play/deactivate', [PlayerProfileController::class, 'deactivate']);
    });
});
