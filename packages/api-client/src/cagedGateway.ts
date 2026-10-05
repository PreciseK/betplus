import { ApiError, get, post } from "./http";

export type CagedEligibilityCode =
  | "GAME_UNAVAILABLE"
  | "ROUND_NOT_ACCEPTING_BETS"
  | "LOCATION_UNVERIFIED"
  | "STATE_NOT_LICENSED"
  | "VPN_OR_PROXY_DETECTED"
  | "EXCLUDED"
  | "LIMIT_REACHED"
  | "INSUFFICIENT_PLAY_BALANCE";

/** Structurally the same shape as apps/web's CagedGatewayError (mocks/caged.ts). */
export class CagedGatewayError extends Error {
  constructor(public readonly code: CagedEligibilityCode) {
    super(code);
  }
}

export type CagedCashoutCode = "ROUND_NOT_FLYING" | "TOO_LATE_ROUND_CRASHED";

export class CagedCashoutError extends Error {
  constructor(public readonly code: CagedCashoutCode) {
    super(code);
  }
}

// Aliases for backwards compatibility
export type BirdEscapeEligibilityCode = CagedEligibilityCode;
export const BirdEscapeGatewayError = CagedGatewayError;
export type BirdEscapeCashoutCode = CagedCashoutCode;
export const BirdEscapeCashoutError = CagedCashoutError;

type ApiRoundPlayer = {
  username?: string | null;
  stake_kobo: number;
  status: "PLACED" | "CASHED_OUT" | "LOST";
  cashed_out_at_multiplier_hundredths: number | null;
};

type ApiMyBet = {
  bet_id: number;
  stake_kobo: number;
  auto_cashout_multiplier_hundredths: number | null;
  status: "PLACED" | "CASHED_OUT" | "LOST";
  cashed_out_at_multiplier_hundredths: number | null;
  gross_prize_kobo: number | null;
  tax_withheld_kobo: number | null;
  net_credit_kobo: number | null;
};

type ApiRecentRound = { round_number: number; crash_multiplier_hundredths: number; crashed_at: string };

function toMyBet(b: ApiMyBet) {
  return {
    betId: b.bet_id,
    stakeKobo: b.stake_kobo,
    autoCashoutMultiplierHundredths: b.auto_cashout_multiplier_hundredths,
    status: b.status,
    cashedOutAtMultiplierHundredths: b.cashed_out_at_multiplier_hundredths,
    grossPrizeKobo: b.gross_prize_kobo,
    taxWithheldKobo: b.tax_withheld_kobo,
    netCreditKobo: b.net_credit_kobo,
  };
}

/**
 * Real implementation of apps/web's CagedGateway (apps/web/src/mocks/caged.ts).
 * Never generates a crash point or invents a balance — every displayed number comes
 * from a server response, matching ConnectedBlackRedGameFlow's "no mock fallback on
 * error, this is a real-money engine" precedent.
 */
export const cagedGateway = {
  async loadCurrentRound() {
    let round;
    try {
      round = await get<{
        game_code: string;
        server_time: string;
        round_id: number;
        round_number: number;
        status: "BETTING" | "FLYING" | "CRASHED";
        betting_started_at: string;
        flight_started_at: string | null;
        betting_window_seconds: number;
        growth_rate_constant: number;
        commitment_digest: string;
        min_stake_kobo: number;
        max_stake_kobo: number;
        play_balance_kobo: number;
        winnings_balance_kobo: number;
        crash_multiplier_hundredths: number | null;
        seed_hex: string | null;
        recent_rounds: ApiRecentRound[];
        players: ApiRoundPlayer[];
        my_bets: ApiMyBet[];
      }>("/caged/rounds/current");
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        round = await get<any>("/birdescape/rounds/current");
      } else {
        throw err;
      }
    }

    return {
      gameCode: round.game_code,
      serverTime: round.server_time,
      roundId: round.round_id,
      roundNumber: round.round_number,
      status: round.status,
      bettingStartedAt: round.betting_started_at,
      flightStartedAt: round.flight_started_at,
      bettingWindowSeconds: round.betting_window_seconds,
      growthRateConstant: round.growth_rate_constant,
      commitmentDigest: round.commitment_digest,
      minStakeKobo: round.min_stake_kobo,
      maxStakeKobo: round.max_stake_kobo,
      playBalanceKobo: round.play_balance_kobo,
      winningsBalanceKobo: round.winnings_balance_kobo,
      crashMultiplierHundredths: round.crash_multiplier_hundredths,
      seedHex: round.seed_hex,
      recentRounds: round.recent_rounds.map((r: ApiRecentRound) => ({
        roundNumber: r.round_number,
        crashMultiplierHundredths: r.crash_multiplier_hundredths,
        crashedAt: r.crashed_at,
      })),
      players: round.players.map((p: ApiRoundPlayer, idx: number) => ({
        username: p.username || `Player #${idx + 1}`,
        stakeKobo: p.stake_kobo,
        status: p.status,
        cashedOutAtMultiplierHundredths: p.cashed_out_at_multiplier_hundredths,
      })),
      myBets: round.my_bets.map(toMyBet),
    };
  },

  async placeBet(input: {
    roundId: number;
    stakeKobo: number;
    autoCashoutMultiplierHundredths?: number;
    idempotencyKey: string;
  }) {
    try {
      let placed;
      try {
        placed = await post<{
          bet_id: number;
          round_number: number;
          stake_kobo: number;
          auto_cashout_multiplier_hundredths: number | null;
          play_balance_after_kobo: number;
          status: "placed";
        }>(
          `/caged/rounds/${input.roundId}/bets`,
          {
            stake_kobo: input.stakeKobo,
            auto_cashout_multiplier_hundredths: input.autoCashoutMultiplierHundredths ?? null,
            idempotency_key: input.idempotencyKey,
          },
          { idempotencyKey: input.idempotencyKey },
        );
      } catch (err) {
        if (err instanceof ApiError && err.status === 404) {
          placed = await post<{
            bet_id: number;
            round_number: number;
            stake_kobo: number;
            auto_cashout_multiplier_hundredths: number | null;
            play_balance_after_kobo: number;
            status: "placed";
          }>(
            `/birdescape/rounds/${input.roundId}/bets`,
            {
              stake_kobo: input.stakeKobo,
              auto_cashout_multiplier_hundredths: input.autoCashoutMultiplierHundredths ?? null,
              idempotency_key: input.idempotencyKey,
            },
            { idempotencyKey: input.idempotencyKey },
          );
        } else {
          throw err;
        }
      }

      return {
        betId: placed.bet_id,
        roundNumber: placed.round_number,
        stakeKobo: placed.stake_kobo,
        autoCashoutMultiplierHundredths: placed.auto_cashout_multiplier_hundredths,
        playBalanceAfterKobo: placed.play_balance_after_kobo,
        status: "placed" as const,
      };
    } catch (error) {
      throw toCagedError(error);
    }
  },

  async cashout(betId: number) {
    try {
      let settled;
      try {
        settled = await post<{
          bet_id: number;
          status: "PLACED" | "CASHED_OUT" | "LOST";
          cashed_out_at_multiplier_hundredths: number | null;
          gross_prize_kobo: number | null;
          tax_withheld_kobo: number | null;
          net_credit_kobo: number | null;
          winnings_balance_after_kobo: number;
        }>(`/caged/bets/${betId}/cashout`, {});
      } catch (err) {
        if (err instanceof ApiError && err.status === 404) {
          settled = await post<{
            bet_id: number;
            status: "PLACED" | "CASHED_OUT" | "LOST";
            cashed_out_at_multiplier_hundredths: number | null;
            gross_prize_kobo: number | null;
            tax_withheld_kobo: number | null;
            net_credit_kobo: number | null;
            winnings_balance_after_kobo: number;
          }>(`/birdescape/bets/${betId}/cashout`, {});
        } else {
          throw err;
        }
      }

      return {
        betId: settled.bet_id,
        status: settled.status,
        cashedOutAtMultiplierHundredths: settled.cashed_out_at_multiplier_hundredths,
        grossPrizeKobo: settled.gross_prize_kobo,
        taxWithheldKobo: settled.tax_withheld_kobo,
        netCreditKobo: settled.net_credit_kobo,
        winningsBalanceAfterKobo: settled.winnings_balance_after_kobo,
      };
    } catch (error) {
      throw toCashoutError(error);
    }
  },
};

export const birdEscapeGateway = cagedGateway;

function toCagedError(error: unknown): Error {
  if (error instanceof ApiError && error.status === 422 && typeof error.body.code === "string") {
    return new CagedGatewayError(error.body.code as CagedEligibilityCode);
  }
  return error instanceof Error ? error : new Error("CAGED_REQUEST_FAILED");
}

function toCashoutError(error: unknown): Error {
  if (error instanceof ApiError && error.status === 422 && typeof error.body.code === "string") {
    return new CagedCashoutError(error.body.code as CagedCashoutCode);
  }
  return error instanceof Error ? error : new Error("CAGED_CASHOUT_FAILED");
}
