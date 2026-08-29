<?php

declare(strict_types=1);

namespace App\Domain\Jurisdiction\Signals;

use App\Models\Player;

/**
 * No IP-intelligence/geolocation vendor is contracted yet (mirrors the identity-vendor
 * situation — see config/identityVendor.php). Returns a fixed, configured state and
 * confidence so the rest of the attribution pipeline (licence footprint, exclusion
 * registry gate, confidence threshold) is genuinely exercised end to end in dev and
 * staging. Never detects VPN/proxy — REQ-GEO-006 has no real implementation here.
 */
final class StubLocationSignalProvider implements LocationSignalProvider
{
    public function resolve(Player $player): LocationSignal
    {
        return new LocationSignal(
            stateCode: (string) config('jurisdiction.stub_state_code'),
            confidence: (float) config('jurisdiction.stub_confidence'),
            vpnOrProxyDetected: false,
        );
    }
}
