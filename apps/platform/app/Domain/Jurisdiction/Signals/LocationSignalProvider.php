<?php

declare(strict_types=1);

namespace App\Domain\Jurisdiction\Signals;

use App\Models\Player;

/**
 * §7.7 (REQ-GEO-002) — real implementations combine GPS, coarse network location, IP
 * geolocation and USSD cell data by documented precedence, and run real VPN/proxy
 * detection. MSISDN prefix is never a valid signal (REQ-GEO-003) — no implementation
 * of this interface may derive stateCode from $player->msisdn.
 */
interface LocationSignalProvider
{
    public function resolve(Player $player): LocationSignal;
}
