"use client";

import { useRouter } from "next/navigation";
import { registrationGateway, ExistingAccountError } from "@betplus/api-client";
import { RegistrationFlow } from "./RegistrationFlow";
import type { RegistrationGateway } from "@/mocks/registration";

/**
 * Wires the real registrationGateway in from a client component — a Server Component
 * (register/page.tsx, which needs the `metadata` export) can't pass functions as props
 * to a Client Component, so the gateway has to be constructed on this side of that
 * boundary rather than imported and passed down from the page.
 */
export function ConnectedRegistrationFlow() {
  const router = useRouter();

  const gateway: RegistrationGateway = {
    ...registrationGateway,
    async verifyOtp(challengeId, code) {
      try {
        return await registrationGateway.verifyOtp(challengeId, code);
      } catch (error) {
        // Non-disclosure means this can only be known after OTP ownership is proven
        // (Story 1.7) — route to sign-in rather than continuing registration.
        if (error instanceof ExistingAccountError) router.push("/login");
        throw error;
      }
    },
  };

  return <RegistrationFlow gateway={gateway} />;
}
