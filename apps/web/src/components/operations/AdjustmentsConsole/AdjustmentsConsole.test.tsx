import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AdjustmentsConsole } from "./AdjustmentsConsole";

const changes = vi.fn();
const proposeChange = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      changes: (...args: unknown[]) => changes(...args),
      proposeChange: (...args: unknown[]) => proposeChange(...args),
    },
  };
});

beforeEach(() => {
  vi.clearAllMocks();
  changes.mockResolvedValue({ changes: [] });
});

describe("AdjustmentsConsole", () => {
  it("filters the real changes endpoint to manual_credit_debit only", async () => {
    render(<AdjustmentsConsole />);

    await screen.findByText("No adjustments yet");
    expect(changes).toHaveBeenCalledWith(undefined, "manual_credit_debit");
  });

  it("proposes a real adjustment through the shared maker-checker endpoint", async () => {
    proposeChange.mockResolvedValueOnce({ id: 1, change_type: "manual_credit_debit", status: "AWAITING_APPROVAL", payload: {}, before_snapshot: null, maker_id: 1, maker_justification: "", submitted_at: null, checker_id: null, checker_decision_at: null, rejection_reason: null, applied_at: null });
    render(<AdjustmentsConsole />);
    await screen.findByText("No adjustments yet");

    fireEvent.change(screen.getByLabelText("Player ID"), { target: { value: "4" } });
    fireEvent.change(screen.getByLabelText("Amount (₦)"), { target: { value: "500" } });
    fireEvent.change(screen.getByLabelText("Justification"), { target: { value: "Compensating a support ticket." } });
    fireEvent.click(screen.getByRole("button", { name: "Propose adjustment" }));

    expect(await screen.findByRole("status")).toHaveTextContent("Adjustment proposed for player #4");
    expect(proposeChange).toHaveBeenCalledWith({
      change_type: "manual_credit_debit",
      payload: { player_id: 4, direction: "credit", balance: "PLAY", amount_kobo: 50_000 },
      justification: "Compensating a support ticket.",
    });
  });
});
