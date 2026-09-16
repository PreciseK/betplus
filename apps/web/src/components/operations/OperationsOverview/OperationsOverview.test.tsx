import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { GameScopeSelector } from "@/components/operations/GameScopeSelector/GameScopeSelector";
import { OperationsOverview } from "./OperationsOverview";

describe("OperationsOverview", () => {
  beforeEach(() => {
    window.history.replaceState({}, "", "/back-office/overview");
    window.sessionStorage.clear();
  });
  afterEach(() => vi.unstubAllGlobals());

  it("renders role-specific finance decisions and exact analytics values", () => {
    render(<OperationsOverview role="finance" />);
    expect(screen.getByRole("heading", { name: "Today at a Glance" })).toBeInTheDocument();
    expect(screen.getByText(/Finance overview/)).toBeInTheDocument();
    expect(screen.getByText("Open variance")).toBeInTheDocument();
    fireEvent.click(screen.getByText("View exact figures"));
    expect(screen.getByRole("table", { name: /exact values for settled value and exceptions/i })).toBeInTheDocument();
  });

  it("lets Super Admin preview another role without changing session scope", () => {
    render(<OperationsOverview role="super-admin" />);
    fireEvent.change(screen.getByLabelText("Role view"), { target: { value: "compliance" } });
    expect(screen.getByText(/Compliance overview/)).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Player safety" })).toBeInTheDocument();
    expect(window.location.search).toContain("viewRole=compliance");
  });

  it("scopes the dashboard to one game and exposes previous-day data", () => {
    window.history.replaceState({}, "", "/back-office/overview?game=blackred");
    render(<OperationsOverview role="super-admin" />);

    expect(screen.getByText(/BlackRed · All operations/)).toBeInTheDocument();
    expect(screen.queryByText(/Heritage catalogue item published/)).not.toBeInTheDocument();
    expect(screen.getAllByText(/BlackRed only/).length).toBeGreaterThan(0);

    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    const yesterdayIso = yesterday.toISOString().slice(0, 10);
    const yesterdayLabel = new Intl.DateTimeFormat("en-NG", { day: "numeric", month: "short" }).format(yesterday);

    fireEvent.click(screen.getByRole("button", { name: /Previous/ }));
    expect(screen.getByRole("heading", { name: `${yesterdayLabel} at a Glance` })).toBeInTheDocument();
    expect(window.location.search).toContain(`date=${yesterdayIso}`);
  });

  it("updates the dashboard from the global header game selector", () => {
    render(<><GameScopeSelector /><OperationsOverview role="super-admin" /></>);

    fireEvent.change(screen.getByRole("combobox", { name: "Game" }), { target: { value: "blackred" } });

    expect(screen.getByText(/BlackRed · All operations/)).toBeInTheDocument();
    expect(screen.queryByText(/Heritage catalogue item published/)).not.toBeInTheDocument();
    expect(window.location.search).toContain("game=blackred");
  });

  it("composes live role metrics from the bounded back-office endpoints", async () => {
    window.sessionStorage.setItem("betplus.operator.access-token", "institution-token");
    const apiResponse = (body: unknown) => Promise.resolve(new Response(JSON.stringify(body), {
      status: 200,
      headers: { "Content-Type": "application/json" },
    }));
    const fetchMock = vi.fn((input: string | URL | Request) => {
      const url = String(input);
      if (url.includes("/analytics/rollups")) return apiResponse({ window: { from: "2026-08-14", to: "2026-08-20" }, rows: [
        { day: "2026-08-20", event_name: "deposit_completed", channel: "web", game_code: null, state_code: null, event_count: 4, distinct_player_count: 4 },
        { day: "2026-08-20", event_name: "payout_completed", channel: "web", game_code: null, state_code: null, event_count: 2, distinct_player_count: 2 },
      ] });
      if (url.includes("/changes")) return apiResponse({ changes: [] });
      if (url.includes("/games")) return apiResponse({ games: [] });
      if (url.includes("/jurisdictions")) return apiResponse({ states: [] });
      if (url.includes("/reconciliation/exceptions")) return apiResponse({ exceptions: [{
        id: 7, check_type: "provider_mismatch", subject_id: "OP-7", expected_kobo: 10000,
        actual_kobo: 9000, difference_kobo: 1000, severity: "attention", status: "open",
        detected_at: "2026-08-20T09:00:00.000Z", resolved_at: null,
      }] });
      return apiResponse({});
    });
    vi.stubGlobal("fetch", fetchMock);

    render(<OperationsOverview role="finance" />);

    await waitFor(() => expect(screen.getByText(/Live endpoint data/)).toBeInTheDocument());
    expect(screen.getAllByText("Deposits").length).toBeGreaterThan(0);
    expect(screen.getByText("Open exceptions")).toBeInTheDocument();
    expect(screen.getByText("Provider Mismatch")).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining("/backoffice/v1/analytics/rollups"), expect.objectContaining({
      headers: expect.objectContaining({ Authorization: "Bearer institution-token" }),
    }));
  });
});
