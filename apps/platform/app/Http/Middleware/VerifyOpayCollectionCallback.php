<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Payments\Providers\Opay\OpayCollectionSigner;
use App\Models\OpayApiCallLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REQ-PAY-014: Verifies incoming OPay Collection callbacks.
 * Checks IP allowlist and validates HMAC-SHA3-512 signature using OpayCollectionSigner.
 */
class VerifyOpayCollectionCallback
{
    public function __construct(private readonly OpayCollectionSigner $signer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $allowlist = array_filter(explode(',', (string) config('opay.callback_ip_allowlist')));
        if ($allowlist !== [] && !in_array($request->ip(), $allowlist, true)) {
            $this->rejectAndLog($request, 'ip_not_allowlisted');

            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->input('payload');
        $sha512 = $request->string('sha512')->toString();

        if (!is_array($payload) || $sha512 === '' || !$this->signer->verifyCallback($payload, $sha512)) {
            $this->rejectAndLog($request, 'signature_invalid');

            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }

    private function rejectAndLog(Request $request, string $reason): void
    {
        $payload = $request->input('payload');

        OpayApiCallLog::create([
            'endpoint' => $request->path(),
            'signatureScheme' => 'hmac_sha3_512',
            'reference' => is_array($payload) && is_string($payload['reference'] ?? null) ? $payload['reference'] : null,
            'outcome' => 'rejected_' . $reason,
            'latencyMs' => 0,
            'requestBody' => $request->all(),
            'responseBody' => null,
        ]);
    }
}
