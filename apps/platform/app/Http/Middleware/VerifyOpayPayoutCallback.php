<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Payments\Providers\Opay\OpayPayoutCallbackVerifier;
use App\Models\OpayApiCallLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** REQ-PAY-014 for the payout callback — see OpayPayoutCallbackVerifier's doc comment. */
class VerifyOpayPayoutCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowlist = array_filter(explode(',', (string) config('opay.callback_ip_allowlist')));
        if ($allowlist !== [] && !in_array($request->ip(), $allowlist, true)) {
            $this->rejectAndLog($request, 'ip_not_allowlisted');

            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->input('payload');
        $sha512 = $request->string('sha512')->toString();
        $verifier = app(OpayPayoutCallbackVerifier::class);

        if (!is_array($payload) || $sha512 === '' || !$verifier->verify($payload, $sha512)) {
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
            'signatureScheme' => 'hmac_sha512',
            'reference' => is_array($payload) && is_string($payload['reference'] ?? null) ? $payload['reference'] : null,
            'outcome' => 'rejected_' . $reason,
            'latencyMs' => 0,
            'requestBody' => $request->all(),
            'responseBody' => null,
        ]);
    }
}
