import { describe, expect, it } from "vitest";
import { makeBoard, MAX_PICKS, parseStake, payout, reveal, type Round } from "./rules";

const fresh = (board: number[]): Round => ({ board, opened: [], deadline: 10000, stake: 200, settled: false });
describe("Doors first-treasure rounds", () => {
  it.each([2, 3, 5])("settles on the first %sx treasure and pays the stake multiplier once", multiplier => {
    const round = reveal(fresh([0, multiplier, 5]), 1, 100);
    expect(round.settled).toBe(true);
    expect(round.opened).toEqual([1]);
    expect(payout(round)).toBe(200 * multiplier);
    expect(reveal(round, 2, 110)).toBe(round);
    expect(payout(reveal(round, 1, 120))).toBe(200 * multiplier);
  });
  it("allows misses, but stops immediately when a later pick wins", () => {
    let round = fresh([0, 0, 3, 5]);
    round = reveal(round, 0, 100);
    expect(round.settled).toBe(false);
    round = reveal(round, 1, 110);
    round = reveal(round, 2, 120);
    expect(round.opened).toEqual([0, 1, 2]);
    expect(round.settled).toBe(true);
    expect(payout(round)).toBe(600);
  });
  it("ends after five empty attempts", () => {
    let round = fresh([0, 0, 0, 0, 0, 5]);
    for (let i = 0; i < MAX_PICKS; i++) round = reveal(round, i, 100 + i);
    expect(MAX_PICKS).toBe(5);
    expect(round.settled).toBe(true);
    expect(payout(round)).toBe(0);
    expect(reveal(round, 5, 150)).toBe(round);
  });
  it("rejects expired, duplicate, and invalid picks", () => {
    const round = fresh([0, 5]);
    expect(reveal(round, 1, round.deadline)).toBe(round);
    expect(reveal(round, -1, 10)).toBe(round);
    const opened = reveal(round, 0, 10);
    expect(reveal(opened, 0, 20)).toBe(opened);
  });
});

describe("Doors demo board", () => {
  it("hides exactly one treasure with the real game's tier weights", () => {
    // 0.99 lands past gold (56%) + chest (28%) into the 2x card tier.
    const board = makeBoard((() => { const rolls = [0.99, 0.5]; return () => rolls.shift()!; })());
    expect(board).toHaveLength(20);
    expect(board.filter(Boolean)).toEqual([2]);
    expect(board[10]).toBe(2);
    expect(makeBoard(() => 0).filter(Boolean)).toEqual([3]);
    expect(makeBoard(() => 0.7).filter(Boolean)).toEqual([5]);
  });
});

describe("Doors typed stake", () => {
  it("accepts whole naira within range and rejects everything else", () => {
    expect(parseStake("350", 200, 500)).toBe(350);
    expect(parseStake("200", 200, 500)).toBe(200);
    for (const bad of ["199", "501", "", "2.5", "abc", "-200"]) expect(parseStake(bad, 200, 500)).toBeNull();
  });
});
