<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Payments\Providers\Opay\OpayCollectionSigner;
use App\Models\OpayApiCallLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REQ-PAY-014 — signature and source IP verified; unverifiable callbacks are logged
 * and rejected. Header name for the callback signature is unconfirmed (no Collections
 * API doc) — assumed to match the same Authorization: Bearer {sig} convention OPay
 * uses for requests it receives signed the same way.
 */
class VerifyOpayCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowlist = array_filter(explode(',', (string) config('opay.callback_ip_allowlist')));
        if ($allowlist !== [] && !in_array($request->ip(), $allowlist, true)) {
            $this->rejectAndLog($request, 'ip_not_allowlisted');
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $signature = str_starts_with((string) $request->header('Authorization'), 'Bearer ')
            ? substr((string) $request->header('Authorization'), 7)
            : null;

        $signer = app(OpayCollectionSigner::class);
        if ($signature === null || !$signer->verify($request->getContent(), $signature)) {
            $this->rejectAndLog($request, 'signature_invalid');
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }

    private function rejectAndLog(Request $request, string $reason): void
    {
        OpayApiCallLog::create([
            'endpoint' => $request->path(),
            'signatureScheme' => 'hmac_sha512',
            'reference' => is_string($request->input('reference')) ? $request->input('reference') : null,
            'outcome' => 'rejected_' . $reason,
            'latencyMs' => 0,
            'requestBody' => $request->all(),
            'responseBody' => null,
        ]);
    }
}
