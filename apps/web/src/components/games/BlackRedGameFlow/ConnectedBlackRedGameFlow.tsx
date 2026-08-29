"use client";

import { blackRedGateway } from "@betplus/api-client";
import { BlackRedGameFlow } from "./BlackRedGameFlow";

/**
 * See ConnectedRegistrationFlow for why this wiring happens on the client side.
 *
 * Deliberately no mock fallback on error, for any of the three calls — this is
 * a real-money engine. A failed load shows BlackRedGameFlow's own error state
 * (loadFailed); a failed purchase surfaces a real error message to the player.
 * Silently substituting fabricated outcomes here would mean showing someone a
 * "win" that was never actually settled against their balance.
 */
export function ConnectedBlackRedGameFlow() {
  return <BlackRedGameFlow gateway={blackRedGateway} />;
}

