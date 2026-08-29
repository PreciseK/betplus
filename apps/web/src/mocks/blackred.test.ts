import { describe, expect, it } from "vitest";
import { mockBlackRedGateway } from "./blackred";

describe("mockBlackRedGateway", () => {
  it("does not leak outcome fields from the purchase response", async () => {
    const purchase = await mockBlackRedGateway.purchaseTicket({
      prediction: ["B", "R"],
      stakeKobo: 100_000,
      idempotencyKey: "test-purchase",
    });

    expect(purchase.status).toBe("purchased");
    expect(purchase).not.toHaveProperty("result");
    expect(purchase).not.toHaveProperty("won");
    expect(purchase).not.toHaveProperty("grossPrizeKobo");
  });

  it("reveals the complete predetermined settlement in one response", async () => {
    const purchase = await mockBlackRedGateway.purchaseTicket({
      prediction: ["B", "R"],
      stakeKobo: 100_000,
      idempotencyKey: "test-reveal",
    });
    const settlement = await mockBlackRedGateway.revealTicket(purchase.reference);

    expect(settlement.result).toEqual(["B", "R"]);
    expect(settlement.won).toBe(true);
    expect(settlement.taxWithheldKobo).toBe(50_000);
    expect(settlement.netCreditKobo).toBe(950_000);
  });
});
