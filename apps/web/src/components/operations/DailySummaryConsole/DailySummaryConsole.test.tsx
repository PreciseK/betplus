import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { DailySummaryConsole } from "./DailySummaryConsole";

const dailySummary = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      dailySummary: (...args: unknown[]) => dailySummary(...args),
    },
  };
});

const SUMMARY = {
  date: "2026-08-20", game_code: null,
  gross_stakes_kobo: 100_000, gross_wins_kobo: 40_000, net_gaming_revenue_kobo: 60_000,
  deposits_kobo: 500_000, payouts_kobo: 200_000,
  new_players: 3, active_players: 5, verified_players_total: 120,
  reconciliation_exceptions: 1, payout_holds: 0, safer_play_reviews_opened: 2,
};

beforeEach(() => {
  window.history.replaceState({}, "", "/back-office/insights/daily-summary?date=2026-08-20");
  vi.clearAllMocks();
  dailySummary.mockResolvedValue(SUMMARY);
});

describe("DailySummaryConsole", () => {
  it("shows real money metrics fetched from the daily-summary endpoint for two real days", async () => {
    render(<DailySummaryConsole />);

    expect(await screen.findByText("Gross stakes")).toBeInTheDocument();
    expect(screen.getAllByText("₦1,000")[0]).toBeInTheDocument();
    expect(dailySummary).toHaveBeenCalledWith("2026-08-20", undefined);
    expect(dailySummary).toHaveBeenCalledWith("2026-08-19", undefined);
  });

  it("labels active players and verified players honestly rather than implying precision that isn't real", async () => {
    render(<DailySummaryConsole />);
    await screen.findByText("Gross stakes");

    fireEvent.click(screen.getByRole("button", { name: "Players" }));

    expect(await screen.findByText(/Distinct ticket purchasers that day/)).toBeInTheDocument();
    expect(screen.getByText(/Point-in-time total, not new verifications that day/)).toBeInTheDocument();
  });
});
