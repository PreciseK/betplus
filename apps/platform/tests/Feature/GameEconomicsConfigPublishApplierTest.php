<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\BackOffice\MakerChecker\GameEconomicsConfigPublishApplier;
use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\TotpService;
use App\Models\GameEconomicsConfig;
use App\Models\InstitutionUser;
use App\Models\ReviewableChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class GameEconomicsConfigPublishApplierTest extends TestCase
{
    use RefreshDatabase;

    private function institutionUser(): InstitutionUser
    {
        $secret = app(TotpService::class)->generateSecret();

        return InstitutionUser::create([
            'email' => 'maker' . random_int(10000, 99999) . '@betplus.test',
            'displayName' => 'Test Maker',
            'passwordHash' => password_hash('x', PASSWORD_BCRYPT),
            'role' => 'game_ops',
            'status' => 'active',
            'mfaSecretEncrypted' => app(MfaSecretCipher::class)->encrypt($secret),
            'mfaConfirmedAt' => now(),
        ]);
    }

    public function test_publishes_a_config_that_passes_the_gate(): void
    {
        $maker = $this->institutionUser();
        $config = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-APPLY-1', 'status' => 'draft',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => 300],
            'effectiveAt' => now(),
        ]);
        $change = ReviewableChange::create([
            'changeType' => 'game_economics_config_publish', 'status' => 'AWAITING_APPROVAL',
            'payload' => ['game_economics_config_id' => $config->id], 'beforeSnapshot' => null,
            'makerId' => $maker->id, 'makerJustification' => 'test', 'submittedAt' => now(),
        ]);

        app(GameEconomicsConfigPublishApplier::class)->apply($change);

        $this->assertSame('published', $config->refresh()->status);
        $this->assertNotNull($config->publishedAt);
    }

    public function test_refuses_to_publish_a_config_that_fails_the_gate_at_approval_time(): void
    {
        $maker = $this->institutionUser();
        $config = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-APPLY-2', 'status' => 'draft',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => [], // missing kelly_factor_basis_points
            'effectiveAt' => now(),
        ]);
        $change = ReviewableChange::create([
            'changeType' => 'game_economics_config_publish', 'status' => 'AWAITING_APPROVAL',
            'payload' => ['game_economics_config_id' => $config->id], 'beforeSnapshot' => null,
            'makerId' => $maker->id, 'makerJustification' => 'test', 'submittedAt' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        app(GameEconomicsConfigPublishApplier::class)->apply($change);
    }
}
