import type { IconName } from "@/components/ui/Icon/Icon";
import { roleHasCapability, type OperationsCapability } from "@/components/operations/operations-permissions";
import type { OperatorRole } from "@/mocks/operator-session";

export type OperationsNavigationGroup = "Home" | "Customers" | "Finance" | "Product" | "Compliance" | "Insights" | "Administration";

export interface OperationsDestination {
  href: string;
  label: string;
  shortLabel: string;
  icon: IconName;
  group: OperationsNavigationGroup;
  subgroup?: string;
  capability: OperationsCapability;
}

export const OPERATIONS_DESTINATIONS: readonly OperationsDestination[] = [
  { href: "/back-office/overview", label: "Dashboard", shortLabel: "Dashboard", icon: "home", group: "Home", capability: "overview.view" },
  { href: "/back-office/players", label: "Current case", shortLabel: "Current case", icon: "account", group: "Customers", subgroup: "Player details", capability: "players.read" },
  { href: "/back-office/player-ledger", label: "Ledger", shortLabel: "Ledger", icon: "activity", group: "Customers", subgroup: "Player details", capability: "players.read" },
  { href: "/back-office/player-tickets", label: "Tickets", shortLabel: "Tickets", icon: "ticket", group: "Customers", subgroup: "Player details", capability: "players.read" },
  { href: "/back-office/player-payments", label: "Payments", shortLabel: "Payments", icon: "money", group: "Customers", subgroup: "Player details", capability: "players.read" },
  { href: "/back-office/player-tax", label: "Tax", shortLabel: "Tax", icon: "money", group: "Customers", subgroup: "Player details", capability: "players.read" },
  { href: "/back-office/player-notifications", label: "Notifications", shortLabel: "Notifications", icon: "phone", group: "Customers", subgroup: "Player details", capability: "players.read" },
  { href: "/back-office/player-responsible-play", label: "Responsible play", shortLabel: "Responsible play", icon: "limits", group: "Customers", subgroup: "Player details", capability: "players.read" },
  { href: "/back-office/tickets", label: "Support tickets", shortLabel: "Tickets", icon: "ticket", group: "Customers", subgroup: "Support", capability: "tickets.read" },
  { href: "/back-office/money", label: "Reconciliation", shortLabel: "Reconciliation", icon: "money", group: "Finance", subgroup: "Payments", capability: "money.read" },
  { href: "/back-office/deposits", label: "Deposits", shortLabel: "Deposits", icon: "money", group: "Finance", subgroup: "Payments", capability: "money.read" },
  { href: "/back-office/payout-float", label: "Payouts", shortLabel: "Payouts", icon: "wallet", group: "Finance", subgroup: "Payments", capability: "payout-float.read" },
  { href: "/back-office/adjustments", label: "Adjustments", shortLabel: "Adjustments", icon: "activity", group: "Finance", subgroup: "Controls", capability: "money.read" },
  { href: "/back-office/games", label: "Game overview", shortLabel: "Games", icon: "games", group: "Product", subgroup: "Game management", capability: "games.manage" },
  { href: "/back-office/game-configurations", label: "Configurations", shortLabel: "Configurations", icon: "limits", group: "Product", subgroup: "Game management", capability: "games.manage" },
  { href: "/back-office/game-economics", label: "Economics models", shortLabel: "Economics", icon: "limits", group: "Product", subgroup: "Game management", capability: "games.manage" },
  { href: "/back-office/promotions", label: "Promotions & Boosts", shortLabel: "Promotions", icon: "activity", group: "Product", subgroup: "Promotions", capability: "games.manage" },
  { href: "/back-office/content", label: "Content releases", shortLabel: "Content", icon: "games", group: "Product", subgroup: "Content", capability: "content.manage" },
  { href: "/back-office/responsible-play", label: "Safer play reviews", shortLabel: "Safer play", icon: "limits", group: "Compliance", subgroup: "Player protection", capability: "responsible-play.read" },
  { href: "/back-office/player-limits", label: "Limits", shortLabel: "Limits", icon: "limits", group: "Compliance", subgroup: "Player protection", capability: "responsible-play.read" },
  { href: "/back-office/exclusions", label: "Exclusions", shortLabel: "Exclusions", icon: "lock", group: "Compliance", subgroup: "Player protection", capability: "responsible-play.read" },
  { href: "/back-office/jurisdictions", label: "Licences and tax", shortLabel: "Licences", icon: "activity", group: "Compliance", subgroup: "Regulatory", capability: "jurisdictions.read" },
  { href: "/back-office/daily-summary", label: "Daily summary", shortLabel: "Daily summary", icon: "calendar", group: "Insights", subgroup: "Performance", capability: "analytics.read" },
  { href: "/back-office/reports", label: "Reports", shortLabel: "Reports", icon: "activity", group: "Insights", subgroup: "Performance", capability: "reports.read" },
  { href: "/back-office/analytics", label: "Analytics", shortLabel: "Analytics", icon: "activity", group: "Insights", subgroup: "Performance", capability: "analytics.read" },
  { href: "/back-office/users", label: "Team access", shortLabel: "Team access", icon: "support", group: "Administration", subgroup: "Access", capability: "users.manage" },
  { href: "/back-office/roles", label: "Roles", shortLabel: "Roles", icon: "lock", group: "Administration", subgroup: "Access", capability: "users.manage" },
  { href: "/back-office/audit-log", label: "Audit log", shortLabel: "Audit log", icon: "lock", group: "Administration", subgroup: "Audit", capability: "audit.read" },
];

export const OPERATOR_ROLE_LABELS: Record<OperatorRole, string> = {
  "support-agent": "Support Agent",
  "support-lead": "Support Lead",
  finance: "Finance",
  compliance: "Compliance",
  "game-ops": "Game Ops",
  "content-editor": "Content Editor",
  "cultural-reviewer": "Cultural Reviewer",
  "system-admin": "System Admin",
  "super-admin": "Super Admin",
};

export function destinationsForRole(role: OperatorRole) {
  return OPERATIONS_DESTINATIONS.filter((destination) => roleHasCapability(role, destination.capability));
}
