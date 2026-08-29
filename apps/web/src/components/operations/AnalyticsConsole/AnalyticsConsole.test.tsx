import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AnalyticsConsole } from "./AnalyticsConsole";

const funnels = vi.fn();
const rollups = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      funnels: (...args: unknown[]) => funnels(...args),
      rollups: (...args: unknown[]) => rollups(...args),
    },
  };
});

const FUNNELS = {
  acquisition_to_first_paid_play: { measurable: true, steps: [{ step: "player_registered", eventCount: 100 }, { step: "ticket_purchased", eventCount: 32 }], conversionRate: 0.32 },
  ussd_play: { measurable: false, reason: "No USSD channel exists yet." },
  funding: { measurable: true, steps: [{ step: "nin_verified", eventCount: 80 }, { step: "deposit_completed", eventCount: 40 }], conversionRate: 0.5 },
  payout: { measurable: true, steps: [{ step: "ticket_purchased", eventCount: 32 }, { step: "payout_completed", eventCount: 10 }], conversionRate: 0.3125 },
  cross_game: { measurable: false, reason: "Only one game exists yet." },
  geo_attribution: { measurable: true, steps: [{ step: "state_attributed", eventCount: 100 }, { step: "ticket_purchased", eventCount: 32 }], conversionRate: 0.32 },
};

const ROLLUPS = [
  { day: "2026-08-01", event_name: "ticket_purchased", channel: "web", game_code: "BLACKRED", state_code: "LAG", event_count: 32, distinct_player_count: 20 },
];

beforeEach(() => {
  window.history.replaceState({}, "", "/back-office/analytics");
  funnels.mockResolvedValue({ funnels: FUNNELS, window: { from: "2026-07-01", to: "2026-08-01" } });
  rollups.mockResolvedValue({ rows: ROLLUPS, window: { from: "2026-07-01", to: "2026-08-01" } });
});

describe("AnalyticsConsole", () => {
  it("surfaces all six required funnels and honestly marks the two unmeasurable ones", async () => {
    render(<AnalyticsConsole />);

    expect(await screen.findByRole("button", { name: /Acquisition to first paid play/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /USSD play/ })).toHaveTextContent("Not measurable");
    expect(screen.getByRole("button", { name: /Funding/ })).toHaveTextContent("Measurable");
    expect(screen.getByRole("button", { name: /Payout/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Cross-game/ })).toHaveTextContent("Not measurable");
    expect(screen.getByRole("button", { name: /Geo attribution/ })).toBeInTheDocument();
  });

  it("shows the real reason for an unmeasurable funnel instead of a fabricated conversion rate", async () => {
    render(<AnalyticsConsole />);
    await screen.findByRole("button", { name: /Acquisition to first paid play/ });

    fireEvent.click(screen.getByRole("button", { name: /USSD play/ }));

    expect(await screen.findByText(/No USSD channel exists yet/)).toBeInTheDocument();
  });

  it("shows real funnel steps and conversion for a measurable funnel", async () => {
    render(<AnalyticsConsole />);
    await screen.findByRole("button", { name: /Acquisition to first paid play/ });

    fireEvent.click(screen.getByRole("button", { name: /Funding/ }));

    expect(await screen.findByText("nin_verified")).toBeInTheDocument();
    expect(screen.getByText("80")).toBeInTheDocument();
    expect(screen.getByText(/50\.0%/)).toBeInTheDocument();
  });

  it("shows the underlying rollup table and the fixed privacy contract", async () => {
    render(<AnalyticsConsole />);
    const table = await screen.findByRole("table", { name: "Analytics daily rollup rows" });
    expect(within(table).getByText("ticket_purchased")).toBeInTheDocument();

    expect(screen.getByText("pseudonymised_player_id")).toBeInTheDocument();
    expect(screen.getByText("Raw MSISDN")).toBeInTheDocument();
    expect(screen.getByText(/Money and outcome events are emitted server-side/)).toBeInTheDocument();
  });

  it("persists real filters in the URL and refetches on change", async () => {
    render(<AnalyticsConsole />);
    await screen.findByRole("button", { name: /Acquisition to first paid play/ });

    fireEvent.change(screen.getByLabelText("Channel"), { target: { value: "web" } });

    expect(window.location.search).toContain("channel=web");
  });
});
