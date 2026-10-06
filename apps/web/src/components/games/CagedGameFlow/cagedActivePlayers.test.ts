import { describe, expect, it } from "vitest";
import {
  CAGED_50_USERNAMES,
  createInitial50Players,
  getRandomStakeKobo,
  getRandomTargetMultiplierHundredths,
  settleCrashedRound,
  startNewRoundBets,
  updateFlyingCashouts,
} from "./cagedActivePlayers";

describe("cagedActivePlayers", () => {
  it("defines exactly 50 unique usernames", () => {
    expect(CAGED_50_USERNAMES).toHaveLength(50);
    const unique = new Set(CAGED_50_USERNAMES);
    expect(unique.size).toBe(50);
  });

  it("ensures random stakes are strictly between ₦1,000 and ₦150,000 (100,000 to 15,000,000 kobo)", () => {
    for (let i = 0; i < 100; i++) {
      const stakeKobo = getRandomStakeKobo();
      expect(stakeKobo).toBeGreaterThanOrEqual(100_000); // ₦1,000
      expect(stakeKobo).toBeLessThanOrEqual(15_000_000); // ₦150,000
    }
  });

  it("creates initial 50 players with valid stakes and winners", () => {
    const players = createInitial50Players();
    expect(players).toHaveLength(50);

    for (const player of players) {
      expect(player.stakeKobo).toBeGreaterThanOrEqual(100_000);
      expect(player.stakeKobo).toBeLessThanOrEqual(15_000_000);
      expect(player.username).toBeTruthy();
    }

    // Top player should have a payout >= 0 and list should be sorted
    expect(players[0].payoutKobo).toBeGreaterThanOrEqual(players[1].payoutKobo);
  });

  it("resets bets on new round with stakes within range", () => {
    const initial = createInitial50Players();
    const newRound = startNewRoundBets(initial);

    expect(newRound).toHaveLength(50);
    for (const p of newRound) {
      expect(p.cashedOut).toBe(false);
      expect(p.payoutKobo).toBe(0);
      expect(p.stakeKobo).toBeGreaterThanOrEqual(100_000);
      expect(p.stakeKobo).toBeLessThanOrEqual(15_000_000);
    }
  });

  it("triggers cashouts during flying phase when multiplier crosses target", () => {
    const initial = createInitial50Players();
    const reset = startNewRoundBets(initial);

    // Set one player's target explicitly
    reset[0].targetMultiplier = 2.0;

    // Below target
    const result1 = updateFlyingCashouts(reset, 180);
    expect(result1.players[0].cashedOut).toBe(false);

    // At/above target
    const result2 = updateFlyingCashouts(reset, 210);
    expect(result2.hasNewCashouts).toBe(true);
    expect(result2.players[0].cashedOut).toBe(true);
    expect(result2.players[0].payoutKobo).toBe(Math.round(reset[0].stakeKobo * 2.0));
  });

  it("settles crashed round correctly", () => {
    const initial = createInitial50Players();
    const reset = startNewRoundBets(initial);

    reset[0].targetMultiplier = 1.5;
    reset[1].targetMultiplier = 5.0;

    const settled = settleCrashedRound(reset, 200); // crashed at 2.00x
    const p1 = settled.find((p) => p.id === reset[0].id)!;
    const p2 = settled.find((p) => p.id === reset[1].id)!;

    expect(p1.cashedOut).toBe(true);
    expect(p1.lost).toBe(false);
    expect(p1.payoutKobo).toBeGreaterThan(0);

    expect(p2.cashedOut).toBe(false);
    expect(p2.lost).toBe(true);
    expect(p2.payoutKobo).toBe(0);
  });

  it("ensures all 50 usernames are masked phone numbers containing 'xx'", () => {
    expect(CAGED_50_USERNAMES).toHaveLength(50);
    for (const name of CAGED_50_USERNAMES) {
      expect(name).toMatch(/^0\d{3}xx\d{4}$/);
    }
  });

  it("never allows a player with 3.10x target to win if the game crashed at 3.01x", () => {
    const players = createInitial50Players();
    players[0].targetMultiplier = 3.1; // 3.10x
    players[0].cashedOut = false;
    players[1].targetMultiplier = 2.5; // 2.50x
    players[1].cashedOut = false;

    // Simulate round crashing at 3.01x (301 hundredths)
    const settled = settleCrashedRound(players, 301);

    const p310 = settled.find((p) => p.id === players[0].id)!;
    const p250 = settled.find((p) => p.id === players[1].id)!;

    // 3.10x is higher than 3.01x -> must have lost!
    expect(p310.cashedOut).toBe(false);
    expect(p310.lost).toBe(true);
    expect(p310.payoutKobo).toBe(0);

    // 2.50x is lower than 3.01x -> cashed out
    expect(p250.cashedOut).toBe(true);
    expect(p250.lost).toBe(false);
    expect(p250.payoutKobo).toBeGreaterThan(0);
  });

  it("arranges players strictly from highest stake to lowest stake across all rounds and crashes", () => {
    const players = createInitial50Players();
    for (let i = 0; i < players.length - 1; i++) {
      expect(players[i].stakeKobo).toBeGreaterThanOrEqual(players[i + 1].stakeKobo);
    }

    const newRound = startNewRoundBets(players);
    for (let i = 0; i < newRound.length - 1; i++) {
      expect(newRound[i].stakeKobo).toBeGreaterThanOrEqual(newRound[i + 1].stakeKobo);
    }

    const settled = settleCrashedRound(newRound, 250);
    for (let i = 0; i < settled.length - 1; i++) {
      expect(settled[i].stakeKobo).toBeGreaterThanOrEqual(settled[i + 1].stakeKobo);
    }
  });
});
