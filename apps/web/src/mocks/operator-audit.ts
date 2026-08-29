export interface AuditValueChange {
  field: string;
  before: string;
  after: string;
}

export interface AuditIntegrityEvidence {
  sequence: number;
  hashPrefix: string;
  previousHashPrefix: string;
  offServerCopyAt: string;
  retainedUntil: string;
}

export interface OperatorAuditEvent {
  id: string;
  occurredAt: string;
  actor: {
    displayName: string;
    role: string;
  };
  action: string;
  permission: string;
  subject: string;
  outcome: "completed" | "declined";
  ipAddress: string;
  justification?: string;
  changes: readonly AuditValueChange[];
  integrity: AuditIntegrityEvidence;
}

export const OPERATOR_AUDIT_EVENTS: readonly OperatorAuditEvent[] = [
  {
    id: "AUD-260818-1042",
    occurredAt: "2026-08-18T13:48:22+01:00",
    actor: { displayName: "Amaka Okafor", role: "Support Lead" },
    action: "Player account restriction changed",
    permission: "players.restrictions.update",
    subject: "Player BP-•••-7319",
    outcome: "completed",
    ipAddress: "10.44.18.27",
    justification: "Confirmed cooling-off request on support case CS-48218.",
    changes: [
      { field: "Restriction", before: "None", after: "Cooling-off · 7 days" },
      { field: "Review date", before: "Not set", after: "25 Aug 2026, 1:48 PM WAT" },
    ],
    integrity: {
      sequence: 1042,
      hashPrefix: "8bd19f7c2e6a",
      previousHashPrefix: "1e883c054a47",
      offServerCopyAt: "2026-08-18T13:49:03+01:00",
      retainedUntil: "18 Aug 2027",
    },
  },
  {
    id: "AUD-260818-1041",
    occurredAt: "2026-08-18T13:31:09+01:00",
    actor: { displayName: "Tunde Bamidele", role: "Finance" },
    action: "Reconciliation exception assigned",
    permission: "reconciliation.exceptions.assign",
    subject: "Exception REC-260818-014",
    outcome: "completed",
    ipAddress: "10.44.18.42",
    justification: "Assigned for same-day OPay settlement review.",
    changes: [
      { field: "Owner", before: "Unassigned", after: "Tunde Bamidele" },
      { field: "Queue", before: "New", after: "Finance review" },
    ],
    integrity: {
      sequence: 1041,
      hashPrefix: "1e883c054a47",
      previousHashPrefix: "ba10fcd61a55",
      offServerCopyAt: "2026-08-18T13:32:02+01:00",
      retainedUntil: "18 Aug 2027",
    },
  },
  {
    id: "AUD-260818-1040",
    occurredAt: "2026-08-18T12:56:44+01:00",
    actor: { displayName: "Ifeoma Nwosu", role: "Compliance" },
    action: "Jurisdiction rule approved",
    permission: "jurisdictions.rules.approve",
    subject: "Enugu · player tax rule",
    outcome: "completed",
    ipAddress: "10.44.20.11",
    justification: "Approved against signed directive EN-LG-2026-08.",
    changes: [
      { field: "Tax rate", before: "5%", after: "7.5%" },
      { field: "Effective date", before: "Not set", after: "1 Sep 2026" },
    ],
    integrity: {
      sequence: 1040,
      hashPrefix: "ba10fcd61a55",
      previousHashPrefix: "f7307b3031c9",
      offServerCopyAt: "2026-08-18T12:57:18+01:00",
      retainedUntil: "18 Aug 2027",
    },
  },
  {
    id: "AUD-260818-1039",
    occurredAt: "2026-08-18T11:42:31+01:00",
    actor: { displayName: "Amaka Okafor", role: "Support Lead" },
    action: "Withdrawal review declined",
    permission: "withdrawals.review",
    subject: "Withdrawal WD-•••-0084",
    outcome: "declined",
    ipAddress: "10.44.18.27",
    justification: "No account change made; evidence did not meet the review threshold.",
    changes: [{ field: "Withdrawal state", before: "Review required", after: "Review required" }],
    integrity: {
      sequence: 1039,
      hashPrefix: "f7307b3031c9",
      previousHashPrefix: "7522aa1c069d",
      offServerCopyAt: "2026-08-18T11:43:06+01:00",
      retainedUntil: "18 Aug 2027",
    },
  },
  {
    id: "AUD-260818-1038",
    occurredAt: "2026-08-18T10:17:06+01:00",
    actor: { displayName: "Chidi Eze", role: "Game Ops" },
    action: "Game availability changed",
    permission: "games.availability.update",
    subject: "BlackRed · Rivers",
    outcome: "completed",
    ipAddress: "10.44.21.08",
    justification: "Licence schedule restored after compliance verification.",
    changes: [{ field: "Availability", before: "Suspended", after: "Available" }],
    integrity: {
      sequence: 1038,
      hashPrefix: "7522aa1c069d",
      previousHashPrefix: "229f2cc9965b",
      offServerCopyAt: "2026-08-18T10:17:44+01:00",
      retainedUntil: "18 Aug 2027",
    },
  },
  {
    id: "AUD-260818-1037",
    occurredAt: "2026-08-18T09:02:51+01:00",
    actor: { displayName: "Zainab Musa", role: "System Admin" },
    action: "Operator role changed",
    permission: "operators.roles.update",
    subject: "Operator OP-•••-2041",
    outcome: "completed",
    ipAddress: "10.44.16.05",
    justification: "Access review AR-2026-Q3 approved by Compliance.",
    changes: [{ field: "Role", before: "Support Agent", after: "Support Lead" }],
    integrity: {
      sequence: 1037,
      hashPrefix: "229f2cc9965b",
      previousHashPrefix: "975ebfd71420",
      offServerCopyAt: "2026-08-18T09:03:29+01:00",
      retainedUntil: "18 Aug 2027",
    },
  },
];

export function formatAuditTimestamp(value: string) {
  return new Intl.DateTimeFormat("en-NG", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
    timeZone: "Africa/Lagos",
    timeZoneName: "short",
  }).format(new Date(value));
}
