"use client";

import { responsiblePlayGateway } from "@betplus/api-client";
import { ResponsiblePlayDashboard } from "./ResponsiblePlayDashboard";

/** See ConnectedRegistrationFlow for why this wiring happens on the client side. */
export function ConnectedResponsiblePlayDashboard() {
  return <ResponsiblePlayDashboard gateway={responsiblePlayGateway} />;
}
