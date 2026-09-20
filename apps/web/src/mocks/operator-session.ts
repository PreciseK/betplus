export const OPERATOR_ROLES = [
  "support-agent",
  "support-lead",
  "finance",
  "compliance",
  "game-ops",
  "content-editor",
  "cultural-reviewer",
  "system-admin",
  "super-admin",
] as const;

export type OperatorRole = (typeof OPERATOR_ROLES)[number];

export interface OperatorIdentity {
  id: string;
  displayName: string;
  email: string;
  role: OperatorRole;
}

export interface OperatorSession {
  operator: OperatorIdentity;
  mfaVerifiedAt: string;
  expiresAt: string;
  approvedNetwork: string;
}

export interface OperatorSessionGateway {
  beginMfa(email: string, password: string): Promise<{
    challengeId: string;
    maskedEmail: string;
  }>;
  verifyMfa(challengeId: string, code: string): Promise<OperatorSession>;
}

export type OperatorSessionErrorCode =
  | "CREDENTIALS_INVALID"
  | "IP_NOT_ALLOWED"
  | "MFA_INVALID_OR_EXPIRED";

export class OperatorSessionError extends Error {
  constructor(public readonly code: OperatorSessionErrorCode) {
    super(code);
  }
}

const MOCK_MFA_CODE = "123456";

/**
 * Temporary Story 6.1 adapter. No operator auth contract exists in api-types yet.
 * This mock deliberately returns no token and must be replaced by the separately
 * guarded back-office session once the backend publishes its contract.
 */
export const mockOperatorSessionGateway: OperatorSessionGateway = {
  async beginMfa(email, password) {
    await Promise.resolve();
    if (email.startsWith("blocked.")) {
      throw new OperatorSessionError("IP_NOT_ALLOWED");
    }
    const lower = email.toLowerCase();
    if ((!lower.endsWith("@betplus.com.ng") && !lower.endsWith("@betplus.ng")) || password.length < 8) {
      throw new OperatorSessionError("CREDENTIALS_INVALID");
    }
    const [localPart, domain] = email.split("@");
    return {
      challengeId: `mock-operator-${localPart}`,
      maskedEmail: `${localPart.slice(0, 1)}•••@${domain}`,
    };
  },

  async verifyMfa(_challengeId, code) {
    await Promise.resolve();
    if (code !== MOCK_MFA_CODE) {
      throw new OperatorSessionError("MFA_INVALID_OR_EXPIRED");
    }
    return {
      operator: {
        id: "op_support_lead_01",
        displayName: "Amaka Okafor",
        email: "amaka.okafor@betplus.ng",
        role: "support-lead",
      },
      mfaVerifiedAt: "2026-08-18T13:15:00.000Z",
      expiresAt: "2026-08-18T13:45:00.000Z",
      approvedNetwork: "Lagos operations VPN",
    };
  },
};
