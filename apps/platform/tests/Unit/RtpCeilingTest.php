<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\RtpCeiling;
use Tests\TestCase;

final class RtpCeilingTest extends TestCase
{
    public function test_the_platform_wide_rtp_ceiling_is_8800_basis_points(): void
    {
        $this->assertSame(8_800, RtpCeiling::BASIS_POINTS);
    }
}
