import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { ReconciliationConsole } from "./ReconciliationConsole";

const reconciliation = vi.fn();
const resolveReconciliation = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      reconciliation: (...args: unknown[]) => reconciliation(...args),
      resolveReconciliation: (...args: unknown[]) => resolveReconciliation(...args),
    },
  };
});

beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", { configurable: true, value() { this.setAttribute("open", ""); } });
  Object.defineProperty(HTMLDialogElement.prototype, "close", { configurable: true, value() { this.removeAttribute("open"); } });
});

const EXCEPTION = {
  id: 4, check_type: "wallet_ledger_balance", subject_id: "PLAYER-991",
  expected_kobo: 500_000, actual_kobo: 480_000, difference_kobo: 20_000,
  severity: "p1", status: "open", detected_at: "2026-08-15T00:00:00Z", resolved_at: null,
};

beforeEach(() => {
  vi.clearAllMocks();
  reconciliation.mockResolvedValue({ exceptions: [EXCEPTION] });
});

describe("ReconciliationConsole", () => {
  it("shows the real exception queue with real severity and difference figures", async () => {
    render(<ReconciliationConsole />);
    const table = await screen.findByRole("table", { name: "Reconciliation exceptions requiring finance action" });
    expect(within(table).getByText("Wallet Ledger Balance")).toBeInTheDocument();
    expect(within(table).getByText("PLAYER-991")).toBeInTheDocument();
    expect(within(table).getByText("P1")).toBeInTheDocument();
    expect(within(table).getByText("₦200")).toBeInTheDocument();
    expect(reconciliation).toHaveBeenCalledWith("open");
  });

  it("shows the real ledger discrepancy detail for the selected exception", async () => {
    render(<ReconciliationConsole />);
    await screen.findByRole("table", { name: "Reconciliation exceptions requiring finance action" });

    expect(screen.getByRole("heading", { name: "Subject PLAYER-991" })).toBeInTheDocument();
    expect(screen.getByText("₦5,000")).toBeInTheDocument();
    expect(screen.getByText("₦4,800")).toBeInTheDocument();
  });

  it("marks an exception resolved via the real endpoint, with copy stating no money moves and no ledger entry is created", async () => {
    resolveReconciliation.mockResolvedValueOnce(undefined);
    render(<ReconciliationConsole />);
    await screen.findByRole("heading", { name: "Subject PLAYER-991" });

    fireEvent.click(screen.getByRole("button", { name: "Mark resolved" }));
    const dialog = screen.getByRole("dialog", { name: "Mark this exception resolved?" });
    expect(within(dialog).getByText(/does not move money or create a ledger entry/)).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole("button", { name: "Mark resolved" }));

    expect(await screen.findByRole("status")).toHaveTextContent("Exception #4 marked resolved");
    expect(resolveReconciliation).toHaveBeenCalledWith(4);
  });

  it("re-fetches from the real endpoint when the status filter changes", async () => {
    render(<ReconciliationConsole />);
    await screen.findByRole("table", { name: "Reconciliation exceptions requiring finance action" });

    reconciliation.mockResolvedValueOnce({ exceptions: [] });
    fireEvent.change(screen.getByLabelText("Status"), { target: { value: "resolved" } });

    expect(await screen.findByText("No exceptions in this status")).toBeInTheDocument();
    expect(reconciliation).toHaveBeenLastCalledWith("resolved");
  });
});
