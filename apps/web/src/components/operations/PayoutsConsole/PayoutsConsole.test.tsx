import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { PayoutsConsole } from "./PayoutsConsole";

const payouts = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: { ...actual.backOfficeGateway, hasSession: () => true, payouts: (...args: unknown[]) => payouts(...args) },
  };
});

beforeEach(() => {
  vi.clearAllMocks();
  payouts.mockResolvedValue({
    payouts: [{
      id: 1, reference: "PO-1", player_id: 4, player_reference: "BP-4", registered_name: "Ada Okafor",
      kind: "withdrawal", amount_kobo: 40_000, destination_label: "OPay wallet", provider_status: "SUCCESS",
      manual_review_required: true, dispatched_at: null, confirmed_at: null, created_at: "2026-08-20T11:00:00Z",
    }],
  });
});

describe("PayoutsConsole", () => {
  it("shows real payout rows and no fabricated provider-float view", async () => {
    render(<PayoutsConsole />);

    expect(await screen.findByText("PO-1")).toBeInTheDocument();
    expect(screen.getByText("Needs review")).toBeInTheDocument();
    expect(screen.getByText(/no provider-float or reserve-balance table/)).toBeInTheDocument();
  });
});
