import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import type { PayoutContext, PayoutGateway, PayoutRecord } from "@/mocks/payout";
import { WithdrawalFlow } from "./WithdrawalFlow";

const context: PayoutContext = {
  winningsBalanceKobo: 185_000,
  playBalanceKobo: 640_000,
  destinationLabel: "OPay wallet ending 5678",
  destinationName: "Adaeze Okafor",
  turnover: { depositAmountKobo: 1_000_000, stakedKobo: 420_000, requiredStakeKobo: 1_000_000, releasedPlayBalanceKobo: 0 },
  manualReviewThresholdKobo: 100_000,
  payouts: [],
};

const payout: PayoutRecord = {
  reference: "BP-PO-TEST-WD",
  kind: "withdrawal",
  createdAt: "2026-08-15T09:15:00.000Z",
  amountKobo: 50_000,
  sourceLabel: "Winnings Balance",
  destinationLabel: context.destinationLabel,
  providerStatus: "PENDING",
  displayStatus: "processing",
  statusExpectation: "Usually confirmed within 90 seconds.",
  fundsRemainInWinnings: true,
  statusHistory: [],
};

function gateway(): PayoutGateway {
  return {
    loadPayoutContext: vi.fn(),
    quoteWithdrawal: vi.fn().mockImplementation(async (source, amountKobo) => ({
      quoteId: `quote-${amountKobo}`,
      source,
      sourceLabel: "Winnings Balance",
      amountKobo,
      feeKobo: 0,
      feeVerified: true,
      taxAlreadyHandled: true,
      destinationLabel: context.destinationLabel,
      destinationName: context.destinationName,
      expectedTiming: "Usually within 90 seconds after submission",
      manualReviewRequired: amountKobo >= context.manualReviewThresholdKobo,
    })),
    requestWithdrawal: vi.fn().mockResolvedValue(payout),
  };
}

async function reachAmount(payoutGateway: PayoutGateway) {
  render(<WithdrawalFlow context={context} gateway={payoutGateway} onComplete={vi.fn()} onCancel={vi.fn()} />);
  // OPay is selected by default, click Continue
  fireEvent.click(screen.getByRole("button", { name: "Continue" }));
  await screen.findByRole("heading", { name: /How much to withdraw/i });
}

describe("WithdrawalFlow", () => {
  it("shows the correct available balance and destination cards", () => {
    render(<WithdrawalFlow context={context} gateway={gateway()} onComplete={vi.fn()} onCancel={vi.fn()} />);

    expect(screen.getByText(/Available in Winnings/i)).toBeInTheDocument();
    expect(screen.getByText(/₦1,850/i)).toBeInTheDocument();
    expect(screen.getByText(/Move to Play Balance/i)).toBeInTheDocument();
    expect(screen.getByText(/Withdraw to OPay/i)).toBeInTheDocument();
  });

  it("shows destination, fee, tax, timing and exact amount before submission", async () => {
    const payoutGateway = gateway();
    await reachAmount(payoutGateway);
    fireEvent.change(screen.getByPlaceholderText("0"), { target: { value: "500" } });
    fireEvent.submit(screen.getByRole("button", { name: "Continue" }));

    await screen.findByText("You're about to");
    expect(screen.getByText(/to your registered OPay/i)).toBeInTheDocument();
    expect(screen.getByText("FREE")).toBeInTheDocument();
    expect(screen.getAllByText(/500\.00/i).length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: "Confirm withdrawal" })).toBeInTheDocument();
    expect(payoutGateway.requestWithdrawal).not.toHaveBeenCalled();
  });

  it("keeps a processing withdrawal available by durable reference", async () => {
    const payoutGateway = gateway();
    const onComplete = vi.fn();
    render(<WithdrawalFlow context={context} gateway={payoutGateway} onComplete={onComplete} onCancel={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "Continue" }));
    fireEvent.change(await screen.findByPlaceholderText("0"), { target: { value: "500" } });
    fireEvent.submit(screen.getByRole("button", { name: "Continue" }));
    fireEvent.click(await screen.findByRole("button", { name: "Confirm withdrawal" }));

    await screen.findByText("Withdrawal submitted");
    expect(screen.getByText("BP-PO-TEST-WD")).toBeInTheDocument();
    expect(onComplete).toHaveBeenCalledWith(payout);
  });
});
