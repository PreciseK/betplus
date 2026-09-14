<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GameEconomicsConfigMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_game_economics_config_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('gameEconomicsConfig'));
        $this->assertTrue(Schema::hasColumns('gameEconomicsConfig', [
            'id', 'gameCode', 'version', 'status', 'activeModel', 'paramsJson',
            'effectiveAt', 'publishedAt', 'createdAt', 'updatedAt',
        ]));
    }
}
