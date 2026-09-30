<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\SmsSender;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Player;
use App\Models\PlayerSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The player-facing "who am I" read the dashboard/greeting needs. Distinct from
 * BackOffice\PlayerProfileController — that one is an operator viewing a masked
 * record for ANY player; this one is a player reading their own, unmasked (it's
 * already their own token, already their own data).
 */
class PlayerProfileController extends Controller
{
    public function __construct(private readonly SmsSender $sms)
    {
    }

    /** GET /v1/me */
    public function show(): JsonResponse
    {
        $player = $this->player();

        return response()->json([
            'registered_name' => $player->registeredName,
            'msisdn' => $player->msisdn,
            'kyc_tier' => $player->kycTier,
            'account_status' => $player->accountStatus,
            'deleted_at' => $player->deletedAt?->toIso8601String(),
        ]);
    }

    /**
     * POST /v1/account/deactivate
     * Two-step deactivation: pass `send_otp: true` to receive a confirmation code,
     * then re-call with `otp_code` to complete. Compliant 6-month cooling-off period
     * and 7-year regulatory data retention (NLRC & AML/CFT).
     */
    public function deactivate(Request $request): JsonResponse
    {
        $player = $this->player();

        if ($player->accountStatus === 'inactive' || $player->accountStatus === 'closed') {
            return response()->json([
                'message' => 'Account is already deactivated.',
                'account_status' => $player->accountStatus,
                'deactivated_at' => $player->deletedAt?->toIso8601String(),
            ]);
        }

        // Step 1: client requests an OTP be sent to their phone.
        if ($request->boolean('send_otp')) {
            $rateLimiterKey = "deactivate-otp-send:{$player->id}";
            if (RateLimiter::tooManyAttempts($rateLimiterKey, 3)) {
                return response()->json(['message' => 'Too many OTP requests. Please wait before trying again.'], 429);
            }
            RateLimiter::hit($rateLimiterKey, 3600);

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            Cache::put("deactivate-confirm:{$player->id}", hash('sha256', $code), 300);
            $this->sms->send($player->msisdn, 'deactivate.confirm.v1', ['code' => $code]);

            return response()->json(['status' => 'otp_sent', 'expires_in' => 300]);
        }

        // Step 2: client supplies the OTP to confirm intent.
        $otpCode = $request->string('otp_code')->toString();
        $verifyKey = "deactivate-otp-verify:{$player->id}";

        if (RateLimiter::tooManyAttempts($verifyKey, 5)) {
            Cache::forget("deactivate-confirm:{$player->id}");
            return response()->json(['message' => 'Too many failed attempts. Request a new confirmation code.'], 429);
        }

        $storedHash = Cache::get("deactivate-confirm:{$player->id}");

        if ($otpCode === '' || $storedHash === null || !hash_equals($storedHash, hash('sha256', $otpCode))) {
            RateLimiter::hit($verifyKey, 300);
            return response()->json(['message' => 'A valid confirmation code is required. Call this endpoint with send_otp: true to receive one.'], 422);
        }

        RateLimiter::clear($verifyKey);
        Cache::forget("deactivate-confirm:{$player->id}");

        $now = now();
        $gracePeriodEndsAt = $now->copy()->addMonths(6);

        // Transition player to inactive status with deletedAt timestamp
        $player->update([
            'accountStatus' => 'inactive',
            'deletedAt' => $now,
        ]);

        // Revoke active sessions & bearer token
        PlayerSession::where('playerId', $player->id)
            ->whereNull('terminatedAt')
            ->update(['terminatedAt' => $now]);

        $token = $request->bearerToken() ?? $request->cookie('access_token');
        if ($token !== null) {
            Cache::forget("access-token:$token");
        }

        // Immutable compliance audit trail
        AuditLog::create([
            'actorType' => 'player',
            'actorId' => $player->id,
            'action' => 'player.account_deactivated',
            'targetTable' => 'player',
            'targetId' => $player->id,
            'before' => ['accountStatus' => 'active'],
            'after' => [
                'accountStatus' => 'inactive',
                'deletedAt' => $now->toIso8601String(),
                'gracePeriodEndsAt' => $gracePeriodEndsAt->toIso8601String(),
            ],
            'reason' => (string) $request->input('reason', 'User requested account deactivation (6-month cooling off / 7-year regulatory retention)'),
            'ipAddress' => $request->ip(),
            'userAgent' => substr((string) $request->userAgent(), 0, 500),
            'createdAt' => $now,
        ]);

        return response()->json([
            'status' => 'deactivated',
            'account_status' => 'inactive',
            'deactivated_at' => $now->toIso8601String(),
            'cooling_off_ends_at' => $gracePeriodEndsAt->toIso8601String(),
            'message' => 'Your account has been deactivated. Records are retained in compliance with NLRC & AML/CFT regulations for 7 years. You may request reactivation via Support within 6 months.',
        ]);
    }

    private function player(): Player
    {
        /** @var Player $player */
        $player = request()->attributes->get('player');

        return $player;
    }
}
