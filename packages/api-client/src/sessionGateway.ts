import { post } from "./http";

/**
 * Real Story 1.11 implementation of apps/web's SessionGateway
 * (apps/web/src/mocks/session.ts). Never surfaces a token to the caller — the session
 * is the HttpOnly, Secure, SameSite=Lax cookie the backend sets on this same response
 * (REQ-ID-026); nothing here touches localStorage/sessionStorage.
 */
export const sessionGateway = {
  async requestSignInCode(phoneE164: string): Promise<{ challengeId: string }> {
    // Same endpoint as registration's requestOtp — the response is deliberately
    // identical whether or not an account exists (Story 1.7 non-disclosure), so
    // sign-in and registration share it rather than risk two divergent code paths.
    await post("/auth/register", { msisdn: phoneE164 });
    return { challengeId: phoneE164 };
  },

  async verifySignInCode(
    challengeId: string,
    code: string,
  ): Promise<{ player: { displayName: string }; sessionExpiresAt: string }> {
    const verify = await post<{ status: string; next?: string }>("/auth/register/verify", {
      msisdn: challengeId,
      code,
    });
    if (verify.status !== "verified") throw new Error("OTP_INVALID_OR_EXPIRED");
    if (verify.next !== "sign_in") throw new Error("NO_ACCOUNT");

    const result = await post<{ status: string; registered_name?: string; expires_in?: number }>(
      "/auth/sign-in/complete",
      { msisdn: challengeId },
    );
    if (result.status !== "signed_in") throw new Error("SIGN_IN_FAILED");

    return {
      player: { displayName: result.registered_name ?? "" },
      sessionExpiresAt: new Date(Date.now() + (result.expires_in ?? 1800) * 1000).toISOString(),
    };
  },

  async signOut(): Promise<void> {
    await post("/auth/sign-out", {});
  },
};
