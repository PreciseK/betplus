<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GameEconomicsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GameEconomicsConfigModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_params_json_round_trips_as_an_array(): void
    {
        $config = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED',
            'version' => 'GEC-TEST-1',
            'status' => 'draft',
            'activeModel' => 'BALANCED_HYBRID',
            'paramsJson' => ['kelly_factor_basis_points' => 300],
            'effectiveAt' => now(),
        ]);

        $fresh = GameEconomicsConfig::findOrFail($config->id);

        $this->assertSame(['kelly_factor_basis_points' => 300], $fresh->paramsJson);
        $this->assertSame('BALANCED_HYBRID', $fresh->activeModel);
    }
}
