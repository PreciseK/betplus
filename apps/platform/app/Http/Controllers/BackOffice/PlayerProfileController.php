<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Collection;
use App\Models\KycRecord;
use App\Models\Payout;
use App\Models\Player;
use App\Models\SmsLog;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 6.4 — "everything about a player, on one task-oriented page" (REQ-BO-004).
 * Sensitive identifiers stay masked by default (REQ-BO-024): this endpoint returns
 * kycStatus/ninHash-presence only, never a raw NIN/BVN — reading the vault is a
 * separate, individually-audited action (Domain/Identity/Vault/IdentityVaultService),
 * deliberately not folded into this general-purpose view.
 */
class PlayerProfileController extends Controller
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly ProtectionService $protection,
        private readonly RegistryCheckService $registry,
    ) {
    }

    /** GET /backoffice/v1/players/{id} */
    public function show(string $id): JsonResponse
    {
        $player = is_numeric($id) ? Player::find((int) $id) : null;
        if ($player === null) {
            $msisdn = str_starts_with($id, '0') ? '+234' . substr($id, 1) : $id;
            $player = Player::where('msisdn', $msisdn)->first();
        }

        if ($player === null) {
            return response()->json(['message' => 'Player not found.'], 404);
        }

        $wallet = $this->wallet->walletFor($player);

        $tickets = Ticket::with('outcome')->where('playerId', $player->id)->latest('id')->limit(50)->get();
        $payouts = Payout::where('playerId', $player->id)->latest('id')->limit(50)->get();
        $collections = Collection::where('playerId', $player->id)->latest('id')->limit(50)->get();
        $kycRecords = KycRecord::where('playerId', $player->id)->get();
        $notifications = SmsLog::where('msisdn', $player->msisdn)->latest('id')->limit(50)->get();

        $protectionEvent = $this->protection->activeEventFor($player);
        $registryStatus = $player->ninHash !== null ? $this->registry->statusFor($player) : 'no_nin';

        return response()->json([
            'profile' => [
                'id' => $player->id,
                'msisdn' => $player->msisdn,
                'registered_name' => $player->registeredName,
                'display_name' => $player->displayName,
                'kyc_tier' => $player->kycTier,
                'kyc_status' => $player->kycStatus,
                'account_status' => $player->accountStatus,
                'residency_status' => $player->residencyStatus,
                'registration_channel' => $player->registrationChannel,
                'created_at' => $player->createdAt->toIso8601String(),
                'last_login_at' => $player->lastLoginAt?->toIso8601String(),
                'has_verified_nin' => $player->ninHash !== null,
                'has_verified_bvn' => $player->bvnVerifiedAt !== null,
            ],
            'balances' => [
                'play_balance_kobo' => $wallet->playBalanceKobo,
                'winnings_balance_kobo' => $wallet->winningsBalanceKobo,
                'bonus_balance_kobo' => (int) $wallet->bonusBalanceKobo,
            ],
            'rg_status' => [
                'protection' => $protectionEvent === null ? null : [
                    'type' => $protectionEvent->type,
                    'ends_at' => $protectionEvent->endsAt->toIso8601String(),
                ],
                'registry_status' => $registryStatus,
            ],
            'tickets' => $tickets->map(fn (Ticket $t) => [
                'reference' => $t->reference,
                'game_code' => $t->gameCode,
                'stake_kobo' => $t->stakeKobo,
                'status' => $t->status,
                'won' => $t->outcome?->won,
                'net_credit_kobo' => $t->outcome?->netCreditKobo,
                'created_at' => $t->createdAt->toIso8601String(),
            ])->values(),
            'payments' => [
                'deposits' => $collections->map(fn (Collection $c) => [
                    'reference' => $c->reference, 'amount_kobo' => $c->amountKobo,
                    'status' => $c->status, 'created_at' => $c->createdAt->toIso8601String(),
                ])->values(),
                'payouts' => $payouts->map(fn (Payout $p) => [
                    'reference' => $p->reference, 'kind' => $p->kind, 'amount_kobo' => $p->amountKobo,
                    'provider_status' => $p->providerStatus, 'created_at' => $p->createdAt->toIso8601String(),
                ])->values(),
            ],
            'kyc_records' => $kycRecords->map(fn (KycRecord $k) => [
                'id_type' => $k->idType, 'verification_method' => $k->verificationMethod,
                'opay_name_match' => $k->opayNameMatch, 'verified_at' => $k->verifiedAt?->toIso8601String(),
            ])->values(),
            'notification_history' => $notifications->map(fn (SmsLog $s) => [
                'category' => $s->category, 'status' => $s->status,
                'queued_at' => $s->queuedAt->toIso8601String(),
            ])->values(),
        ]);
    }

    /** GET /backoffice/v1/demo-mode */
    public function demoModeStatus(): JsonResponse
    {
        $demoPlayer = Player::where('msisdn', '+2348000000000')->first();
        $wallet = $demoPlayer ? $this->wallet->walletFor($demoPlayer) : null;

        return response()->json([
            'enabled' => $demoPlayer !== null && $demoPlayer->accountStatus === 'active',
            'phone' => '08000000000',
            'msisdn' => '+2348000000000',
            'code' => '123456',
            'balance_kobo' => $wallet?->playBalanceKobo ?? 0,
            'balance_naira' => ($wallet?->playBalanceKobo ?? 0) / 100,
            'account_status' => $demoPlayer?->accountStatus ?? 'inactive',
        ]);
    }

    /** POST /backoffice/v1/demo-mode/toggle */
    public function toggleDemoMode(): JsonResponse
    {
        $demoPlayer = Player::firstOrCreate(
            ['msisdn' => '+2348000000000'],
            [
                'registeredName' => 'Demo Player',
                'displayName' => 'Demo VIP Player',
                'registrationChannel' => 'web',
                'kycTier' => 3,
                'kycStatus' => 'tier3_verified',
                'accountStatus' => 'active',
                'residencyStatus' => 'resident',
            ]
        );

        $wallet = \App\Models\PlayerWallet::firstOrCreate(
            ['playerId' => $demoPlayer->id],
            [
                'playBalanceKobo' => 100000000,
                'winningsBalanceKobo' => 0,
                'bonusBalanceKobo' => 0,
                'version' => 1,
            ]
        );

        if ($demoPlayer->accountStatus === 'active') {
            $demoPlayer->accountStatus = 'inactive';
            $demoPlayer->save();
            $enabled = false;
        } else {
            $demoPlayer->accountStatus = 'active';
            $demoPlayer->save();
            if ($wallet->playBalanceKobo < 100000000) {
                $wallet->playBalanceKobo = 100000000;
                $wallet->save();
            }
            $enabled = true;
        }

        return response()->json([
            'enabled' => $enabled,
            'status' => $demoPlayer->accountStatus,
            'balance_naira' => $wallet->playBalanceKobo / 100,
            'message' => $enabled ? 'Demo mode enabled with ₦1,000,000 balance.' : 'Demo mode disabled.',
        ]);
    }

    /** POST /backoffice/v1/players/{id}/status */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $player = is_numeric($id) ? Player::find((int) $id) : null;
        if ($player === null) {
            $msisdn = str_starts_with($id, '0') ? '+234' . substr($id, 1) : $id;
            $player = Player::where('msisdn', $msisdn)->first();
        }

        if ($player === null) {
            return response()->json(['message' => 'Player not found.'], 404);
        }

        $newStatus = $request->input('status');
        if (!in_array($newStatus, ['active', 'inactive', 'closed', 'suspended'], true)) {
            $newStatus = $player->accountStatus === 'active' ? 'inactive' : 'active';
        }

        $player->accountStatus = $newStatus;
        if ($newStatus === 'inactive' || $newStatus === 'closed') {
            $player->deletedAt = now();
        } else {
            $player->deletedAt = null;
        }
        $player->save();

        return response()->json([
            'id' => $player->id,
            'account_status' => $player->accountStatus,
            'message' => "Player status updated to {$player->accountStatus}.",
        ]);
    }
}
