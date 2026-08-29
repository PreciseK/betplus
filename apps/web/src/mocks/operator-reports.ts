export type ReportJobStatus = "Ready" | "Processing" | "Queued" | "Expired";
export type ReportFormat = "CSV" | "JSON";

export interface FinancialSlice {
  id: string;
  stateCode: string;
  stateName: string;
  gameCode: "BLACKRED" | "HERITAGE";
  stakesKobo: number;
  payoutsKobo: number;
  ggrKobo: number;
  actualRtp: number;
  modelledRtp: number;
  providerFeesKobo: number;
  taxKobo: number;
  floatMovementKobo: number;
}

export interface ReportJob {
  id: string;
  report: string;
  stateCode: string;
  range: string;
  formats: readonly ReportFormat[];
  status: ReportJobStatus;
  requestedAt: string;
  actor: string;
  rowCount?: number;
  auditReference: string;
  expiresAt?: string;
}

export interface StateRemittance {
  id: string;
  month: string;
  stateCode: string;
  stateName: string;
  attributedGgrKobo: number;
  expectedRemittanceKobo: number;
  remittedKobo: number;
  varianceKobo: number;
  status: "Reconciled" | "Variance" | "Pending evidence";
}

export const REPORT_DEFAULT_RANGE = {
  from: "2026-08-01",
  to: "2026-08-18",
};

export const FINANCIAL_SLICES: readonly FinancialSlice[] = [
  { id: "LAG-BR", stateCode: "LA", stateName: "Lagos", gameCode: "BLACKRED", stakesKobo: 184_500_000_00, payoutsKobo: 150_720_000_00, ggrKobo: 33_780_000_00, actualRtp: 81.69, modelledRtp: 81.25, providerFeesKobo: 4_612_500_00, taxKobo: 3_378_000_00, floatMovementKobo: -12_400_000_00 },
  { id: "LAG-HG", stateCode: "LA", stateName: "Lagos", gameCode: "HERITAGE", stakesKobo: 72_360_000_00, payoutsKobo: 57_220_000_00, ggrKobo: 15_140_000_00, actualRtp: 79.08, modelledRtp: 78.75, providerFeesKobo: 1_809_000_00, taxKobo: 1_514_000_00, floatMovementKobo: 4_800_000_00 },
  { id: "RIV-BR", stateCode: "RI", stateName: "Rivers", gameCode: "BLACKRED", stakesKobo: 94_780_000_00, payoutsKobo: 77_164_000_00, ggrKobo: 17_616_000_00, actualRtp: 81.41, modelledRtp: 81.25, providerFeesKobo: 2_369_500_00, taxKobo: 1_761_600_00, floatMovementKobo: -3_180_000_00 },
  { id: "OYO-BR", stateCode: "OY", stateName: "Oyo", gameCode: "BLACKRED", stakesKobo: 66_920_000_00, payoutsKobo: 54_020_000_00, ggrKobo: 12_900_000_00, actualRtp: 80.72, modelledRtp: 81.25, providerFeesKobo: 1_673_000_00, taxKobo: 1_290_000_00, floatMovementKobo: 2_100_000_00 },
  { id: "FCT-HG", stateCode: "FC", stateName: "FCT", gameCode: "HERITAGE", stakesKobo: 81_240_000_00, payoutsKobo: 64_332_000_00, ggrKobo: 16_908_000_00, actualRtp: 79.19, modelledRtp: 78.75, providerFeesKobo: 2_031_000_00, taxKobo: 1_690_800_00, floatMovementKobo: -1_840_000_00 },
];

export const REPORT_JOBS: readonly ReportJob[] = [
  { id: "EXP-260818-091", report: "Per-state regulatory activity", stateCode: "LA", range: "01–18 Aug 2026", formats: ["CSV", "JSON"], status: "Ready", requestedAt: "18 Aug 2026, 11:42 AM WAT", actor: "Nkiru Eze", rowCount: 48_291, auditReference: "AUD-260818-9081", expiresAt: "18 Aug 2026, 12:42 PM WAT" },
  { id: "EXP-260818-088", report: "Financial activity by game", stateCode: "RI", range: "01–18 Aug 2026", formats: ["CSV"], status: "Processing", requestedAt: "18 Aug 2026, 11:34 AM WAT", actor: "Chiamaka Obi", auditReference: "AUD-260818-9068" },
  { id: "EXP-260818-073", report: "Tax and remittance evidence", stateCode: "OY", range: "Jul 2026", formats: ["CSV", "JSON"], status: "Expired", requestedAt: "18 Aug 2026, 9:02 AM WAT", actor: "Nkiru Eze", rowCount: 12_804, auditReference: "AUD-260818-8924", expiresAt: "18 Aug 2026, 10:02 AM WAT" },
];

export const STATE_REMITTANCES: readonly StateRemittance[] = [
  { id: "REM-2026-07-LA", month: "July 2026", stateCode: "LA", stateName: "Lagos", attributedGgrKobo: 82_430_000_00, expectedRemittanceKobo: 8_243_000_00, remittedKobo: 8_243_000_00, varianceKobo: 0, status: "Reconciled" },
  { id: "REM-2026-07-RI", month: "July 2026", stateCode: "RI", stateName: "Rivers", attributedGgrKobo: 37_560_000_00, expectedRemittanceKobo: 3_756_000_00, remittedKobo: 3_700_000_00, varianceKobo: -56_000_00, status: "Variance" },
  { id: "REM-2026-07-OY", month: "July 2026", stateCode: "OY", stateName: "Oyo", attributedGgrKobo: 24_910_000_00, expectedRemittanceKobo: 2_491_000_00, remittedKobo: 0, varianceKobo: -2_491_000_00, status: "Pending evidence" },
];

export const REPORT_STATES = [
  { code: "ALL", name: "All attributed states" },
  { code: "LA", name: "Lagos" },
  { code: "RI", name: "Rivers" },
  { code: "OY", name: "Oyo" },
  { code: "FC", name: "FCT" },
] as const;
