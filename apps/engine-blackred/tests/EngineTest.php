<?php

declare(strict_types=1);

namespace Betplus\EngineBlackred\Tests;

use Betplus\EngineBlackred\Engine;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    public function testDescribeIdentifiesTheEngine(): void
    {
        $this->assertSame('blackred', (new Engine())->describe()['engine']);
    }
}
