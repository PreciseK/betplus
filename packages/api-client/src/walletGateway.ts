import { get, post } from "./http";

type ApiTransaction = {
  reference: string;
  type: string;
  provider: string;
  occurred_at: string;
  amount_kobo: number;
  fee_kobo: number;
  status: string;
  status_history?: Array<{ status: string; at: string; detail: string }>;
};

function toMoneyTransaction(t: ApiTransaction) {
  return {
    reference: t.reference,
    type: t.type as "OPay deposit" | "Deposit reversal",
    provider: t.provider as "OPay",
    occurredAt: t.occurred_at,
    amountKobo: t.amount_kobo,
    feeKobo: t.fee_kobo,
    status: t.status as "pending" | "paid" | "failed" | "reversed",
    statusHistory: t.status_history ?? [],
  };
}

/**
 * Real Story 2.3/2.6 implementation of apps/web's WalletGateway
 * (apps/web/src/mocks/wallet.ts). Endpoint field names beyond what REQ-PAY specifies
 * are unconfirmed against a real OPay Collections doc — same caveat as the backend
 * (see FundingService) — but the /v1/wallet* surface itself is Betplus's own and is
 * fully tested.
 */
export const walletGateway = {
  async loadWallet() {
    const [wallet, history] = await Promise.all([
      get<{ play_balance_kobo: number; winnings_balance_kobo: number; bonus_balance_kobo?: number; currency: "NGN"; registered_source_label: string }>(
        "/wallet",
      ),
      get<{ transactions: ApiTransaction[] }>("/wallet/transactions"),
    ]);

    return {
      playBalanceKobo: wallet.play_balance_kobo,
      winningsBalanceKobo: wallet.winnings_balance_kobo,
      bonusBalanceKobo: wallet.bonus_balance_kobo ?? 0,
      currency: wallet.currency,
      registeredSourceLabel: wallet.registered_source_label,
      transactions: history.transactions.map(toMoneyTransaction),
    };
  },

  async quoteFunding(amountKobo: number) {
    const quote = await post<{
      quote_id: string;
      amount_kobo: number;
      fee_kobo: number;
      fee_verified: true;
      source_label: string;
      destination_label: "Play Balance";
      expected_timing: string;
      reversible: false;
    }>("/wallet/deposits/quote", { amount_kobo: amountKobo });

    return {
      quoteId: quote.quote_id,
      amountKobo: quote.amount_kobo,
      feeKobo: quote.fee_kobo,
      feeVerified: quote.fee_verified,
      sourceLabel: quote.source_label,
      destinationLabel: quote.destination_label,
      expectedTiming: quote.expected_timing,
      reversible: quote.reversible,
    };
  },

  async createCollection(quoteId: string, idempotencyKey?: string) {
    const key = idempotencyKey ?? (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function" ? crypto.randomUUID() : undefined);
    const result = await post<{ status: string; collection_id?: number }>(
      "/wallet/deposits",
      { quote_id: quoteId },
      { idempotencyKey: key },
    );
    if (result.status !== "otp_required" || result.collection_id === undefined) {
      // limit_exceeded/protection_active/registry_unavailable (Epic 5 — REQ-RG-002/
      // 004/005/015 all block deposit, not just play) fall through to the generic
      // COLLECTION_FAILED message; FundingFlow's catch blocks don't discriminate by
      // error message today, so a distinct thrown value costs nothing and documents
      // intent for whenever that copy is added.
      throw new Error(result.status === "bvn_required" ? "BVN_REQUIRED" : result.status.toUpperCase());
    }

    return { collectionId: String(result.collection_id), otpRequired: true as const };
  },

  async submitCollectionOtp(collectionId: string, code: string) {
    const result = await post<{ status: string } & Partial<ApiTransaction>>(
      `/wallet/deposits/${collectionId}/otp`,
      { otp: code },
    );
    if (result.status !== "paid" || result.reference === undefined) {
      throw new Error("OTP_INVALID_OR_EXPIRED");
    }

    return toMoneyTransaction(result as ApiTransaction);
  },
};
