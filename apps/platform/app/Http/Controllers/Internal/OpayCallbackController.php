<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Payout\SettlePayoutStatus;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /internal/opay/callback/payout (Betplus_PRD.md §12.4 / OPay Payout API
 * Developer Guide §2.3). Signature/IP verification happens in the route's
 * middleware before this runs.
 *
 * There used to be a .../payin sibling for a Collections callback — removed along
 * with FundingService's invented Collections mechanism (see that class's doc
 * comment). Nothing calls this controller for deposits any more.
 */
class OpayCallbackController extends Controller
{
    public function __construct(
        private readonly SettlePayoutStatus $settlePayout,
    ) {
    }

    /**
     * REQ-PAY-011/REQ-PO-011 — the payout callback reports amount in Naira, unlike the
     * kobo request; that unit is not used here at all, deliberately — the confirmed
     * amount is always read from the payout row this platform created, never trusted
     * from the callback body, so a unit mismatch in the payload can't corrupt the
     * ledger. See PayoutStatusMapper::nairaStringToKobo for the one place that
     * conversion is exercised (comparison logging only).
     */
    public function payout(Request $request): JsonResponse
    {
        $payload = $request->input('payload');
        $merchantOrderNo = is_array($payload) ? (string) ($payload['reference'] ?? '') : '';
        $providerStatus = is_array($payload) ? strtoupper((string) ($payload['status'] ?? '')) : '';
        // §2.3.3's status values are "successful"/"failed" — remapped to the
        // createSingleOrder/queryorder enumeration PayoutStatusMapper already handles.
        $providerStatus = match ($providerStatus) {
            'SUCCESSFUL' => 'SUCCESS',
            'FAILED' => 'FAIL',
            default => $providerStatus,
        };

        // merchantOrderNo is the payout's own id, zero-padded (DispatchPayout::numericOrderNo) — reversible directly.
        $payoutId = ctype_digit($merchantOrderNo) ? (int) ltrim($merchantOrderNo, '0') : 0;
        $payout = $payoutId > 0 ? Payout::find($payoutId) : null;
        if ($payout === null) {
            return response()->json(['message' => 'Unknown payout reference'], 404);
        }

        $this->settlePayout->apply($payout, $providerStatus !== '' ? $providerStatus : 'RETURN');

        return response()->json(['received' => true]);
    }
}
