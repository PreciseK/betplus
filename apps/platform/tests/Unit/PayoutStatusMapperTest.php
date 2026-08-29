<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Payout\PayoutStatusMapper;
use PHPUnit\Framework\TestCase;

final class PayoutStatusMapperTest extends TestCase
{
    public function test_all_seven_known_statuses_are_recognised(): void
    {
        $mapper = new PayoutStatusMapper();
        foreach (['INITIAL', 'PENDING', 'CHECKING', 'SUCCESS', 'FAIL', 'CLOSE', 'RETURN'] as $status) {
            $this->assertTrue($mapper->isKnown($status), "$status should be known");
        }
    }

    public function test_an_unrecognised_status_is_not_known_and_maps_to_manual_review_not_failure(): void
    {
        $mapper = new PayoutStatusMapper();

        $this->assertFalse($mapper->isKnown('SOME_NEW_CODE_OPAY_ADDED'));
        $this->assertFalse($mapper->isTerminalFailure('SOME_NEW_CODE_OPAY_ADDED'));
        $this->assertSame('manual-review', $mapper->toDisplayStatus('SOME_NEW_CODE_OPAY_ADDED'));
    }

    public function test_only_success_is_terminal_success(): void
    {
        $mapper = new PayoutStatusMapper();

        $this->assertTrue($mapper->isTerminalSuccess('SUCCESS'));
        foreach (['INITIAL', 'PENDING', 'CHECKING', 'FAIL', 'CLOSE', 'RETURN'] as $status) {
            $this->assertFalse($mapper->isTerminalSuccess($status), "$status must not be terminal success");
        }
    }

    public function test_fail_close_and_return_are_terminal_failure(): void
    {
        $mapper = new PayoutStatusMapper();
        foreach (['FAIL', 'CLOSE', 'RETURN'] as $status) {
            $this->assertTrue($mapper->isTerminalFailure($status));
        }
    }

    /** REQ-QA-014 — requests in kobo, callbacks parsed as Naira; fails if reversed. */
    public function test_naira_callback_string_converts_to_kobo_correctly(): void
    {
        $mapper = new PayoutStatusMapper();

        $this->assertSame(200_000, $mapper->nairaStringToKobo('2000.00'));
        $this->assertSame(100, $mapper->nairaStringToKobo('1.00'));
        $this->assertSame(1, $mapper->nairaStringToKobo('0.01'));
    }

    public function test_naira_conversion_is_not_a_kobo_passthrough(): void
    {
        // A 100x reconciliation error looks exactly like passing the Naira string
        // straight through as if it were already kobo — this pins the correct scale.
        $mapper = new PayoutStatusMapper();

        $this->assertNotSame(2000, $mapper->nairaStringToKobo('2000.00'));
        $this->assertSame(200_000, $mapper->nairaStringToKobo('2000.00'));
    }
}
