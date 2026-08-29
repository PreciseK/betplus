import { post } from "./http";

/** Thrown by verifyOtp when the number already has an account (Story 1.7 non-disclosure —
 *  only revealed after OTP ownership is proven, never before). */
export class ExistingAccountError extends Error {
  constructor() {
    super("EXISTING_ACCOUNT");
  }
}

function maskPhone(phoneE164: string): string {
  return `${phoneE164.slice(0, 4)}•••${phoneE164.slice(-3)}`;
}

/**
 * Real Story 1.7/1.8 implementation of apps/web's RegistrationGateway
 * (apps/web/src/mocks/registration.ts). Structurally compatible by shape, not by
 * imported type, so this package doesn't depend on apps/web.
 */
export const registrationGateway = {
  async requestOtp(phoneE164: string): Promise<{ challengeId: string; maskedPhone: string }> {
    await post("/auth/register", { msisdn: phoneE164 });
    // The msisdn itself is the natural key through the rest of this flow — the backend
    // has no separate opaque challenge concept (see epics.md Story 1.7).
    return { challengeId: phoneE164, maskedPhone: maskPhone(phoneE164) };
  },

  async verifyOtp(challengeId: string, code: string): Promise<{ verified: true }> {
    const result = await post<{ status: string; next?: string }>("/auth/register/verify", {
      msisdn: challengeId,
      code,
    });
    if (result.status !== "verified") throw new Error("OTP_INVALID_OR_EXPIRED");
    if (result.next === "sign_in") throw new ExistingAccountError();

    return { verified: true };
  },

  async validateOpayWallet(
    phoneE164: string,
  ): Promise<{ status: "found"; registeredName: string } | { status: "not-found" }> {
    const lookup = await post<{ status: string; registered_name?: string }>(
      "/auth/register/confirm-identity",
      { msisdn: phoneE164 },
    );
    if (lookup.status === "no_wallet") return { status: "not-found" };
    if (lookup.status !== "confirm") throw new Error("OPAY_UNAVAILABLE");

    // The account is created here, not on a later "confirm" click — Story 1.8's name
    // confirmation is informational on the client; the OPay name is already
    // authoritative and non-editable (REQ-ID-013), so there is nothing left to gate on.
    await post("/auth/register/complete", { msisdn: phoneE164 });

    return { status: "found", registeredName: lookup.registered_name! };
  },

  async verifyIdentity(
    dateOfBirth: string,
    nin: string,
  ): Promise<
    | { status: "verified"; tier: 1; monthlyDepositLimitKobo: number }
    | { status: "underage" }
  > {
    // No player identifier here — registration.complete already signed the player in
    // (HttpOnly cookie), and this endpoint resolves the player from that session.
    const result = await post<{ status: string; monthly_deposit_limit_kobo?: number }>(
      "/identity/verify-nin",
      { date_of_birth: dateOfBirth, nin },
    );

    if (result.status === "under_age") return { status: "underage" };
    if (result.status !== "verified_tier_1") throw new Error("IDENTITY_UNAVAILABLE");

    return { status: "verified", tier: 1, monthlyDepositLimitKobo: result.monthly_deposit_limit_kobo! };
  },
};
