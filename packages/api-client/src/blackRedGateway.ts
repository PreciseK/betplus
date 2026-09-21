import { ApiError, get, post } from "./http";

export type BlackRedEligibilityCode =
  | "LOCATION_UNVERIFIED"
  | "STATE_NOT_LICENSED"
  | "VPN_OR_PROXY_DETECTED"
  | "EXCLUDED"
  | "LIMIT_REACHED"
  | "INSUFFICIENT_PLAY_BALANCE"
  | "GAME_UNAVAILABLE";

/** Structurally the same shape as apps/web's BlackRedGatewayError (mocks/blackred.ts). */
export class BlackRedGatewayError extends Error {
  constructor(public readonly code: BlackRedEligibilityCode, message?: string) {
    super(message || code);
    this.name = "BlackRedGatewayError";
  }
}

type ApiTier = { positions: number; multiplier_hundredths: number; probability_numerator: number; probability_denominator: number };
type ApiColor = "B" | "R";

function toTier(t: ApiTier) {
  return {
    positions: t.positions as 1 | 2 | 3 | 4 | 5,
    multiplierHundredths: t.multiplier_hundredths,
    probabilityNumerator: t.probability_numerator as 1,
    probabilityDenominator: t.probability_denominator as 2 | 4 | 8 | 16 | 32,
  };
}

/**
 * Real Story 3.6/3.9 implementation of apps/web's BlackRedGateway
 * (apps/web/src/mocks/blackred.ts). Errors surfaced by the eligibility gate
 * (Domain/Ticket/TicketEligibilityException) arrive as {code, message} with HTTP 422
 * and are re-thrown here as BlackRedGatewayError so BlackRedGameFlow's existing
 * `error instanceof BlackRedGatewayError` handling needs no changes.
 */
export const blackRedGateway = {
  async loadGame() {
    const game = await get<{
      game_name: "BlackRed"; description: "Instant fixed-odds prediction";
      play_balance_kobo: number; winnings_balance_kobo: number;
      turnover_staked_kobo: number; turnover_required_kobo: number;
      daily_limit_kobo: number; daily_limit_remaining_kobo: number;
      min_stake_kobo: number; max_stake_kobo: number; currency: "NGN"; state_name: string;
      tax_rate_basis_points: number; tax_basis_label: string;
      ruleset_version: string; prize_table_version: string; engine_version: string;
      tiers: ApiTier[];
    }>("/games/blackred");

    return {
      gameName: game.game_name,
      description: game.description,
      playBalanceKobo: game.play_balance_kobo,
      winningsBalanceKobo: game.winnings_balance_kobo,
      turnoverStakedKobo: game.turnover_staked_kobo,
      turnoverRequiredKobo: game.turnover_required_kobo,
      dailyLimitKobo: game.daily_limit_kobo,
      dailyLimitRemainingKobo: game.daily_limit_remaining_kobo,
      minStakeKobo: game.min_stake_kobo,
      maxStakeKobo: game.max_stake_kobo,
      currency: game.currency,
      stateName: game.state_name as "Lagos",
      taxRateBasisPoints: game.tax_rate_basis_points,
      taxBasisLabel: game.tax_basis_label as "Gross prize",
      rulesetVersion: game.ruleset_version,
      prizeTableVersion: game.prize_table_version,
      engineVersion: game.engine_version,
      tiers: game.tiers.map(toTier),
    };
  },

  async purchaseTicket(input: { prediction: ApiColor[]; stakeKobo: number; idempotencyKey: string }) {
    try {
      const purchase = await post<{
        reference: string; purchased_at: string; prediction: ApiColor[]; stake_kobo: number;
        tier: ApiTier; play_balance_after_kobo: number; status: "purchased";
      }>("/tickets", {
        prediction: input.prediction,
        stake_kobo: input.stakeKobo,
        idempotency_key: input.idempotencyKey,
      });

      return {
        reference: purchase.reference,
        purchasedAt: purchase.purchased_at,
        prediction: purchase.prediction,
        stakeKobo: purchase.stake_kobo,
        tier: toTier(purchase.tier),
        playBalanceAfterKobo: purchase.play_balance_after_kobo,
        status: "purchased" as const,
      };
    } catch (error) {
      throw toBlackRedError(error);
    }
  },

  async revealTicket(reference: string) {
    try {
      const settlement = await get<{
        reference: string; purchased_at: string; prediction: ApiColor[]; stake_kobo: number;
        tier: ApiTier; play_balance_after_kobo: number; status: "settled";
        result: ApiColor[]; won: boolean; gross_prize_kobo: number; tax_withheld_kobo: number;
        net_credit_kobo: number; winnings_balance_after_kobo: number; tax_rate_basis_points: number;
        tax_basis_label: string; ruleset_version: string; prize_table_version: string;
        engine_version: string; state_name: string;
      }>(`/tickets/${reference}/reveal`);

      return {
        reference: settlement.reference,
        purchasedAt: settlement.purchased_at,
        prediction: settlement.prediction,
        stakeKobo: settlement.stake_kobo,
        tier: toTier(settlement.tier),
        playBalanceAfterKobo: settlement.play_balance_after_kobo,
        status: "settled" as const,
        result: settlement.result,
        won: settlement.won,
        grossPrizeKobo: settlement.gross_prize_kobo,
        taxWithheldKobo: settlement.tax_withheld_kobo,
        netCreditKobo: settlement.net_credit_kobo,
        winningsBalanceAfterKobo: settlement.winnings_balance_after_kobo,
        taxRateBasisPoints: settlement.tax_rate_basis_points,
        taxBasisLabel: settlement.tax_basis_label,
        rulesetVersion: settlement.ruleset_version,
        prizeTableVersion: settlement.prize_table_version,
        engineVersion: settlement.engine_version,
        stateName: settlement.state_name,
      };
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) throw new Error("TICKET_NOT_READY");
      throw toBlackRedError(error);
    }
  },
};

function toBlackRedError(error: unknown): Error {
  if (error instanceof ApiError && error.status === 422) {
    const code = typeof error.body.code === "string" ? (error.body.code as BlackRedEligibilityCode) : "GAME_UNAVAILABLE";
    const msg = typeof error.body.message === "string" ? error.body.message : undefined;
    return new BlackRedGatewayError(code, msg);
  }
  return error instanceof Error ? error : new Error("BLACKRED_REQUEST_FAILED");
}
