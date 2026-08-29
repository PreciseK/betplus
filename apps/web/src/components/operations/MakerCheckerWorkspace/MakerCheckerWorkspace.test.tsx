import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { BackOfficeApiError } from "@betplus/api-client";
import { MakerCheckerWorkspace } from "./MakerCheckerWorkspace";

const changes = vi.fn();
const approveChange = vi.fn();
const rejectChange = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      changes: (...args: unknown[]) => changes(...args),
      approveChange: (...args: unknown[]) => approveChange(...args),
      rejectChange: (...args: unknown[]) => rejectChange(...args),
    },
  };
});

beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", { configurable: true, value() { this.setAttribute("open", ""); } });
  Object.defineProperty(HTMLDialogElement.prototype, "close", { configurable: true, value() { this.removeAttribute("open"); } });
});

const CHANGE = {
  id: 1, change_type: "prize_table_publish", status: "AWAITING_APPROVAL",
  payload: { multiplier: 20 }, before_snapshot: { multiplier: 18 },
  maker_id: 7, maker_justification: "New actuarial certification received.",
  submitted_at: "2026-08-01T00:00:00Z", checker_id: null, checker_decision_at: null,
  rejection_reason: null, applied_at: null,
};

beforeEach(() => {
  vi.clearAllMocks();
  changes.mockResolvedValue({ changes: [CHANGE] });
});

describe("MakerCheckerWorkspace", () => {
  it("shows the real change queue and a real field-by-field diff computed from payload/before_snapshot", async () => {
    render(<MakerCheckerWorkspace />);

    expect(await screen.findByRole("heading", { name: "prize_table_publish" })).toBeInTheDocument();
    expect(screen.getByText("18")).toBeInTheDocument();
    expect(screen.getByText("20")).toBeInTheDocument();
    expect(screen.getByText("New actuarial certification received.")).toBeInTheDocument();
  });

  it("surfaces the backend's real self-approval refusal rather than a client-guessed rule", async () => {
    approveChange.mockRejectedValueOnce(new BackOfficeApiError(422, { message: "A maker may not approve their own change." }));
    render(<MakerCheckerWorkspace />);
    await screen.findByRole("heading", { name: "prize_table_publish" });

    fireEvent.click(screen.getByRole("button", { name: "Approve change" }));
    fireEvent.click(within(screen.getByRole("dialog", { name: "Approve this change?" })).getByRole("button", { name: "Record approval" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("A maker may not approve their own change.");
    expect(approveChange).toHaveBeenCalledWith(1);
  });

  it("requires a rejection reason and calls the real reject endpoint", async () => {
    rejectChange.mockResolvedValueOnce({ ...CHANGE, status: "REJECTED", rejection_reason: "Legal directive attachment is not countersigned." });
    render(<MakerCheckerWorkspace />);
    await screen.findByRole("heading", { name: "prize_table_publish" });

    fireEvent.click(screen.getByRole("button", { name: "Reject change" }));
    const dialog = screen.getByRole("dialog", { name: "Reject this change?" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Record rejection" }));
    expect(within(dialog).getByRole("alert")).toHaveTextContent("Enter the reason");

    fireEvent.change(within(dialog).getByLabelText(/Rejection reason/), { target: { value: "Legal directive attachment is not countersigned." } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Record rejection" }));

    expect(await screen.findByRole("status")).toHaveTextContent("preserved");
    expect(rejectChange).toHaveBeenCalledWith(1, "Legal directive attachment is not countersigned.");
  });

  it("records a real approval and shows the applied state returned by the backend", async () => {
    approveChange.mockResolvedValueOnce({ ...CHANGE, status: "APPLIED", checker_id: 3, applied_at: "2026-08-02T00:00:00Z" });
    render(<MakerCheckerWorkspace />);
    await screen.findByRole("heading", { name: "prize_table_publish" });

    fireEvent.click(screen.getByRole("button", { name: "Approve change" }));
    fireEvent.click(within(screen.getByRole("dialog", { name: "Approve this change?" })).getByRole("button", { name: "Record approval" }));

    expect(await screen.findByRole("status")).toHaveTextContent("approved and applied");
    expect(screen.getByRole("heading", { name: "Immutable application record" })).toBeInTheDocument();
    expect(screen.getByText("Institution user #3")).toBeInTheDocument();
  });
});
