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
 * (apps/web/src/mocks/wallet.ts). There is no Collections API doc — deposits are
 * verified synchronously against the OPay Payout API (wallet validate + merchant
 * balance query) using the player's own registered phone number, not one entered
 * here — see FundingService::collect()'s doc comment on the backend. No OTP step.
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

  /**
   * Single step: verify the player's OPay wallet + BetPlus's merchant balance and
   * credit Play Balance, or reject — no OTP, nothing left pending after this call.
   */
  async collectDeposit(quoteId: string, idempotencyKey?: string) {
    const reference = idempotencyKey ?? (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function" ? crypto.randomUUID() : `dep-${Date.now()}`);
    const result = await post<{ status: string; credited_kobo?: number; reference?: string }>(
      "/wallet/deposits",
      { quote_id: quoteId, reference },
      { idempotencyKey: reference },
    );
    if (result.status !== "paid") {
      // limit_exceeded/protection_active/registry_unavailable (Epic 5 — REQ-RG-002/
      // 004/005/015 all block deposit, not just play) fall through to the generic
      // status-derived message; FundingFlow's catch blocks don't discriminate by
      // error message today, so a distinct thrown value costs nothing and documents
      // intent for whenever that copy is added.
      throw new Error(result.status.toUpperCase());
    }

    const now = new Date().toISOString();
    return toMoneyTransaction({
      reference: result.reference ?? reference,
      type: "OPay deposit",
      provider: "OPay",
      occurred_at: now,
      amount_kobo: result.credited_kobo ?? Number(quoteId),
      fee_kobo: 0,
      status: "paid",
      status_history: [
        { status: "Deposit requested", at: now, detail: "OPay wallet and merchant balance verified." },
        { status: "Payment confirmed", at: now, detail: "Play Balance credited immediately." },
      ],
    });
  },
};
