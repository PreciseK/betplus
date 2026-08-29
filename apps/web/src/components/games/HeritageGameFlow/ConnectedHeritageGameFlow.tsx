"use client";

import { heritageGateway } from "@betplus/api-client";
import { HeritageGameFlow } from "./HeritageGameFlow";

/**
 * See ConnectedRegistrationFlow for why this wiring happens on the client side.
 *
 * No mock fallback on error — same reasoning as ConnectedBlackRedGameFlow. The
 * outcome tier and match count here come from the certified engine via
 * placeTicket(); a fabricated fallback would mean showing an invented result
 * for real money.
 */
export function ConnectedHeritageGameFlow() {
  return <HeritageGameFlow gateway={heritageGateway} />;
}
