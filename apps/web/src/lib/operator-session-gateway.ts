import { backOfficeGateway, BackOfficeApiError } from "@betplus/api-client";
import {
  OperatorSessionError,
  type OperatorRole,
  type OperatorSessionGateway,
} from "@/mocks/operator-session";

let pendingEmail = "operator@betplus.ng";

function maskEmail(email: string) {
  const [name, domain] = email.split("@");
  return `${name.slice(0, 1)}•••@${domain}`;
}

function displayName(email: string) {
  return email
    .split("@")[0]
    .split(/[._-]/)
    .filter(Boolean)
    .map((part) => `${part[0]?.toUpperCase() ?? ""}${part.slice(1)}`)
    .join(" ");
}

function toFrontendRole(role: string): OperatorRole {
  const normalized = role.replaceAll("_", "-");
  const supported: OperatorRole[] = [
    "support-agent", "support-lead", "finance", "compliance", "game-ops",
    "content-editor", "cultural-reviewer", "system-admin", "super-admin",
  ];
  return supported.includes(normalized as OperatorRole) ? normalized as OperatorRole : "support-agent";
}

function toSessionError(error: unknown) {
  if (error instanceof BackOfficeApiError) {
    if (error.status === 401) return new OperatorSessionError("CREDENTIALS_INVALID");
    if (error.status === 403) return new OperatorSessionError("IP_NOT_ALLOWED");
  }
  return error instanceof Error ? error : new Error("OPERATOR_SESSION_FAILED");
}

export const operatorSessionGateway: OperatorSessionGateway = {
  async beginMfa(email, password) {
    pendingEmail = email;
    try {
      const response = await backOfficeGateway.beginMfa(email, password);
      return {
        challengeId: response.challenge_id,
        maskedEmail: maskEmail(email),
        status: response.status,
        secret: response.secret,
      };
    } catch (error) {
      throw toSessionError(error);
    }
  },

  async verifyMfa(challengeId, code) {
    try {
      const response = await backOfficeGateway.verifyMfa(challengeId, code);
      const now = new Date();
      const sessionResult = {
        operator: {
          id: pendingEmail,
          displayName: displayName(pendingEmail),
          email: pendingEmail,
          role: toFrontendRole(response.role),
        },
        mfaVerifiedAt: now.toISOString(),
        expiresAt: new Date(now.getTime() + response.expires_in * 1000).toISOString(),
        approvedNetwork: "Approved institutional network",
      };
      if (typeof window !== "undefined") {
        window.sessionStorage.setItem("betplus.operator.session", JSON.stringify(sessionResult.operator));
      }
      return sessionResult;
    } catch (error) {
      if (error instanceof BackOfficeApiError && error.status === 401) {
        throw new OperatorSessionError("MFA_INVALID_OR_EXPIRED");
      }
      throw toSessionError(error);
    }
  },
};
