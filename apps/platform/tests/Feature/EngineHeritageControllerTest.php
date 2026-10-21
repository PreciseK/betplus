<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Engine\Heritage\HeritageEngine;
use Tests\TestCase;

final class EngineHeritageControllerTest extends TestCase
{
    private array $samplePrizeTable = [
        [
            'name' => 'TIER_JACKPOT',
            'probability_basis_points' => 100,
            'multiplier_hundredths' => 10000,
            'outcome_type' => 'cash',
        ],
        [
            'name' => 'TIER_HIGH',
            'probability_basis_points' => 900,
            'multiplier_hundredths' => 200,
            'outcome_type' => 'cash',
        ],
        [
            'name' => 'TIER_LOSS',
            'probability_basis_points' => 9000,
            'multiplier_hundredths' => 0,
            'outcome_type' => 'none',
        ],
    ];

    public function test_describe_endpoint_returns_engine_metadata(): void
    {
        $response = $this->getJson('/engine/v1/describe');

        $response->assertOk()
            ->assertJson([
                'engine' => 'heritage',
                'engine_version' => HeritageEngine::VERSION,
                'game_code' => 'HERITAGE',
                'board_size' => 9,
                'pick_size' => 5,
            ]);
    }

    public function test_draw_pool_endpoint_draws_five_winning_positions(): void
    {
        $response = $this->postJson('/engine/v1/draw-pool', [
            'seed' => bin2hex(random_bytes(32)),
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'winning_positions',
                'engine_version',
            ]);

        $this->assertCount(5, $response->json('winning_positions'));
    }

    public function test_resolve_endpoint_executes_engine_contract(): void
    {
        $seedHex = bin2hex(random_bytes(32));

        $payload = [
            'ticket_id' => 'test-ticket-123',
            'game_code' => 'HERITAGE',
            'stake_kobo' => 10000,
            'prize_table_version' => 'v1.0.0',
            'prize_table' => $this->samplePrizeTable,
            'seed' => $seedHex,
            'player_input' => [
                'selected_positions' => [0, 1, 2, 3, 4],
                'tradition' => 'yoruba',
                'leader_type' => 'king',
            ],
        ];

        $response = $this->postJson('/engine/v1/resolve', $payload);

        $response->assertOk()
            ->assertJsonStructure([
                'ticket_id',
                'outcome_tier',
                'gross_prize_kobo',
                'engine_state' => [
                    'board',
                    'winning_positions',
                    'selected_positions',
                    'match_count',
                    'tradition',
                    'leader_type',
                ],
                'engine_version',
                'digest',
            ]);

        $this->assertSame('test-ticket-123', $response->json('ticket_id'));
        $this->assertCount(9, $response->json('engine_state.board'));
        $this->assertCount(5, $response->json('engine_state.winning_positions'));
    }

    public function test_replay_endpoint_returns_byte_identical_outcome(): void
    {
        $seedHex = bin2hex(random_bytes(32));

        $payload = [
            'ticket_id' => 'test-ticket-replay',
            'game_code' => 'HERITAGE',
            'stake_kobo' => 20000,
            'prize_table_version' => 'v1.0.0',
            'prize_table' => $this->samplePrizeTable,
            'seed' => $seedHex,
            'player_input' => [
                'selected_positions' => [1, 2, 4, 6, 8],
                'tradition' => 'edo',
                'leader_type' => 'queen',
            ],
        ];

        $res1 = $this->postJson('/engine/v1/resolve', $payload)->json();
        $res2 = $this->postJson('/engine/v1/replay', $payload)->json();

        $this->assertSame($res1, $res2);
    }
}
