export interface SessionGateway {
  requestSignInCode(phoneE164: string): Promise<{ challengeId: string }>;
  verifySignInCode(
    challengeId: string,
    code: string,
  ): Promise<{ player: { displayName: string }; sessionExpiresAt: string }>;
}

const MOCK_CODE = "123456";

/**
 * Temporary Story 1.11 adapter. Replace when api-types publishes the session
 * contract. It intentionally returns no access or refresh token: the production
 * web session must be established by HttpOnly, Secure, SameSite=Lax cookies.
 */
export const mockSessionGateway: SessionGateway = {
  async requestSignInCode(phoneE164) {
    await Promise.resolve();
    return { challengeId: `mock-session-${phoneE164.slice(-4)}` };
  },
  async verifySignInCode(_challengeId, code) {
    await Promise.resolve();
    if (code !== MOCK_CODE) throw new Error("OTP_INVALID_OR_EXPIRED");
    return {
      player: { displayName: "Adaeze" },
      sessionExpiresAt: new Date(Date.now() + 30 * 60 * 1000).toISOString(),
    };
  },
};
