import { describe, expect, it } from "vitest";
import { createHeritageSettlement, mockHeritageGateway } from "./heritage";

const BASE_INPUT = {
  selectedPositions: [1, 2, 3, 4, 5],
  traditionId: "yoruba",
  leader: "king" as const,
  stakeKobo: 100_000,
};

describe("mockHeritageGateway.placeTicket", () => {
  it("cycles through jackpot, high and loss tiers instead of always settling as a loss", async () => {
    // Regression test: the tier cycle strings must match the literal tier
    // values makeBoard()/createHeritageSettlement() compare against, or every
    // round silently falls through to the "loss" default (2 matches, 0 payout).
    const matchCounts = new Set<number>();
    for (let round = 0; round < 4; round += 1) {
      const settlement = await mockHeritageGateway.placeTicket(BASE_INPUT);
      matchCounts.add(settlement.matchCount);
    }
    expect(matchCounts.size).toBeGreaterThan(1);
    expect(matchCounts.has(5)).toBe(true); // the "jackpot" slot in the cycle
  });
});

describe("createHeritageSettlement board regalia placement", () => {
  it("reveals a complete 5-slot outfit on a jackpot, never the same piece twice", () => {
    for (let round = 0; round < 10; round += 1) {
      const settlement = createHeritageSettlement(BASE_INPUT, "jackpot");
      const matched = settlement.board.filter((tile) => tile.selected && tile.winning);

      expect(matched).toHaveLength(5);
      const slots = matched.map((tile) => tile.item.slot);
      expect(new Set(slots).size).toBe(5); // 5 distinct slots, no slot repeated
      const names = matched.map((tile) => tile.item.canonicalName);
      expect(new Set(names).size).toBe(5); // 5 distinct pieces, none repeated
    }
  });

  it("never repeats a piece among the 4 non-winning decoy tiles either", () => {
    const settlement = createHeritageSettlement(BASE_INPUT, "loss");
    const unmatched = settlement.board.filter((tile) => !(tile.selected && tile.winning));
    const names = unmatched.map((tile) => tile.item.canonicalName);
    expect(new Set(names).size).toBe(names.length);
  });
});
