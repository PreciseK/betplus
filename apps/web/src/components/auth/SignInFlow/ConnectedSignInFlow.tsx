"use client";

import { sessionGateway } from "@betplus/api-client";
import { SignInFlow } from "./SignInFlow";

/** See ConnectedRegistrationFlow for why this wiring happens on the client side. */
export function ConnectedSignInFlow() {
  return <SignInFlow gateway={sessionGateway} />;
}
