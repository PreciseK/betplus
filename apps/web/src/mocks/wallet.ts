export type MoneyStatus = "pending" | "paid" | "failed" | "reversed";

export interface MoneyTransaction {
  reference: string;
  type: "OPay deposit" | "Deposit reversal";
  provider: "OPay";
  occurredAt: string;
  amountKobo: number;
  feeKobo: number;
  status: MoneyStatus;
  statusHistory: Array<{ status: string; at: string; detail: string }>;
}

export interface WalletSnapshot {
  playBalanceKobo: number;
  winningsBalanceKobo: number;
  bonusBalanceKobo?: number;
  currency: "NGN";
  registeredSourceLabel: string;
  transactions: MoneyTransaction[];
}

export interface FundingQuote {
  quoteId: string;
  amountKobo: number;
  feeKobo: number;
  feeVerified: true;
  sourceLabel: string;
  destinationLabel: "Play Balance";
  expectedTiming: string;
  reversible: false;
}

export interface WalletGateway {
  loadWallet(): Promise<WalletSnapshot>;
  quoteFunding(amountKobo: number): Promise<FundingQuote>;
  createCollection(quoteId: string): Promise<{ collectionId: string; otpRequired: true }>;
  submitCollectionOtp(collectionId: string, code: string): Promise<MoneyTransaction>;
}

export const MOCK_RECEIPT_REFERENCE = "BP-240814-A7K2";
export const MOCK_TRANSACTION_REFERENCES = [MOCK_RECEIPT_REFERENCE, "BP-240814-J4M8", "BP-240814-N9Q3"] as const;

const PAID_TRANSACTION: MoneyTransaction = {
  reference: MOCK_RECEIPT_REFERENCE,
  type: "OPay deposit",
  provider: "OPay",
  occurredAt: "2026-08-14T11:42:00.000Z",
  amountKobo: 250_000,
  feeKobo: 0,
  status: "paid",
  statusHistory: [
    { status: "Collection requested", at: "2026-08-14T11:41:31.000Z", detail: "OPay collection created." },
    { status: "Payment confirmed", at: "2026-08-14T11:42:00.000Z", detail: "Play Balance credited after server confirmation." },
  ],
};

const PENDING_TRANSACTION: MoneyTransaction = {
  reference: "BP-240814-J4M8",
  type: "OPay deposit",
  provider: "OPay",
  occurredAt: "2026-08-14T13:05:00.000Z",
  amountKobo: 100_000,
  feeKobo: 0,
  status: "pending",
  statusHistory: [
    { status: "Confirmation pending", at: "2026-08-14T13:05:00.000Z", detail: "We are waiting for OPay to confirm this collection." },
  ],
};

const NEW_FUNDING_TRANSACTION: MoneyTransaction = {
  ...PAID_TRANSACTION,
  reference: "BP-240814-N9Q3",
  occurredAt: "2026-08-14T14:18:00.000Z",
  statusHistory: [
    { status: "Collection requested", at: "2026-08-14T14:17:45.000Z", detail: "OPay collection created." },
    { status: "Payment confirmed", at: "2026-08-14T14:18:00.000Z", detail: "Play Balance credited after server confirmation." },
  ],
};

/** Temporary Epic 2 adapter. Replace after api-types publishes wallet contracts. */
export const mockWalletGateway: WalletGateway = {
  async loadWallet() {
    await Promise.resolve();
    return {
      playBalanceKobo: 100_000_000,
      winningsBalanceKobo: 0,
      currency: "NGN",
      registeredSourceLabel: "OPay wallet ending 0000",
      transactions: [PENDING_TRANSACTION, PAID_TRANSACTION],
    };
  },
  async quoteFunding(amountKobo) {
    await Promise.resolve();
    if (!Number.isSafeInteger(amountKobo) || amountKobo <= 0) throw new Error("AMOUNT_INVALID");
    return {
      quoteId: `mock-quote-${amountKobo}`,
      amountKobo,
      feeKobo: 0,
      feeVerified: true,
      sourceLabel: "OPay wallet ending 5678",
      destinationLabel: "Play Balance",
      expectedTiming: "Usually within 2 minutes after OPay confirms payment",
      reversible: false,
    };
  },
  async createCollection(quoteId) {
    await Promise.resolve();
    return { collectionId: `mock-collection-${quoteId}`, otpRequired: true };
  },
  async submitCollectionOtp(_collectionId, code) {
    await Promise.resolve();
    if (code !== "123456") throw new Error("OTP_INVALID_OR_EXPIRED");
    return { ...NEW_FUNDING_TRANSACTION };
  },
};

export function getMockReceipt(reference: string): MoneyTransaction | undefined {
  return [PAID_TRANSACTION, PENDING_TRANSACTION, NEW_FUNDING_TRANSACTION]
    .find((transaction) => transaction.reference === reference);
}
