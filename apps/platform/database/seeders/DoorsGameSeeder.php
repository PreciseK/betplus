<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Games\Doors\DoorsEngine;
use App\Models\GameEconomicsConfig;
use App\Models\GameRegistry;
use Illuminate\Database\Seeder;

class DoorsGameSeeder extends Seeder
{
    public function run(): void
    {
        GameRegistry::firstOrCreate(['gameCode' => 'DOORS'], [
            'engineVersion' => DoorsEngine::VERSION, 'status' => 'SUSPENDED',
            'minStakeKobo' => 20_000, 'maxStakeKobo' => 50_000,
            'enabledChannels' => ['web'], 'enabledStates' => ['LAG'],
        ]);
        // Draft only. No seeder publishes odds, claims certification, or enables wagers.
        GameEconomicsConfig::firstOrCreate(['gameCode' => 'DOORS', 'version' => 'DOORS-DRAFT-3'], [
            'status' => 'draft', 'activeModel' => 'BALANCED_HYBRID', 'effectiveAt' => now(),
            'paramsJson' => [
                'kelly_factor_basis_points' => 100, 'reserve_siphon_bps' => 2000,
                'float_max_age_seconds' => 120, 'reserve_floor_kobo' => 1_000_000,
                'doors' => ['board_size' => 20, 'max_attempts' => 5, 'durations_seconds' => [10],
                    'certification_ref' => 'PENDING-DRAFT-ONLY',
                    'treasures' => [
                        ['code' => 'GOLD', 'multiplier' => 3, 'weight_bps' => 5600],
                        ['code' => 'CHEST', 'multiplier' => 5, 'weight_bps' => 2800],
                        ['code' => 'CARD', 'multiplier' => 2, 'weight_bps' => 1600],
                    ],
                ],
            ],
        ]);
    }
}
