<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Models\GameEconomicsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EconomicsConfigResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_nothing_is_published(): void
    {
        $this->assertNull(app(EconomicsConfigResolver::class)->resolveFor('BLACKRED'));
    }

    public function test_ignores_drafts_and_returns_the_latest_published_row(): void
    {
        GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-DRAFT', 'status' => 'draft',
            'activeModel' => 'FIXED_RTP', 'paramsJson' => [], 'effectiveAt' => now()->subDay(),
        ]);
        GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-OLD', 'status' => 'published',
            'activeModel' => 'FIXED_RTP', 'paramsJson' => [], 'effectiveAt' => now()->subDays(2),
        ]);
        $newest = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-NEW', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => 300], 'effectiveAt' => now()->subDay(),
        ]);

        $resolved = app(EconomicsConfigResolver::class)->resolveFor('BLACKRED');

        $this->assertSame($newest->id, $resolved->id);
    }
}
