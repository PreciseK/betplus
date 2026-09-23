<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Payout\SettlePayoutStatus;
use App\Domain\Wallet\FundingService;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Payout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles incoming webhook callbacks from OPay:
 * - POST /internal/opay/callback/payout: Outbound disbursement settlement (RSA-SHA256)
 * - POST /internal/opay/callback/collection: Inbound payment settlement (HMAC-SHA3-512)
 *
 * Signature & IP allowlist verification happens in middleware prior to invocation.
 */
class OpayCallbackController extends Controller
{
    public function __construct(
        private readonly SettlePayoutStatus $settlePayout,
    ) {
    }

    /**
     * POST /internal/opay/callback/payout
     */
    public function payout(Request $request): JsonResponse
    {
        $payload = $request->input('payload');
        $merchantOrderNo = is_array($payload) ? (string) ($payload['reference'] ?? '') : '';
        $providerStatus = is_array($payload) ? strtoupper((string) ($payload['status'] ?? '')) : '';

        $providerStatus = match ($providerStatus) {
            'SUCCESSFUL' => 'SUCCESS',
            'FAILED' => 'FAIL',
            default => $providerStatus,
        };

        $payoutId = ctype_digit($merchantOrderNo) ? (int) ltrim($merchantOrderNo, '0') : 0;
        $payout = $payoutId > 0 ? Payout::find($payoutId) : null;
        if ($payout === null) {
            return response()->json(['message' => 'Unknown payout reference'], 404);
        }

        $this->settlePayout->apply($payout, $providerStatus !== '' ? $providerStatus : 'RETURN');

        return response()->json(['received' => true]);
    }

    /**
     * POST /internal/opay/callback/collection
     */
    public function collection(Request $request, FundingService $funding): JsonResponse
    {
        $payload = $request->input('payload');
        if (!is_array($payload)) {
            return response()->json(['message' => 'Invalid payload format'], 400);
        }

        $reference = (string) ($payload['reference'] ?? '');
        $status = strtoupper((string) ($payload['status'] ?? ''));

        $collection = Collection::where('reference', $reference)->first();
        if ($collection === null) {
            return response()->json(['message' => 'Unknown collection reference'], 404);
        }

        if ($status === 'SUCCESS' || $status === 'SUCCESSFUL') {
            $funding->confirmCollectionPaid($collection);
        } elseif (in_array($status, ['FAIL', 'FAILED', 'CLOSE'], true)) {
            if ($collection->status !== 'paid') {
                $collection->update(['status' => 'failed']);
            }
        }

        return response()->json(['code' => '00000', 'message' => 'SUCCESSFUL']);
    }
}
