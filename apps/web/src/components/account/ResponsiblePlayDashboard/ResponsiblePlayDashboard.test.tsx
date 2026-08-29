import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeAll, describe, expect, it, vi } from "vitest";
import type { ResponsiblePlayGateway, ResponsiblePlaySnapshot } from "@/mocks/responsiblePlay";
import { ResponsiblePlayDashboard } from "./ResponsiblePlayDashboard";

const activeSnapshot: ResponsiblePlaySnapshot = {
  status: "active",
  withdrawalAvailable: true,
  netPositionKobo: { sevenDays: -390_000, thirtyDays: 125_000, ninetyDays: -1_840_000 },
  limits: [
    { key: "deposit-daily", label: "Daily deposit", scope: "Across every game", unit: "kobo", currentValue: 2_000_000 },
    { key: "stake-weekly", label: "Weekly stake", scope: "BlackRed and Heritage combined", unit: "kobo", currentValue: 5_000_000 },
    { key: "session-time", label: "Session time", scope: "Web and app", unit: "minutes", currentValue: 60 },
  ],
  coolOffOptions: [
    { id: "cool-off-24h", label: "24 hours", detail: "Until this time tomorrow", durationHours: 24 },
    { id: "cool-off-7d", label: "7 days", detail: "One full week", durationHours: 168 },
  ],
  selfExclusionOptions: [
    { id: "exclude-6m", label: "6 months", detail: "Minimum exclusion period", durationHours: 4392 },
    { id: "exclude-1y", label: "1 year", detail: "Twelve months", durationHours: 8760 },
  ],
};

beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", {
    value: vi.fn(function showModal(this: HTMLDialogElement) { this.open = true; }),
    configurable: true,
    writable: true,
  });
  Object.defineProperty(HTMLDialogElement.prototype, "close", {
    value: vi.fn(function close(this: HTMLDialogElement) { this.open = false; }),
    configurable: true,
    writable: true,
  });
});

function gateway(snapshot: ResponsiblePlaySnapshot = activeSnapshot): ResponsiblePlayGateway {
  return {
    load: vi.fn().mockResolvedValue(structuredClone(snapshot)),
    updateLimit: vi.fn().mockImplementation(async (key, value) => ({
      ...snapshot.limits.find((limit) => limit.key === key)!,
      currentValue: value,
    })),
    startCoolOff: vi.fn().mockResolvedValue({ status: "cool-off", statusEndsAt: "2026-08-23T10:00:00.000Z" }),
    selfExclude: vi.fn().mockResolvedValue({ status: "self-excluded", statusEndsAt: "2027-02-16T10:00:00.000Z" }),
  };
}

describe("ResponsiblePlayDashboard", () => {
  it("keeps true net position, every limit and withdrawal visible", async () => {
    render(<ResponsiblePlayDashboard gateway={gateway()} />);

    expect(await screen.findByRole("heading", { name: "Net position" })).toBeInTheDocument();
    expect(screen.getByText("Last 7 days")).toBeInTheDocument();
    expect(screen.getByText("Last 30 days")).toBeInTheDocument();
    expect(screen.getByText("Last 90 days")).toBeInTheDocument();
    expect(screen.getByText("Daily deposit")).toBeInTheDocument();
    expect(screen.getByText("Weekly stake")).toBeInTheDocument();
    expect(screen.getByText("Session time")).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: /withdraw/i }).length).toBeGreaterThan(0);
  });

  it("applies a lower limit immediately and explains the timing", async () => {
    const responsibleGateway = gateway();
    render(<ResponsiblePlayDashboard gateway={responsibleGateway} />);

    const row = (await screen.findByText("Daily deposit")).closest("div")?.parentElement;
    fireEvent.click(within(row!).getByRole("button", { name: "Change" }));
    fireEvent.change(screen.getByLabelText("New limit"), { target: { value: "10000" } });

    expect(screen.getByText("This lower limit takes effect immediately.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Save limit" }));

    expect(await screen.findByText("Lower limit active now")).toBeInTheDocument();
    expect(responsibleGateway.updateLimit).toHaveBeenCalledWith("deposit-daily", 1_000_000);
  });

  it("starts a seven-day cool-off only after a factual confirmation", async () => {
    const responsibleGateway = gateway();
    render(<ResponsiblePlayDashboard gateway={responsibleGateway} />);

    fireEvent.click(await screen.findByRole("button", { name: /7 days/ }));
    fireEvent.click(screen.getByRole("button", { name: "Start a break" }));

    const breakDialog = screen.getByRole("dialog", { name: "Start a 7 days break?" });
    expect(within(breakDialog).getByText(/Withdrawal remains available/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Start break" }));

    expect(await screen.findByText("Break started")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Break active" })).toBeInTheDocument();
    expect(responsibleGateway.startCoolOff).toHaveBeenCalledWith("cool-off-7d");
  });

  it("does not ask a registry-excluded player to self-exclude again", async () => {
    const excluded: ResponsiblePlaySnapshot = { ...activeSnapshot, status: "registry-excluded" };
    render(<ResponsiblePlayDashboard gateway={gateway(excluded)} />);

    expect(await screen.findByRole("heading", { name: "Play and deposits are unavailable" })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Self-exclusion" })).not.toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: /withdraw/i }).length).toBeGreaterThan(0);
  });

  it("keeps self-exclusion reversible only until the final confirmation", async () => {
    const responsibleGateway = gateway();
    render(<ResponsiblePlayDashboard gateway={responsibleGateway} />);

    fireEvent.click(await screen.findByRole("button", { name: /6 months/ }));
    fireEvent.click(screen.getByRole("button", { name: "Continue to self-exclusion" }));
    const exclusionDialog = screen.getByRole("dialog", { name: "Self-exclude for 6 months?" });
    expect(within(exclusionDialog).getByText(/cannot be shortened or cancelled/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Confirm self-exclusion" }));

    await waitFor(() => expect(responsibleGateway.selfExclude).toHaveBeenCalledWith("exclude-6m"));
    expect(await screen.findByRole("heading", { name: "Self-exclusion active" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Continue to self-exclusion" })).not.toBeInTheDocument();
  });
});
