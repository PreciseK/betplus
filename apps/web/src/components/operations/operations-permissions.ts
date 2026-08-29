import type { OperatorRole } from "@/mocks/operator-session";

export const OPERATIONS_CAPABILITIES = [
  "overview.view",
  "players.read",
  "tickets.read",
  "money.read",
  "payout-float.read",
  "responsible-play.read",
  "jurisdictions.read",
  "games.manage",
  "content.manage",
  "reports.read",
  "analytics.read",
  "audit.read",
  "users.manage",
  "approvals.review",
] as const;

export type OperationsCapability = (typeof OPERATIONS_CAPABILITIES)[number];

const allCapabilities: readonly OperationsCapability[] = OPERATIONS_CAPABILITIES;

/**
 * Frontend visibility map for the current mock operator session.
 * The API must still enforce every permission and maker-checker constraint.
 */
export const ROLE_CAPABILITIES: Record<OperatorRole, readonly OperationsCapability[]> = {
  "support-agent": ["overview.view", "players.read", "tickets.read"],
  "support-lead": [
    "overview.view",
    "players.read",
    "tickets.read",
    "money.read",
    "responsible-play.read",
    "reports.read",
    "audit.read",
    "approvals.review",
  ],
  finance: [
    "overview.view",
    "money.read",
    "payout-float.read",
    "jurisdictions.read",
    "reports.read",
    "audit.read",
    "approvals.review",
  ],
  compliance: [
    "overview.view",
    "players.read",
    "tickets.read",
    "money.read",
    "payout-float.read",
    "responsible-play.read",
    "jurisdictions.read",
    "games.manage",
    "reports.read",
    "analytics.read",
    "audit.read",
    "approvals.review",
  ],
  "game-ops": [
    "overview.view",
    "tickets.read",
    "games.manage",
    "content.manage",
    "analytics.read",
    "audit.read",
  ],
  "content-editor": ["overview.view", "content.manage", "audit.read"],
  "cultural-reviewer": ["overview.view", "content.manage", "audit.read", "approvals.review"],
  "system-admin": ["overview.view", "audit.read", "users.manage"],
  "super-admin": allCapabilities,
};

export function roleHasCapability(role: OperatorRole, capability: OperationsCapability) {
  return ROLE_CAPABILITIES[role].includes(capability);
}
