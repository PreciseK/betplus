export type ReconciliationSeverity = "Critical" | "Attention" | "Review";
export type ReconciliationStatus = "Open" | "Investigating" | "Awaiting provider";

export interface ReconciliationException {
  reference: string;
  severity: ReconciliationSeverity;
  status: ReconciliationStatus;
  detectedAt: string;
  issue: string;
  playerReference: string;
  direction: "Credit" | "Debit";
  differenceKobo: number;
  provider: {
    name: string;
    reference: string;
    recordedAmountKobo: number;
    status: string;
    occurredAt: string;
  };
  ledger: readonly {
    reference: string;
    account: "Play Balance" | "Winnings Balance";
    amountKobo: number;
    purpose: string;
    postedAt: string;
  }[];
  finding: string;
  nextAction: string;
}

export const RECONCILIATION_EXCEPTIONS: readonly ReconciliationException[] = [
  {
    reference: "REC-260818-041",
    severity: "Critical",
    status: "Open",
    detectedAt: "18 Aug 2026, 3:04 AM WAT",
    issue: "Provider debit confirmed; withdrawal ledger debit missing",
    playerReference: "BP-4821",
    direction: "Debit",
    differenceKobo: 25_000_00,
    provider: {
      name: "OPay",
      reference: "OP-WD-884190",
      recordedAmountKobo: 25_000_00,
      status: "Delivered to beneficiary",
      occurredAt: "17 Aug 2026, 8:42:11 PM WAT",
    },
    ledger: [
      { reference: "LED-90112", account: "Winnings Balance", amountKobo: 0, purpose: "Withdrawal reservation released", postedAt: "17 Aug 2026, 8:52:19 PM WAT" },
    ],
    finding: "The provider delivered ₦25,000, but the player Winnings Balance was restored after the platform callback timed out.",
    nextAction: "Propose a compensating debit of ₦25,000 and attach the provider delivery proof.",
  },
  {
    reference: "REC-260818-038",
    severity: "Attention",
    status: "Investigating",
    detectedAt: "18 Aug 2026, 3:04 AM WAT",
    issue: "Deposit provider confirmation has no Play Balance credit",
    playerReference: "BP-7319",
    direction: "Credit",
    differenceKobo: 10_000_00,
    provider: {
      name: "OPay",
      reference: "OP-DEP-771902",
      recordedAmountKobo: 10_000_00,
      status: "Provider confirmed",
      occurredAt: "17 Aug 2026, 9:18:03 PM WAT",
    },
    ledger: [
      { reference: "LED-90084", account: "Play Balance", amountKobo: 0, purpose: "Deposit callback received; posting absent", postedAt: "17 Aug 2026, 9:18:09 PM WAT" },
    ],
    finding: "Provider evidence is complete and no equivalent credit exists elsewhere in the bounded ledger search.",
    nextAction: "Propose a compensating Play Balance credit of ₦10,000.",
  },
  {
    reference: "REC-260818-029",
    severity: "Review",
    status: "Awaiting provider",
    detectedAt: "18 Aug 2026, 3:04 AM WAT",
    issue: "Settlement total differs from provider batch by ₦700",
    playerReference: "Batch-level exception",
    direction: "Debit",
    differenceKobo: 700_00,
    provider: {
      name: "OPay",
      reference: "OP-BATCH-260817-09",
      recordedAmountKobo: 8_734_300_00,
      status: "Provider statement received",
      occurredAt: "18 Aug 2026, 2:51:22 AM WAT",
    },
    ledger: [
      { reference: "LED-BATCH-260817", account: "Winnings Balance", amountKobo: 8_735_000_00, purpose: "Daily withdrawal settlement total", postedAt: "18 Aug 2026, 2:44:05 AM WAT" },
    ],
    finding: "The difference equals one withholding entry, but the provider statement lacks the transaction-level row required to close it.",
    nextAction: "Wait for the provider transaction detail. Do not post a manual adjustment yet.",
  },
];

export const RECONCILIATION_RUN = {
  reference: "RECON-260818-NIGHTLY",
  completedAt: "18 Aug 2026, 3:04 AM WAT",
  scope: "OPay deposits, withdrawals and platform ledger · 17 Aug 2026 WAT",
  exceptionCount: RECONCILIATION_EXCEPTIONS.length,
};
