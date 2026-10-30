export type Round = { board: number[]; opened: number[]; deadline: number; stake: number; settled: boolean; serverReference?: string };

export const BOARD_SIZE = 20;
export const MAX_PICKS = 5;
export const ROUND_SECONDS = 10;
export const MIN_STAKE_NAIRA = 200;
export const MAX_STAKE_NAIRA = 500;
// Mirrors the published Doors distribution: one treasure per board, weighted by tier.
export const TREASURE_WEIGHTS = [{ multiplier: 3, weightBps: 5600 }, { multiplier: 5, weightBps: 2800 }, { multiplier: 2, weightBps: 1600 }];

export const naira = (amount: number) => new Intl.NumberFormat("en-NG", {
  style: "currency", currency: "NGN", maximumFractionDigits: 0,
}).format(amount);
export const treasureName = (multiplier: number) => multiplier === 3 ? "Gold bars" : multiplier === 5 ? "Treasure chest" : "Multiplier card";

/** Demo board with the same odds as the real game. `random` returns a value in [0, 1). */
export function makeBoard(random: () => number): number[] {
  const roll = random() * 10_000;
  let cumulative = 0;
  const multiplier = TREASURE_WEIGHTS.find(tier => roll < (cumulative += tier.weightBps))?.multiplier ?? TREASURE_WEIGHTS[0].multiplier;
  const board = Array.from({ length: BOARD_SIZE }, () => 0);
  board[Math.floor(random() * BOARD_SIZE)] = multiplier;
  return board;
}

/** Whole naira within the allowed range, or null when the typed value is not a valid stake. */
export function parseStake(value: string, min: number, max: number): number | null {
  if (!/^\d+$/.test(value.trim())) return null;
  const amount = Number(value);
  return amount >= min && amount <= max ? amount : null;
}

export function payout(round: Round) {
  const winningDoor = round.opened.find(index => round.board[index] > 0);
  return winningDoor === undefined ? 0 : round.board[winningDoor] * round.stake;
}
export function reveal(round: Round, index: number, now: number): Round {
  if (round.settled || now >= round.deadline || round.opened.length >= MAX_PICKS || round.opened.includes(index) || index < 0 || index >= round.board.length || !Number.isInteger(index)) return round;
  const opened = [...round.opened, index];
  return { ...round, opened, settled: round.board[index] > 0 || opened.length === MAX_PICKS };
}
