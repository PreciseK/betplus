import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { FundingFlow } from "./FundingFlow";
import type { MoneyTransaction, WalletGateway } from "@/mocks/wallet";

const transaction: MoneyTransaction = {
  reference: "BP-TEST-001",
  type: "OPay deposit",
  provider: "OPay",
  occurredAt: "2026-08-14T14:18:00.000Z",
  amountKobo: 250_000,
  feeKobo: 0,
  status: "paid",
  statusHistory: [],
};

function gateway(): WalletGateway {
  return {
    loadWallet: vi.fn(),
    quoteFunding: vi.fn().mockResolvedValue({
      quoteId: "quote-1",
      amountKobo: 250_000,
      feeKobo: 0,
      feeVerified: true,
      sourceLabel: "OPay wallet ending 5678",
      destinationLabel: "Play Balance",
      expectedTiming: "Usually within 2 minutes after OPay confirms payment",
      reversible: false,
    }),
    createCollection: vi.fn().mockResolvedValue({ collectionId: "collection-1", otpRequired: true }),
    submitCollectionOtp: vi.fn().mockResolvedValue(transaction),
  };
}

describe("FundingFlow", () => {
  it("waits for OTP confirmation before reporting a balance-changing transaction", async () => {
    const walletGateway = gateway();
    const onComplete = vi.fn();
    render(<FundingFlow gateway={walletGateway} sourceLabel="OPay wallet ending 5678" onComplete={onComplete} onCancel={vi.fn()} />);

    fireEvent.change(screen.getByLabelText("Amount to add"), { target: { value: "2500" } });
    fireEvent.submit(screen.getByRole("button", { name: "Continue" }));

    // Should go straight to entering OTP
    await screen.findByRole("heading", { name: /Check your phone & enter OTP/i });
    expect(walletGateway.createCollection).toHaveBeenCalledWith("quote-1");
    expect(onComplete).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText("Verification code"), { target: { value: "123456" } });
    fireEvent.submit(screen.getByRole("button", { name: "Confirm Deposit" }));

    await screen.findByText("Deposit confirmed");
    expect(walletGateway.submitCollectionOtp).toHaveBeenCalledWith("collection-1", "123456");
    expect(onComplete).toHaveBeenCalledWith(transaction);
  });
});
