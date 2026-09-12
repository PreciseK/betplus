"use client";

import { birdEscapeGateway } from "@betplus/api-client";
import { BirdEscapeGameFlow } from "./BirdEscapeGameFlow";

/**
 * See ConnectedBlackRedGameFlow for why this wiring happens on the client side.
 *
 * Deliberately no mock fallback on error, for any of the three calls — this is a
 * real-money engine. A failed poll shows BirdEscapeGameFlow's own error state; a
 * failed bet or cashout surfaces a real error message to the player. Silently
 * substituting fabricated round state here would mean showing someone a "win" that
 * was never actually settled against their balance.
 */
export function ConnectedBirdEscapeGameFlow() {
  return <BirdEscapeGameFlow gateway={birdEscapeGateway} />;
}
