import { get, post } from "./http";

export type DoorsWallet = { play_balance_kobo: number; bonus_balance_kobo: number; winnings_balance_kobo: number };
export type DoorsRoundResponse = {
  reference: string; status: "in_play" | "settled"; currency: "NGN";
  stake_kobo: number; remaining_ms: number; expires_at: string; server_time: string;
  duration_seconds: number; max_attempts: number; board_size: number;
  opened: { index: number; multiplier: number }[];
  commitment: string; seed_hex: string | null; prize_table_version: string; engine_version: string;
  gross_prize_kobo: number; net_prize_kobo: number; tax_withheld_kobo: number;
  tax_rate_basis_points: number; tax_basis_label: string; wallet: DoorsWallet;
};
export type DoorsContext = {
  game_code: "DOORS"; currency: "NGN"; available: boolean;
  min_stake_kobo: number | null; max_stake_kobo: number | null;
  economics_model: "BALANCED_HYBRID"; prize_table_version: string | null;
  rules: { board_size: number; max_attempts: number; durations_seconds: number[]; treasures: { code: string; multiplier: number; weight_bps: number }[] } | null;
  wallet: DoorsWallet; active_round: DoorsRoundResponse | null; latest_round: DoorsRoundResponse | null;
};

export const doorsGateway = {
  loadGame: () => get<DoorsContext>("/games/doors"),
  // One round-trip each: the round clock is already running. A retried key can replay an
  // older snapshot, so callers re-read the round after a retry (see DoorsDemo.runPending).
  startRound: (input: { stakeKobo: number; durationSeconds: number; idempotencyKey: string }) =>
    post<DoorsRoundResponse>("/doors/rounds", {
      stake_kobo: input.stakeKobo, duration_seconds: input.durationSeconds, idempotency_key: input.idempotencyKey,
    }, { idempotencyKey: input.idempotencyKey }),
  openDoor: (reference: string, index: number, key: string) =>
    post<DoorsRoundResponse>(`/doors/rounds/${encodeURIComponent(reference)}/open`, {
      door_index: index, idempotency_key: key,
    }, { idempotencyKey: key }),
  readRound: (reference: string) => get<DoorsRoundResponse>(`/doors/rounds/${encodeURIComponent(reference)}`),
};
export type DoorsGateway = typeof doorsGateway;
