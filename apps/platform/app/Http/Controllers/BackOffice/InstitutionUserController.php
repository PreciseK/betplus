<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\TotpService;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\CreateInstitutionUserRequest;
use App\Models\AuditLog;
use App\Models\InstitutionUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Users — the same enrolment InstitutionAuthController's `backoffice:create-user`
 * Artisan command does (Story 6.1's only path until now), reached over HTTP. Real
 * fields only; no capability/permission table exists (see Roles below).
 */
class InstitutionUserController extends Controller
{
    private const VALID_ROLES = ['support_agent', 'support_lead', 'finance', 'compliance', 'game_ops', 'content_editor', 'cultural_reviewer', 'system_admin', 'super_admin'];

    public function __construct(
        private readonly TotpService $totp,
        private readonly MfaSecretCipher $cipher,
    ) {
    }

    /** GET /backoffice/v1/institution-users */
    public function index(): JsonResponse
    {
        $users = InstitutionUser::orderBy('displayName')->get();

        return response()->json(['users' => $users->map(fn (InstitutionUser $u) => $this->shape($u))->values()]);
    }

    /**
     * POST /backoffice/v1/institution-users — the one-time password and TOTP secret are
     * returned ONLY in this response, exactly like the CLI prints them once; neither is
     * ever stored or retrievable again (passwordHash/mfaSecretEncrypted are $hidden).
     */
    public function store(CreateInstitutionUserRequest $request): JsonResponse
    {
        $password = bin2hex(random_bytes(12));
        $secret = $this->totp->generateSecret();

        $user = InstitutionUser::create([
            'email' => $request->string('email')->toString(),
            'displayName' => $request->string('display_name')->toString(),
            'passwordHash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => $request->string('role')->toString(),
            'status' => 'active',
            'mfaSecretEncrypted' => $this->cipher->encrypt($secret),
        ]);

        $this->logChange('institution_user_created', $user, null, $user->getAttributes());

        return response()->json(array_merge($this->shape($user), [
            'one_time_password' => $password,
            'totp_secret' => $secret,
        ]), 201);
    }

    /** PATCH /backoffice/v1/institution-users/{id} — status (suspend/reactivate) or role. */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = InstitutionUser::findOrFail($id);
        $before = $user->getAttributes();

        $changes = $request->only(['status', 'role']);
        if (isset($changes['role']) && !in_array($changes['role'], self::VALID_ROLES, true)) {
            return response()->json(['message' => 'Invalid role.'], 422);
        }
        if (isset($changes['status']) && !in_array($changes['status'], ['active', 'suspended'], true)) {
            return response()->json(['message' => 'Invalid status.'], 422);
        }

        $user->update($changes);
        $this->logChange('institution_user_updated', $user, $before, $user->getAttributes());

        return response()->json($this->shape($user));
    }

    /** @return array<string, mixed> */
    private function shape(InstitutionUser $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->displayName,
            'role' => $user->role,
            'status' => $user->status,
            'mfa_confirmed_at' => $user->mfaConfirmedAt?->toIso8601String(),
            'last_login_at' => $user->lastLoginAt?->toIso8601String(),
            'created_at' => $user->createdAt->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $after
     */
    private function logChange(string $action, InstitutionUser $subject, ?array $before, array $after): void
    {
        /** @var InstitutionUser $actor */
        $actor = request()->attributes->get('institutionUser');
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => $action,
            'targetTable' => 'institutionUser',
            'targetId' => $subject->id,
            'before' => $before,
            'after' => $after,
            'ipAddress' => request()->ip(),
            'userAgent' => request()->userAgent(),
        ]);
    }
}
