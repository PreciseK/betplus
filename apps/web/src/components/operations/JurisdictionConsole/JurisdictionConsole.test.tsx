import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { JurisdictionConsole } from "./JurisdictionConsole";

const jurisdictions = vi.fn();
const upsertJurisdiction = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      jurisdictions: (...args: unknown[]) => jurisdictions(...args),
      upsertJurisdiction: (...args: unknown[]) => upsertJurisdiction(...args),
    },
  };
});

beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", { configurable: true, value() { this.setAttribute("open", ""); } });
  Object.defineProperty(HTMLDialogElement.prototype, "close", { configurable: true, value() { this.removeAttribute("open"); } });
});

beforeEach(() => {
  vi.clearAllMocks();
  jurisdictions.mockResolvedValue({
    states: [
      { state_code: "LAG", licence_number: "LAG-2026-01", issued_at: "2026-01-01", expires_at: "2027-01-01", is_expired: false, expiry_alert: false, ruleset_version: "2026.1", remittance_status: "current", activity_volume: 120, resident_wht_rate_basis_points: 500, non_resident_wht_rate_basis_points: 1000 },
      { state_code: "KAN", licence_number: "KAN-2025-01", issued_at: "2025-01-01", expires_at: "2026-01-01", is_expired: true, expiry_alert: false, ruleset_version: "2025.1", remittance_status: "overdue", activity_volume: 5, resident_wht_rate_basis_points: 500, non_resident_wht_rate_basis_points: 1000 },
    ],
  });
});

describe("JurisdictionConsole", () => {
  it("shows real licence, ruleset and activity data by state", async () => {
    render(<JurisdictionConsole />);
    const table = await screen.findByRole("table", { name: /Jurisdiction licence/ });
    expect(within(table).getByText("LAG")).toBeInTheDocument();
    expect(within(table).getByText("KAN")).toBeInTheDocument();
    expect(within(table).getByText("120 tickets")).toBeInTheDocument();
  });

  it("flags an expired licence rather than a fabricated countdown", async () => {
    render(<JurisdictionConsole />);
    const table = await screen.findByRole("table", { name: /Jurisdiction licence/ });
    const kanRow = within(table).getByText("KAN").closest("tr");
    expect(within(kanRow!).getByText("Expired")).toBeInTheDocument();
  });

  it("updates a licence record via the real endpoint and shows it applies immediately", async () => {
    upsertJurisdiction.mockResolvedValueOnce({ state_code: "LAG", expires_at: "2027-06-01" });
    render(<JurisdictionConsole />);
    await screen.findByRole("table", { name: /Jurisdiction licence/ });

    fireEvent.click(screen.getAllByRole("button", { name: "Inspect" })[0]);
    fireEvent.click(screen.getByRole("button", { name: "Update licence" }));
    const dialog = screen.getByRole("dialog", { name: /Update LAG licence/ });
    fireEvent.change(within(dialog).getByLabelText(/Licence number/), { target: { value: "LAG-2026-02" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Save licence record" }));

    expect(await screen.findByRole("status")).toHaveTextContent("applies immediately");
    expect(upsertJurisdiction).toHaveBeenCalledWith(expect.objectContaining({ state_code: "LAG", licence_number: "LAG-2026-02" }));
  });

  it("does not offer a tax-ruleset change proposal — no backend workflow exists for it", async () => {
    render(<JurisdictionConsole />);
    await screen.findByRole("table", { name: /Jurisdiction licence/ });
    expect(screen.queryByRole("button", { name: /Propose ruleset change/ })).not.toBeInTheDocument();
  });
});
