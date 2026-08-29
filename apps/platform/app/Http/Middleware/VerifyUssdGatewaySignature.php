<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Ussd\UssdGatewaySigner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REQ-SEC-009 — everything under /internal/ussd is machine-to-machine (apps/ussd,
 * not the telco aggregator directly — see UssdGatewayController's doc comment) and
 * still gets a real signature check, not a bare network-position trust. Mirrors
 * VerifyOpayCallback's shape exactly.
 */
class VerifyUssdGatewaySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowlist = array_filter(explode(',', (string) config('ussd.gateway_ip_allowlist')));
        if ($allowlist !== [] && !in_array($request->ip(), $allowlist, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $signature = (string) $request->header('X-Ussd-Signature');
        $signer = app(UssdGatewaySigner::class);
        if ($signature === '' || !$signer->verify($request->getContent(), $signature)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
