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
    collectDeposit: vi.fn().mockResolvedValue(transaction),
  };
}

describe("FundingFlow", () => {
  it("verifies the OPay wallet and merchant balance synchronously before reporting a balance-changing transaction", async () => {
    const walletGateway = gateway();
    const onComplete = vi.fn();
    render(<FundingFlow gateway={walletGateway} sourceLabel="OPay wallet ending 5678" onComplete={onComplete} onCancel={vi.fn()} />);

    fireEvent.change(screen.getByLabelText("Amount to add"), { target: { value: "2500" } });
    fireEvent.submit(screen.getByRole("button", { name: "Pay via OPay" }));

    await screen.findByText("Deposit confirmed");
    expect(walletGateway.quoteFunding).toHaveBeenCalledWith(250_000);
    expect(walletGateway.collectDeposit).toHaveBeenCalledWith("quote-1");
    expect(onComplete).toHaveBeenCalledWith(transaction);
  });

  it("shows a provider error and does not confirm when verification fails", async () => {
    const walletGateway = gateway();
    (walletGateway.collectDeposit as ReturnType<typeof vi.fn>).mockRejectedValue(new Error("WALLET_UNVERIFIED"));
    const onComplete = vi.fn();
    render(<FundingFlow gateway={walletGateway} sourceLabel="OPay wallet ending 5678" onComplete={onComplete} onCancel={vi.fn()} />);

    fireEvent.change(screen.getByLabelText("Amount to add"), { target: { value: "2500" } });
    fireEvent.submit(screen.getByRole("button", { name: "Pay via OPay" }));

    await screen.findByText(/couldn't verify your OPay wallet/i);
    expect(onComplete).not.toHaveBeenCalled();
  });
});
