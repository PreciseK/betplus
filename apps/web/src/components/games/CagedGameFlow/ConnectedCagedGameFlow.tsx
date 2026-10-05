"use client";

import { cagedGateway } from "@betplus/api-client";
import { CagedGameFlow } from "./CagedGameFlow";

/**
 * See ConnectedBlackRedGameFlow for why this wiring happens on the client side.
 *
 * Deliberately no mock fallback on error, for any of the three calls — this is a
 * real-money engine. A failed poll shows CagedGameFlow's own error state; a
 * failed bet or cashout surfaces a real error message to the player. Silently
 * substituting fabricated round state here would mean showing someone a "win" that
 * was never actually settled against their balance.
 */
export function ConnectedCagedGameFlow() {
  return <CagedGameFlow gateway={cagedGateway} />;
}

export const ConnectedBirdEscapeGameFlow = ConnectedCagedGameFlow;
