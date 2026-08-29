import { get, post } from "./http";

type ApiStatusEvent = { status: string; at: string; detail: string };

type ApiPayout = {
  reference: string;
  kind: "automatic-prize" | "withdrawal";
  created_at: string;
  amount_kobo: number;
  source_label: string;
  destination_label: string;
  provider_status: string;
  display_status: string;
  status_expectation: string;
  opay_order_number?: string;
  game?: "BlackRed";
  ticket_reference?: string;
  gross_prize_kobo?: number;
  tax_withheld_kobo?: number;
  tax_rate_basis_points?: number;
  tax_basis_label?: string;
  net_paid_kobo?: number;
  funds_remain_in_winnings: boolean;
  status_history: ApiStatusEvent[];
};

function toPayoutRecord(p: ApiPayout) {
  return {
    reference: p.reference,
    kind: p.kind,
    createdAt: p.created_at,
    amountKobo: p.amount_kobo,
    sourceLabel: p.source_label,
    destinationLabel: p.destination_label,
    providerStatus: p.provider_status,
    displayStatus: p.display_status as "requested" | "processing" | "paid" | "needs-attention" | "manual-review",
    statusExpectation: p.status_expectation,
    opayOrderNumber: p.opay_order_number,
    game: p.game,
    ticketReference: p.ticket_reference,
    grossPrizeKobo: p.gross_prize_kobo,
    taxWithheldKobo: p.tax_withheld_kobo,
    taxRateBasisPoints: p.tax_rate_basis_points,
    taxBasisLabel: p.tax_basis_label,
    netPaidKobo: p.net_paid_kobo,
    fundsRemainInWinnings: p.funds_remain_in_winnings,
    statusHistory: p.status_history,
  };
}

/**
 * Real Story 4.1/4.6 implementation of apps/web's PayoutGateway
 * (apps/web/src/mocks/payout.ts). "released-play" as a withdrawal source is refused
 * server-side (PayoutService — Story 4.5's real FIFO turnover tracker isn't built);
 * that path is unreachable anyway since WithdrawalFlow disables the radio option.
 */
export const payoutGateway = {
  async loadPayoutContext() {
    const context = await get<{
      winnings_balance_kobo: number; play_balance_kobo: number;
      destination_label: string; destination_name: string;
      turnover: { deposit_amount_kobo: number; staked_kobo: number; required_stake_kobo: number; released_play_balance_kobo: number };
      manual_review_threshold_kobo: number;
      payouts: ApiPayout[];
    }>("/payouts");

    return {
      winningsBalanceKobo: context.winnings_balance_kobo,
      playBalanceKobo: context.play_balance_kobo,
      destinationLabel: context.destination_label,
      destinationName: context.destination_name,
      turnover: {
        depositAmountKobo: context.turnover.deposit_amount_kobo,
        stakedKobo: context.turnover.staked_kobo,
        requiredStakeKobo: context.turnover.required_stake_kobo,
        releasedPlayBalanceKobo: context.turnover.released_play_balance_kobo,
      },
      manualReviewThresholdKobo: context.manual_review_threshold_kobo,
      payouts: context.payouts.map(toPayoutRecord),
    };
  },

  async quoteWithdrawal(source: "winnings" | "released-play", amountKobo: number) {
    const quote = await post<{
      quote_id: string; source: string; source_label: string; amount_kobo: number;
      fee_kobo: number; fee_verified: true; tax_already_handled: true;
      destination_label: string; destination_name: string; expected_timing: string;
      manual_review_required: boolean;
    }>("/payouts/quote", { source, amount_kobo: amountKobo });

    return {
      quoteId: quote.quote_id,
      source: quote.source as "winnings" | "released-play",
      sourceLabel: quote.source_label as "Winnings Balance" | "Released Play Balance",
      amountKobo: quote.amount_kobo,
      feeKobo: quote.fee_kobo,
      feeVerified: quote.fee_verified,
      taxAlreadyHandled: quote.tax_already_handled,
      destinationLabel: quote.destination_label,
      destinationName: quote.destination_name,
      expectedTiming: quote.expected_timing,
      manualReviewRequired: quote.manual_review_required,
    };
  },

  async requestWithdrawal(quoteId: string, idempotencyKey?: string) {
    const key = idempotencyKey ?? (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function" ? crypto.randomUUID() : undefined);
    const payout = await post<ApiPayout>(
      "/payouts",
      { quote_id: quoteId },
      { idempotencyKey: key },
    );

    return toPayoutRecord(payout);
  },
};
