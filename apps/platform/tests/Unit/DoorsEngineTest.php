<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Doors\DoorsEngine;
use PHPUnit\Framework\TestCase;

final class DoorsEngineTest extends TestCase
{
    public function test_boards_are_reproducible_and_contain_exactly_one_valid_treasure(): void
    {
        $config = ['board_size' => 20, 'treasures' => [['multiplier' => 3, 'weight_bps' => 5600], ['multiplier' => 5, 'weight_bps' => 2800], ['multiplier' => 2, 'weight_bps' => 1600]]];
        $engine = new DoorsEngine();
        for ($i = 0; $i < 100; $i++) {
            $seed = hash('sha256', "seed-{$i}");
            $a = $engine->board($seed, 'test-reference', $config);
            $this->assertSame($a, $engine->board($seed, 'test-reference', $config));
            $this->assertCount(20, $a['board']);
            $this->assertCount(1, array_filter($a['board']));
            $this->assertContains(array_values(array_filter($a['board']))[0], [2, 3, 5]);
        }
    }
}
