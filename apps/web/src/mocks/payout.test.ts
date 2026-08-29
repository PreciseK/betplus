import { describe, expect, it } from "vitest";
import { mockPayoutGateway, normalizePayoutStatus } from "./payout";

describe("payout status normalization", () => {
  it.each([
    ["INITIAL", "requested"],
    ["PENDING", "processing"],
    ["CHECKING", "processing"],
    ["SUCCESS", "paid"],
    ["FAIL", "needs-attention"],
    ["CLOSE", "needs-attention"],
    ["RETURN", "needs-attention"],
  ] as const)("maps %s to %s", (providerStatus, expected) => {
    expect(normalizePayoutStatus(providerStatus)).toBe(expected);
  });

  it("routes an unrecognised provider status to manual review instead of failure", () => {
    expect(normalizePayoutStatus("PROVIDER_REVIEW_42")).toBe("manual-review");
  });

  it("quotes money using integer kobo and flags threshold review", async () => {
    const quote = await mockPayoutGateway.quoteWithdrawal("winnings", 150_000);
    expect(quote.amountKobo).toBe(150_000);
    expect(Number.isSafeInteger(quote.amountKobo)).toBe(true);
    expect(quote.manualReviewRequired).toBe(true);
    expect(quote.taxAlreadyHandled).toBe(true);
  });
});
