import { fireEvent, render, screen } from "@testing-library/react";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { DepositsConsole } from "./DepositsConsole";

const deposits = vi.fn();
const approveDeposit = vi.fn();
const rejectDeposit = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      deposits: (...args: unknown[]) => deposits(...args),
      approveDeposit: (...args: unknown[]) => approveDeposit(...args),
      rejectDeposit: (...args: unknown[]) => rejectDeposit(...args),
    },
  };
});

// jsdom doesn't implement <dialog> — same polyfill ReconciliationConsole/
// MakerCheckerWorkspace's tests use for the shared Dialog component.
beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", { configurable: true, value() { this.setAttribute("open", ""); } });
  Object.defineProperty(HTMLDialogElement.prototype, "close", { configurable: true, value() { this.removeAttribute("open"); } });
});

const PAID_DEPOSIT = {
  id: 1, reference: "DEP-1", player_id: 4, player_reference: "BP-4", registered_name: "Ada Okafor",
  amount_kobo: 25_000, fee_kobo: 0, provider_collection_id: "OP-1", status: "paid",
  paid_at: "2026-08-20T12:00:00Z", created_at: "2026-08-20T11:00:00Z",
};

const PENDING_REVIEW_DEPOSIT = {
  id: 2, reference: "DEP-2", player_id: 5, player_reference: "BP-5", registered_name: "Bola Tinubu",
  amount_kobo: 6_000_000, fee_kobo: 0, provider_collection_id: null, status: "pending_review",
  paid_at: null, created_at: "2026-08-20T11:05:00Z",
};

beforeEach(() => {
  vi.clearAllMocks();
  deposits.mockResolvedValue({ deposits: [PAID_DEPOSIT] });
});

describe("DepositsConsole", () => {
  it("shows real deposit rows from the real endpoint, not a fixture list", async () => {
    render(<DepositsConsole />);

    expect(await screen.findByText("DEP-1")).toBeInTheDocument();
    expect(screen.getByText("Ada Okafor")).toBeInTheDocument();
    expect(screen.getByText("₦250")).toBeInTheDocument();
    expect(deposits).toHaveBeenCalledWith(undefined);
  });

  it("does not show approve/reject actions on a paid deposit", async () => {
    render(<DepositsConsole />);

    await screen.findByText("DEP-1");
    expect(screen.queryByRole("button", { name: "Approve" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Reject" })).not.toBeInTheDocument();
  });

  it("approves a pending_review deposit through the confirmation dialog", async () => {
    deposits.mockResolvedValue({ deposits: [PENDING_REVIEW_DEPOSIT] });
    approveDeposit.mockResolvedValue({ ...PENDING_REVIEW_DEPOSIT, status: "paid" });
    render(<DepositsConsole />);
    await screen.findByText("DEP-2");

    fireEvent.click(screen.getByRole("button", { name: "Approve" }));
    fireEvent.click(await screen.findByRole("button", { name: "Approve and credit" }));

    expect(approveDeposit).toHaveBeenCalledWith(2);
    expect(await screen.findByText(/approved and credited/i)).toBeInTheDocument();
  });

  it("requires a reason before rejecting a pending_review deposit", async () => {
    deposits.mockResolvedValue({ deposits: [PENDING_REVIEW_DEPOSIT] });
    render(<DepositsConsole />);
    await screen.findByText("DEP-2");

    fireEvent.click(screen.getByRole("button", { name: "Reject" }));
    fireEvent.click(await screen.findByRole("button", { name: "Record rejection" }));

    expect(await screen.findByText(/enter the reason/i)).toBeInTheDocument();
    expect(rejectDeposit).not.toHaveBeenCalled();
  });

  it("rejects a pending_review deposit with a reason and never credits it", async () => {
    deposits.mockResolvedValue({ deposits: [PENDING_REVIEW_DEPOSIT] });
    rejectDeposit.mockResolvedValue({ ...PENDING_REVIEW_DEPOSIT, status: "failed" });
    render(<DepositsConsole />);
    await screen.findByText("DEP-2");

    fireEvent.click(screen.getByRole("button", { name: "Reject" }));
    fireEvent.change(await screen.findByLabelText(/Rejection reason/i), { target: { value: "Could not confirm this transfer." } });
    fireEvent.click(screen.getByRole("button", { name: "Record rejection" }));

    expect(rejectDeposit).toHaveBeenCalledWith(2, "Could not confirm this transfer.");
    expect(await screen.findByText(/rejected. It was never credited/i)).toBeInTheDocument();
  });
});
