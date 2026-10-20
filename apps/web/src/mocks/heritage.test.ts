import { describe, expect, it } from "vitest";
import { mockHeritageGateway } from "./heritage";

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
