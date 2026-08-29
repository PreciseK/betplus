export interface RegistrationGateway {
  requestOtp(phoneE164: string): Promise<{ challengeId: string; maskedPhone: string }>;
  verifyOtp(challengeId: string, code: string): Promise<{ verified: true }>;
  validateOpayWallet(phoneE164: string): Promise<
    | { status: "found"; registeredName: string }
    | { status: "not-found" }
  >;
  verifyIdentity(dateOfBirth: string, nin: string): Promise<
    | { status: "verified"; tier: 1; monthlyDepositLimitKobo: number }
    | { status: "underage" }
  >;
}

const MOCK_CODE = "123456";

/** Temporary Story 1.7 adapter. Replace when api-types publishes the registration contract. */
export function createMockRegistrationGateway(opayState: "found" | "not-found" | "unavailable" = "found"): RegistrationGateway {
  return {
  async requestOtp(phoneE164) {
    await Promise.resolve();
    return { challengeId: `mock-${phoneE164.slice(-4)}`, maskedPhone: phoneE164 };
  },
  async verifyOtp(_challengeId, code) {
    await Promise.resolve();
    if (code !== MOCK_CODE) throw new Error("OTP_INVALID_OR_EXPIRED");
    return { verified: true };
  },
  async validateOpayWallet() {
    await Promise.resolve();
    if (opayState === "unavailable") throw new Error("OPAY_UNAVAILABLE");
    if (opayState === "not-found") return { status: "not-found" };
    return { status: "found", registeredName: "Adaeze Okafor" };
  },
  async verifyIdentity(dateOfBirth, nin) {
    await Promise.resolve();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateOfBirth) || !/^\d{11}$/.test(nin)) throw new Error("IDENTITY_INVALID");
    const birthDate = new Date(`${dateOfBirth}T00:00:00Z`);
    const today = new Date();
    let age = today.getUTCFullYear() - birthDate.getUTCFullYear();
    const birthdayPassed = today.getUTCMonth() > birthDate.getUTCMonth() || (today.getUTCMonth() === birthDate.getUTCMonth() && today.getUTCDate() >= birthDate.getUTCDate());
    if (!birthdayPassed) age -= 1;
    if (age < 18) return { status: "underage" };
    return { status: "verified", tier: 1, monthlyDepositLimitKobo: 20_000_000 };
  },
  };
}

export const mockRegistrationGateway = createMockRegistrationGateway();
