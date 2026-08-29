"use client";

import { walletGateway, payoutGateway } from "@betplus/api-client";
import { ActivityDashboard } from "./ActivityDashboard";

/** See ConnectedRegistrationFlow for why this wiring happens on the client side. */
export function ConnectedActivityDashboard() {
  return <ActivityDashboard gateway={walletGateway} payoutGateway={payoutGateway} />;
}
