"use client";

import { walletGateway, payoutGateway } from "@betplus/api-client";
import { WalletDashboard } from "./WalletDashboard";

/** See ConnectedRegistrationFlow for why this wiring happens on the client side. */
export function ConnectedWalletDashboard({
  initialMode = "idle",
}: {
  initialMode?: "idle" | "funding" | "withdrawal";
}) {
  return (
    <WalletDashboard
      gateway={walletGateway}
      payoutGateway={payoutGateway}
      initialMode={initialMode}
    />
  );
}
