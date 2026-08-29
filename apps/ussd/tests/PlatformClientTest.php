<?php

declare(strict_types=1);

namespace Betplus\Ussd\Tests;

use Betplus\Ussd\PlatformClient;
use PHPUnit\Framework\TestCase;

final class PlatformClientTest extends TestCase
{
    public function testHoldsTheConfiguredBaseUrl(): void
    {
        $client = new PlatformClient('http://127.0.0.1:8000', 'test-secret');
        $this->assertSame('http://127.0.0.1:8000', $client->baseUrl());
    }
}
