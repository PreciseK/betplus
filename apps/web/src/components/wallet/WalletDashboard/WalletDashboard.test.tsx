import { render, screen, fireEvent } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { mockWalletGateway, type WalletSnapshot } from "@/mocks/wallet";
import { mockPayoutGateway, type PayoutContext } from "@/mocks/payout";
import { WalletDashboard } from "./WalletDashboard";
import { ActivityDashboard } from "../ActivityDashboard/ActivityDashboard";

const wallet: WalletSnapshot = {
  playBalanceKobo: 234_500,
  winningsBalanceKobo: 700_000,
  currency: "NGN",
  registeredSourceLabel: "OPay test account",
  transactions: [],
};
const payout: PayoutContext = {
  playBalanceKobo: wallet.playBalanceKobo,
  winningsBalanceKobo: wallet.winningsBalanceKobo,
  destinationLabel: "OPay test account",
  destinationName: "Test player",
  manualReviewThresholdKobo: 10_000_000,
  turnover: { depositAmountKobo: 0, stakedKobo: 0, requiredStakeKobo: 1, releasedPlayBalanceKobo: 0 },
  payouts: [],
};

const pendingPayout = { ...mockPayoutGateway, loadPayoutContext: () => new Promise<PayoutContext>(() => {}) };

describe("WalletDashboard balances", () => {
  it("shows a loading state without displaying sample money or enabling payments", () => {
    render(<WalletDashboard gateway={{ ...mockWalletGateway, loadWallet: () => new Promise<WalletSnapshot>(() => {}) }} payoutGateway={pendingPayout} />);
    expect(screen.getByText("Loading wallet")).toBeInTheDocument();
    expect(screen.queryByLabelText(/Play Balance:/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Deposit" })).not.toBeInTheDocument();
  });

  it("shows unavailable rather than sample balances when the wallet fails", async () => {
    render(<WalletDashboard gateway={{ ...mockWalletGateway, loadWallet: vi.fn().mockRejectedValue(new Error("offline")) }} payoutGateway={pendingPayout} />);
    expect(await screen.findByText("Wallet unavailable")).toBeInTheDocument();
    expect(screen.queryByLabelText(/Play Balance:/)).not.toBeInTheDocument();
  });

  it("uses returned balances and blocks withdrawal until eligibility is loaded", async () => {
    render(<WalletDashboard gateway={{ ...mockWalletGateway, loadWallet: async () => wallet }} payoutGateway={pendingPayout} />);
    expect(await screen.findByLabelText("Play Balance: 2,345 Naira")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Withdraw" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Deposit" })).toBeEnabled();
  });
});

describe("Transaction history", () => {
  it("filters records by period and displays failed deposits without a paid indicator", async () => {
    const now = Date.now();
    const transaction = (reference: string, age: number) => ({
      reference, type: "OPay deposit" as const, provider: "OPay" as const,
      occurredAt: new Date(now - age).toISOString(), amountKobo: 20_000, feeKobo: 0,
      status: "failed" as const, statusHistory: [],
    });
    render(<ActivityDashboard gateway={{ ...mockWalletGateway, loadWallet: async () => ({ ...wallet, transactions: [transaction("RECENT-FAILED", 0), transaction("OLD-FAILED", 10 * 86400000)] }) }} payoutGateway={{ ...mockPayoutGateway, loadPayoutContext: async () => payout }} />);
    expect(await screen.findByText(/RECENT-FAILED/)).toBeInTheDocument();
    expect(screen.queryByText(/OLD-FAILED/)).not.toBeInTheDocument();
    expect(screen.getByText("failed")).toHaveAttribute("data-status", "failed");
    expect(screen.queryByText(/✓/)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Last 30 days" }));
    expect(screen.getByText(/OLD-FAILED/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Last 30 days" })).toHaveAttribute("aria-pressed", "true");
  });
});
