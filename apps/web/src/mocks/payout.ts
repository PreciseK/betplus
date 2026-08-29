export type WithdrawalSource = "winnings" | "released-play";
export type KnownOpayPayoutStatus = "INITIAL" | "PENDING" | "CHECKING" | "SUCCESS" | "FAIL" | "CLOSE" | "RETURN";
export type PayoutDisplayStatus = "requested" | "processing" | "paid" | "needs-attention" | "manual-review";

export interface TurnoverPosition {
  depositAmountKobo: number;
  stakedKobo: number;
  requiredStakeKobo: number;
  releasedPlayBalanceKobo: number;
}

export interface PayoutStatusEvent {
  status: string;
  at: string;
  detail: string;
}

export interface PayoutRecord {
  reference: string;
  kind: "automatic-prize" | "withdrawal";
  createdAt: string;
  amountKobo: number;
  sourceLabel: string;
  destinationLabel: string;
  providerStatus: string;
  displayStatus: PayoutDisplayStatus;
  statusExpectation: string;
  opayOrderNumber?: string;
  game?: "BlackRed";
  ticketReference?: string;
  grossPrizeKobo?: number;
  taxWithheldKobo?: number;
  taxRateBasisPoints?: number;
  taxBasisLabel?: string;
  netPaidKobo?: number;
  fundsRemainInWinnings: boolean;
  statusHistory: PayoutStatusEvent[];
}

export interface PayoutContext {
  winningsBalanceKobo: number;
  playBalanceKobo: number;
  destinationLabel: string;
  destinationName: string;
  turnover: TurnoverPosition;
  manualReviewThresholdKobo: number;
  payouts: PayoutRecord[];
}

export interface WithdrawalQuote {
  quoteId: string;
  source: WithdrawalSource;
  sourceLabel: "Winnings Balance" | "Released Play Balance";
  amountKobo: number;
  feeKobo: number;
  feeVerified: true;
  taxAlreadyHandled: true;
  destinationLabel: string;
  destinationName: string;
  expectedTiming: string;
  manualReviewRequired: boolean;
}

export interface PayoutGateway {
  loadPayoutContext(): Promise<PayoutContext>;
  quoteWithdrawal(source: WithdrawalSource, amountKobo: number): Promise<WithdrawalQuote>;
  requestWithdrawal(quoteId: string): Promise<PayoutRecord>;
}

export const AUTOMATIC_PAYOUT_REFERENCE = "BP-PO-240814-WIN";
export const PROCESSING_PAYOUT_REFERENCE = "BP-PO-240814-PENDING";
export const REVIEW_PAYOUT_REFERENCE = "BP-PO-240814-REVIEW";
export const NEW_WITHDRAWAL_REFERENCE = "BP-PO-240815-WD";
export const PAYOUT_REFERENCES = [
  AUTOMATIC_PAYOUT_REFERENCE,
  PROCESSING_PAYOUT_REFERENCE,
  REVIEW_PAYOUT_REFERENCE,
  NEW_WITHDRAWAL_REFERENCE,
] as const;

const DESTINATION = "OPay wallet ending 5678";
const DESTINATION_NAME = "Adaeze Okafor";

const automaticPayout: PayoutRecord = {
  reference: AUTOMATIC_PAYOUT_REFERENCE,
  kind: "automatic-prize",
  createdAt: "2026-08-14T15:20:45.000Z",
  amountKobo: 950_000,
  sourceLabel: "BlackRed net prize",
  destinationLabel: DESTINATION,
  providerStatus: "SUCCESS",
  displayStatus: "paid",
  statusExpectation: "Paid to OPay",
  opayOrderNumber: "OPAY-89210475",
  game: "BlackRed",
  ticketReference: "BP-BR-240814-WIN",
  grossPrizeKobo: 1_000_000,
  taxWithheldKobo: 50_000,
  taxRateBasisPoints: 500,
  taxBasisLabel: "Gross prize",
  netPaidKobo: 950_000,
  fundsRemainInWinnings: false,
  statusHistory: [
    { status: "Winnings credited", at: "2026-08-14T15:20:00.000Z", detail: "Net prize credited to Winnings Balance." },
    { status: "Transfer processing", at: "2026-08-14T15:20:08.000Z", detail: "Automatic transfer sent to OPay." },
    { status: "Paid", at: "2026-08-14T15:20:45.000Z", detail: "OPay confirmed wallet credit." },
  ],
};

const processingPayout: PayoutRecord = {
  ...automaticPayout,
  reference: PROCESSING_PAYOUT_REFERENCE,
  createdAt: "2026-08-14T16:10:00.000Z",
  providerStatus: "CHECKING",
  displayStatus: "processing",
  statusExpectation: "OPay confirmation is pending. The net prize remains in Winnings Balance.",
  opayOrderNumber: "OPAY-89210601",
  fundsRemainInWinnings: true,
  statusHistory: [
    { status: "Winnings credited", at: "2026-08-14T16:09:45.000Z", detail: "Net prize credited to Winnings Balance." },
    { status: "Transfer processing", at: "2026-08-14T16:10:00.000Z", detail: "Betplus is waiting for OPay confirmation. No prize is lost." },
  ],
};

const reviewPayout: PayoutRecord = {
  reference: REVIEW_PAYOUT_REFERENCE,
  kind: "withdrawal",
  createdAt: "2026-08-14T17:00:00.000Z",
  amountKobo: 150_000,
  sourceLabel: "Winnings Balance",
  destinationLabel: DESTINATION,
  providerStatus: "PROVIDER_REVIEW_42",
  displayStatus: "manual-review",
  statusExpectation: "The provider status is being reviewed manually. It is not treated as a failure.",
  opayOrderNumber: "OPAY-89210714",
  fundsRemainInWinnings: true,
  statusHistory: [
    { status: "Withdrawal requested", at: "2026-08-14T17:00:00.000Z", detail: "Request received and linked to this reference." },
    { status: "Review in progress", at: "2026-08-14T17:01:00.000Z", detail: "An unrecognised provider status was escalated instead of marked failed." },
  ],
};

const newWithdrawal: PayoutRecord = {
  reference: NEW_WITHDRAWAL_REFERENCE,
  kind: "withdrawal",
  createdAt: "2026-08-15T09:15:00.000Z",
  amountKobo: 50_000,
  sourceLabel: "Winnings Balance",
  destinationLabel: DESTINATION,
  providerStatus: "PENDING",
  displayStatus: "processing",
  statusExpectation: "Usually confirmed within 90 seconds. You can leave this screen and check Activity.",
  opayOrderNumber: "OPAY-89210902",
  fundsRemainInWinnings: true,
  statusHistory: [
    { status: "Withdrawal requested", at: "2026-08-15T09:15:00.000Z", detail: "Request received and linked to this reference." },
    { status: "Transfer processing", at: "2026-08-15T09:15:04.000Z", detail: "Betplus is waiting for OPay confirmation." },
  ],
};

const context: PayoutContext = {
  winningsBalanceKobo: 185_000,
  playBalanceKobo: 640_000,
  destinationLabel: DESTINATION,
  destinationName: DESTINATION_NAME,
  turnover: {
    depositAmountKobo: 1_000_000,
    stakedKobo: 420_000,
    requiredStakeKobo: 1_000_000,
    releasedPlayBalanceKobo: 0,
  },
  manualReviewThresholdKobo: 100_000,
  payouts: [reviewPayout, processingPayout, automaticPayout],
};

export function normalizePayoutStatus(providerStatus: string): PayoutDisplayStatus {
  switch (providerStatus) {
    case "INITIAL": return "requested";
    case "PENDING":
    case "CHECKING": return "processing";
    case "SUCCESS": return "paid";
    case "FAIL":
    case "CLOSE":
    case "RETURN": return "needs-attention";
    default: return "manual-review";
  }
}

/** Temporary Epic 4 adapter. Replace after api-types publishes payout contracts. */
export const mockPayoutGateway: PayoutGateway = {
  async loadPayoutContext() {
    await Promise.resolve();
    return context;
  },
  async quoteWithdrawal(source, amountKobo) {
    await Promise.resolve();
    const availableKobo = source === "winnings" ? context.winningsBalanceKobo : context.turnover.releasedPlayBalanceKobo;
    if (!Number.isSafeInteger(amountKobo) || amountKobo <= 0 || amountKobo > availableKobo) {
      throw new Error("WITHDRAWAL_AMOUNT_INVALID");
    }
    return {
      quoteId: `mock-withdrawal-${source}-${amountKobo}`,
      source,
      sourceLabel: source === "winnings" ? "Winnings Balance" : "Released Play Balance",
      amountKobo,
      feeKobo: 0,
      feeVerified: true,
      taxAlreadyHandled: true,
      destinationLabel: context.destinationLabel,
      destinationName: context.destinationName,
      expectedTiming: "Usually within 90 seconds after submission",
      manualReviewRequired: amountKobo >= context.manualReviewThresholdKobo,
    };
  },
  async requestWithdrawal(quoteId) {
    await Promise.resolve();
    const amountKobo = Number(quoteId.split("-").at(-1));
    if (!Number.isSafeInteger(amountKobo)) throw new Error("QUOTE_INVALID");
    if (amountKobo >= context.manualReviewThresholdKobo) {
      return { ...reviewPayout, amountKobo, reference: REVIEW_PAYOUT_REFERENCE };
    }
    return { ...newWithdrawal, amountKobo };
  },
};

export function getMockPayout(reference: string): PayoutRecord | undefined {
  return [automaticPayout, processingPayout, reviewPayout, newWithdrawal]
    .find((payout) => payout.reference === reference);
}
