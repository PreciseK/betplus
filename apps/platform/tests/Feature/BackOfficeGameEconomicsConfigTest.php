<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\TotpService;
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Models\GameEconomicsConfig;
use App\Models\InstitutionUser;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackOfficeGameEconomicsConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
    }

    private function institutionToken(string $role): string
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = InstitutionUser::create([
            'email' => strtolower($role) . random_int(1000, 9999) . '@betplus.test',
            'displayName' => 'Operator', 'passwordHash' => password_hash('x', PASSWORD_BCRYPT),
            'role' => $role, 'status' => 'active',
            'mfaSecretEncrypted' => app(MfaSecretCipher::class)->encrypt($secret),
        ]);
        $signIn = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'x'])->json();
        $mfa = $this->postJson('/backoffice/v1/auth/mfa', ['challenge_id' => $signIn['challenge_id'], 'code' => app(TotpService::class)->currentCode($secret)])->json();

        return $mfa['access_token'];
    }

    private function draftPayload(string $version, string $activeModel = 'BALANCED_HYBRID', array $params = ['kelly_factor_basis_points' => 300]): array
    {
        return [
            'game_code' => 'BLACKRED', 'version' => $version, 'active_model' => $activeModel,
            'params' => $params, 'effective_at' => now()->toDateString(),
        ];
    }

    public function test_creating_a_draft_reports_gate_errors_without_publishing(): void
    {
        $token = $this->institutionToken('game_ops');

        $response = $this->withToken($token)->postJson('/backoffice/v1/game-economics-configs', $this->draftPayload('DRAFT-1', 'BALANCED_HYBRID', []));

        $response->assertOk();
        $this->assertSame('draft', $response->json('status'));
        $this->assertNotEmpty($response->json('gate_errors'));
    }

    public function test_a_valid_draft_can_be_proposed_and_approved_for_publication(): void
    {
        $maker = $this->institutionToken('game_ops');
        $draft = $this->withToken($maker)->postJson('/backoffice/v1/game-economics-configs', $this->draftPayload('DRAFT-2'))->json();
        $this->assertEmpty($draft['gate_errors']);

        $proposeResponse = $this->withToken($maker)->postJson('/backoffice/v1/changes', [
            'change_type' => 'game_economics_config_publish',
            'payload' => ['game_economics_config_id' => $draft['id']],
            'justification' => 'Switching BlackRed to Balanced Hybrid.',
        ]);
        $proposeResponse->assertStatus(201);

        $checker = $this->institutionToken('finance');
        $this->withToken($checker)->postJson("/backoffice/v1/changes/{$proposeResponse->json('id')}/approve")->assertOk();

        $this->assertDatabaseHas('gameEconomicsConfig', ['id' => $draft['id'], 'status' => 'published']);
        $resolved = app(EconomicsConfigResolver::class)->resolveFor('BLACKRED');
        $this->assertSame('BALANCED_HYBRID', $resolved->activeModel);
    }

    public function test_a_published_config_can_neither_be_edited_nor_deleted(): void
    {
        $config = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'PUBLISHED-1', 'status' => 'published',
            'activeModel' => 'FIXED_RTP', 'paramsJson' => [], 'effectiveAt' => now(), 'publishedAt' => now(),
        ]);
        $token = $this->institutionToken('game_ops');

        $this->withToken($token)->patchJson("/backoffice/v1/game-economics-configs/{$config->id}", $this->draftPayload('PUBLISHED-1'))->assertStatus(409);
        $this->withToken($token)->deleteJson("/backoffice/v1/game-economics-configs/{$config->id}")->assertStatus(409);
    }

    public function test_draft_create_and_delete_each_write_a_real_audit_log_entry(): void
    {
        $token = $this->institutionToken('game_ops');
        $draft = $this->withToken($token)->postJson('/backoffice/v1/game-economics-configs', $this->draftPayload('DRAFT-AUDIT-1'))->json();
        $this->assertDatabaseHas('auditLog', ['action' => 'game_economics_config_draft_created', 'targetTable' => 'gameEconomicsConfig', 'targetId' => $draft['id']]);

        $this->withToken($token)->deleteJson("/backoffice/v1/game-economics-configs/{$draft['id']}");
        $this->assertDatabaseHas('auditLog', ['action' => 'game_economics_config_draft_deleted', 'targetTable' => 'gameEconomicsConfig', 'targetId' => $draft['id']]);
    }
}
