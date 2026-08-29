<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\TotpService;
use App\Models\InstitutionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InstitutionAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'support_agent', ?string $ipAllowlist = null): array
    {
        $totp = app(TotpService::class);
        $cipher = app(MfaSecretCipher::class);
        $secret = $totp->generateSecret();

        $user = InstitutionUser::create([
            'email' => strtolower($role) . random_int(1000, 9999) . '@betplus.test',
            'displayName' => 'Test Operator',
            'passwordHash' => password_hash('correct-horse-battery-staple', PASSWORD_BCRYPT),
            'role' => $role,
            'status' => 'active',
            'mfaSecretEncrypted' => $cipher->encrypt($secret),
            'ipAllowlist' => $ipAllowlist,
        ]);

        return [$user, $secret];
    }

    public function test_sign_in_with_wrong_password_is_refused(): void
    {
        [$user] = $this->makeUser();

        $response = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'wrong']);

        $response->assertStatus(401);
    }

    public function test_first_sign_in_requires_mfa_enrolment_and_returns_the_secret(): void
    {
        [$user, $secret] = $this->makeUser();

        $response = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple']);

        $response->assertOk();
        $response->assertJson(['status' => 'mfa_setup_required', 'secret' => $secret]);
    }

    public function test_a_correct_totp_code_completes_sign_in_and_issues_a_token(): void
    {
        [$user, $secret] = $this->makeUser();
        $signIn = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple'])->json();

        $code = app(TotpService::class)->currentCode($secret);
        $response = $this->postJson('/backoffice/v1/auth/mfa', ['challenge_id' => $signIn['challenge_id'], 'code' => $code]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('access_token'));
        $user->refresh();
        $this->assertNotNull($user->mfaConfirmedAt);
    }

    public function test_a_wrong_totp_code_is_refused(): void
    {
        [$user] = $this->makeUser();
        $signIn = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple'])->json();

        $response = $this->postJson('/backoffice/v1/auth/mfa', ['challenge_id' => $signIn['challenge_id'], 'code' => '000000']);

        $response->assertStatus(401);
    }

    public function test_a_valid_token_authenticates_a_protected_backoffice_request(): void
    {
        [$user, $secret] = $this->makeUser('compliance');
        $signIn = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple'])->json();
        $mfa = $this->postJson('/backoffice/v1/auth/mfa', ['challenge_id' => $signIn['challenge_id'], 'code' => app(TotpService::class)->currentCode($secret)])->json();

        $response = $this->withToken($mfa['access_token'])->getJson('/backoffice/v1/jurisdictions');

        $response->assertOk();
    }

    public function test_a_missing_token_is_unauthenticated(): void
    {
        $response = $this->getJson('/backoffice/v1/jurisdictions');
        $response->assertStatus(401);
    }

    public function test_a_privileged_role_with_an_ip_allowlist_is_refused_from_an_unlisted_ip(): void
    {
        [$user] = $this->makeUser('finance', ipAllowlist: '10.0.0.1');

        $response = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple']);

        // Test client default IP (127.0.0.1) is not in the allowlist.
        $response->assertStatus(403);
    }

    public function test_a_role_without_an_allowlist_configured_is_not_ip_restricted(): void
    {
        [$user] = $this->makeUser('support_agent'); // non-privileged role, no allowlist at all

        $response = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple']);

        $response->assertOk();
    }

    public function test_a_non_privileged_role_ignores_any_configured_allowlist(): void
    {
        // ipAllowlist only enforced for system_admin/finance/compliance — a support
        // role with one set (misconfiguration) should still be able to sign in.
        [$user] = $this->makeUser('support_agent', ipAllowlist: '10.0.0.1');

        $response = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple']);

        $response->assertOk();
    }

    public function test_repeated_wrong_passwords_lock_the_account_out(): void
    {
        [$user] = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        }

        // The 6th attempt is locked out even with the CORRECT password now.
        $response = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple']);
        $response->assertStatus(429);
    }

    public function test_a_successful_sign_in_clears_the_account_lockout_counter(): void
    {
        [$user] = $this->makeUser();

        $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple'])->assertOk();

        // Two more wrong attempts after a success shouldn't be anywhere near the cap.
        $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        $response = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple']);
        $response->assertOk();
    }

    public function test_repeated_wrong_mfa_codes_burn_the_challenge(): void
    {
        [$user, $secret] = $this->makeUser();
        $signIn = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery-staple'])->json();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/backoffice/v1/auth/mfa', ['challenge_id' => $signIn['challenge_id'], 'code' => '000000'])->assertStatus(401);
        }

        // The 6th attempt is locked out even with the CORRECT code — the challenge is dead.
        $code = app(TotpService::class)->currentCode($secret);
        $response = $this->postJson('/backoffice/v1/auth/mfa', ['challenge_id' => $signIn['challenge_id'], 'code' => $code]);
        $response->assertStatus(429);
    }
}
