export type LicenceState = "Active" | "Expires soon" | "Expired · play stopped";

export interface JurisdictionRecord {
  code: string;
  state: string;
  licenceStatus: LicenceState;
  licenceReference: string;
  expiresAt: string;
  daysToExpiry: number;
  playState: string;
  rulesetVersion: string;
  taxRate: string;
  activity: {
    tickets: number;
    stakedKobo: number;
    taxWithheldKobo: number;
  };
  remittance: {
    status: string;
    amountKobo: number;
    dueAt: string;
    reference: string;
  };
  registryFreshness: string;
  failedChecks: readonly string[];
  obligations: readonly string[];
}

export const JURISDICTIONS: readonly JurisdictionRecord[] = [
  {
    code: "EN",
    state: "Enugu",
    licenceStatus: "Expires soon",
    licenceReference: "ENSB-IG-2025-118",
    expiresAt: "14 Sep 2026, 11:59:59 PM WAT",
    daysToExpiry: 27,
    playState: "Enabled · automatic stop scheduled at expiry",
    rulesetVersion: "EN-TAX-2026.06-v2",
    taxRate: "10% WHT on gross winnings",
    activity: { tickets: 18_402, stakedKobo: 42_810_000_00, taxWithheldKobo: 1_284_300_00 },
    remittance: { status: "Due in 4 days", amountKobo: 1_284_300_00, dueAt: "22 Aug 2026", reference: "REM-EN-2026-08" },
    registryFreshness: "Current · checked 18 Aug 2026, 2:58 AM WAT",
    failedChecks: [],
    obligations: ["Renewal pack due 21 Aug 2026", "August remittance due 22 Aug 2026"],
  },
  {
    code: "LA",
    state: "Lagos",
    licenceStatus: "Active",
    licenceReference: "LSLB-2026-4401",
    expiresAt: "31 Mar 2027, 11:59:59 PM WAT",
    daysToExpiry: 225,
    playState: "Enabled",
    rulesetVersion: "LA-TAX-2026.07-v4",
    taxRate: "10% WHT on gross winnings",
    activity: { tickets: 61_840, stakedKobo: 164_250_000_00, taxWithheldKobo: 5_428_900_00 },
    remittance: { status: "Submitted", amountKobo: 5_428_900_00, dueAt: "20 Aug 2026", reference: "REM-LA-2026-08" },
    registryFreshness: "Current · checked 18 Aug 2026, 2:58 AM WAT",
    failedChecks: [],
    obligations: ["Submission acknowledgement pending"],
  },
  {
    code: "KN",
    state: "Kano",
    licenceStatus: "Expired · play stopped",
    licenceReference: "KNSB-IG-2025-031",
    expiresAt: "17 Aug 2026, 11:59:59 PM WAT",
    daysToExpiry: -1,
    playState: "Stopped 17 Aug 2026, 11:59:59 PM WAT · all channels",
    rulesetVersion: "KN-TAX-2026.01-v1",
    taxRate: "10% WHT on gross winnings",
    activity: { tickets: 4_922, stakedKobo: 9_430_000_00, taxWithheldKobo: 311_400_00 },
    remittance: { status: "Reconciled", amountKobo: 311_400_00, dueAt: "18 Aug 2026", reference: "REM-KN-2026-08" },
    registryFreshness: "Current · checked 18 Aug 2026, 2:58 AM WAT",
    failedChecks: [],
    obligations: ["Renewal evidence required before play can resume", "Stopped-channel notice due to regulator"],
  },
  {
    code: "RV",
    state: "Rivers",
    licenceStatus: "Active",
    licenceReference: "RSLB-2026-091",
    expiresAt: "30 Nov 2026, 11:59:59 PM WAT",
    daysToExpiry: 104,
    playState: "Enabled · registry gate degraded closed",
    rulesetVersion: "RV-TAX-2026.04-v3",
    taxRate: "12.5% WHT on gross winnings",
    activity: { tickets: 8_730, stakedKobo: 21_190_000_00, taxWithheldKobo: 824_600_00 },
    remittance: { status: "Evidence required", amountKobo: 824_600_00, dueAt: "21 Aug 2026", reference: "REM-RV-2026-08" },
    registryFreshness: "Stale · last successful check 17 Aug 2026, 2:58 AM WAT",
    failedChecks: ["State exclusion registry sync failed closed at 18 Aug 2026, 2:58 AM WAT"],
    obligations: ["Restore registry sync", "Attach remittance bank evidence by 21 Aug 2026"],
  },
];

export const TAX_RULESET_HISTORY = [
  { state: "Enugu", version: "EN-TAX-2026.06-v2", rate: "10%", legalBasis: "Enugu State Gaming Law 2025 · s.44", effectiveAt: "01 Jun 2026, 12:00 AM WAT", appliedBy: "MC-260531-014", historicalTreatment: "Tickets settled before the effective time retain EN-TAX-2026.01-v1." },
  { state: "Lagos", version: "LA-TAX-2026.07-v4", rate: "10%", legalBasis: "Lagos Gaming and Lottery Regulation 2026 · r.18", effectiveAt: "01 Jul 2026, 12:00 AM WAT", appliedBy: "MC-260630-029", historicalTreatment: "Historical tickets retain the ruleset version stored at settlement." },
] as const;
