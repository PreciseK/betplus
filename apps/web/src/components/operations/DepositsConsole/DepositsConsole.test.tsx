import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { DepositsConsole } from "./DepositsConsole";

const deposits = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: { ...actual.backOfficeGateway, hasSession: () => true, deposits: (...args: unknown[]) => deposits(...args) },
  };
});

beforeEach(() => {
  vi.clearAllMocks();
  deposits.mockResolvedValue({
    deposits: [{
      id: 1, reference: "DEP-1", player_id: 4, player_reference: "BP-4", registered_name: "Ada Okafor",
      amount_kobo: 25_000, fee_kobo: 0, provider_collection_id: "OP-1", status: "paid",
      paid_at: "2026-08-20T12:00:00Z", created_at: "2026-08-20T11:00:00Z",
    }],
  });
});

describe("DepositsConsole", () => {
  it("shows real deposit rows from the real endpoint, not a fixture list", async () => {
    render(<DepositsConsole />);

    expect(await screen.findByText("DEP-1")).toBeInTheDocument();
    expect(screen.getByText("Ada Okafor")).toBeInTheDocument();
    expect(screen.getByText("₦250")).toBeInTheDocument();
    expect(deposits).toHaveBeenCalledWith(undefined);
  });
});
