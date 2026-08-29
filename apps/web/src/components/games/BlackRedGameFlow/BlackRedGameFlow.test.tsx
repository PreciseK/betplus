import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import {
  BlackRedGatewayError,
  mockBlackRedGateway,
  type BlackRedGateway,
} from "@/mocks/blackred";
import { BlackRedGameFlow } from "./BlackRedGameFlow";

vi.mock("@betplus/api-client", () => ({
  walletGateway: {
    quoteFunding: vi.fn().mockResolvedValue({ quoteId: "quote-1", amountKobo: 800_000 }),
    createCollection: vi.fn().mockResolvedValue({ collectionId: "collection-1", otpRequired: true as const }),
    submitCollectionOtp: vi.fn().mockResolvedValue({ reference: "dep-ref-1", amountKobo: 800_000 }),
  },
  payoutGateway: {
    quoteWithdrawal: vi.fn().mockResolvedValue({ quoteId: "wd-quote-1", amountKobo: 500_000 }),
    requestWithdrawal: vi.fn().mockResolvedValue({ reference: "WD-TEST-1", amountKobo: 500_000 }),
  },
}));

async function configureRound(prediction: Array<"B" | "R"> = ["B", "R"], stake = "1000") {
  fireEvent.click(await screen.findByRole("button", { name: `${prediction.length} ${prediction.length === 1 ? "position" : "positions"}` }));
  prediction.forEach((choice, index) => {
    fireEvent.click(screen.getByRole("button", { name: `Position ${index + 1}: Not selected` }));
    if (choice === "B") fireEvent.click(screen.getByRole("button", { name: `Position ${index + 1}: Red (R)` }));
  });
  fireEvent.change(screen.getByLabelText("Stake amount"), { target: { value: stake } });
}

describe("BlackRedGameFlow Dashboard", () => {
  it("shows both balances, Step 1-3 dashboard layout, and no preselected game", async () => {
    render(<BlackRedGameFlow />);
    await screen.findByRole("heading", { name: /Pick your game/i });

    expect(screen.getAllByText("Play Balance").length).toBeGreaterThan(0);
    expect(screen.getAllByText(/Winnings/).length).toBeGreaterThan(0);
    expect(screen.getByText("Step 1")).toBeInTheDocument();
    expect(screen.getByText("Step 2")).toBeInTheDocument();
    expect(screen.getByText("Step 3")).toBeInTheDocument();
    expect(screen.getByText(/Betplus/i)).toBeInTheDocument();

    const countButtons = [1, 2, 3, 4, 5].map((count) => screen.getByRole("button", { name: `${count} ${count === 1 ? "position" : "positions"}` }));
    expect(countButtons.every((button) => button.getAttribute("aria-pressed") === "false")).toBe(true);
  });

  it("updates exact multiplier and potential win when configuring game and launches arena modal", async () => {
    render(<BlackRedGameFlow />);
    await configureRound(["B", "R"], "1000");

    expect(screen.getAllByText("10×").length).toBeGreaterThan(0);
    expect(screen.getAllByText("₦9,500").length).toBeGreaterThan(0);

    const startButton = screen.getByRole("button", { name: /Start Round/i });
    expect(startButton).toBeEnabled();

    // Click Start Round to launch Step 4 and 5 Arena Modal Pop-up!
    fireEvent.click(startButton);

    // Step 4 "Meet your deck" modal opens
    const arenaModal = await screen.findByRole("dialog", { name: /Meet your deck/i });
    expect(arenaModal).toBeInTheDocument();
    expect(screen.getAllByText(/6 red/i).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/6 black/i).length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: /Begin shuffle/i })).toBeInTheDocument();
  }, 35000);

  it("opens inline top-up and preserves the configured game through amount, OTP, and done", async () => {
    render(<BlackRedGameFlow />);
    await configureRound(["B"], "20000");

    expect(screen.getByText("Not enough Play Balance.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Top up here" }));
    await screen.findByRole("dialog", { name: "Top up" });
    fireEvent.change(screen.getByPlaceholderText("1,000"), { target: { value: "8000" } });
    fireEvent.click(screen.getByRole("button", { name: "Continue" }));
    fireEvent.change(await screen.findByLabelText("One-time password"), { target: { value: "123456" } });
    fireEvent.click(screen.getByRole("button", { name: "Verify and top up" }));
    expect(await screen.findByRole("heading", { name: "Top-up complete" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Back to game" }));

    expect(screen.getByLabelText("Stake amount")).toHaveValue("20000");
    expect(screen.getByRole("button", { name: "Position 1: Black (B)" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Start Round/i })).toBeEnabled();
  });

  it("keeps keyboard focus inside top-up and restores the trigger when dismissed", async () => {
    render(<BlackRedGameFlow />);
    await configureRound(["B"], "20000");

    const topUpTrigger = screen.getByRole("button", { name: "Top up here" });
    topUpTrigger.focus();
    fireEvent.click(topUpTrigger);
    const dialog = await screen.findByRole("dialog", { name: "Top up" });
    await waitFor(() => expect(screen.getByPlaceholderText("1,000")).toHaveFocus());

    fireEvent.keyDown(dialog, { key: "Escape" });
    await waitFor(() => expect(screen.getByRole("button", { name: "Top up here" })).toHaveFocus());
    expect(screen.queryByRole("dialog", { name: "Top up" })).not.toBeInTheDocument();
  });

  it("opens inline withdraw pop-up and completes withdrawal without leaving the dashboard", async () => {
    render(<BlackRedGameFlow />);
    await screen.findByRole("heading", { name: /Pick your game/i });

    const withdrawButtons = screen.getAllByRole("button", { name: /^Withdraw$/i });
    fireEvent.click(withdrawButtons[0]);

    const withdrawDialog = await screen.findByRole("dialog", { name: /Withdraw Funds/i });
    expect(withdrawDialog).toBeInTheDocument();
    expect(screen.getByText(/Available to withdraw/i)).toBeInTheDocument();

    fireEvent.change(screen.getByPlaceholderText("5,000"), { target: { value: "5000" } });
    fireEvent.click(screen.getByRole("button", { name: "Continue to review" }));

    expect(await screen.findByText(/Release ₦5,000 to your verified OPay account/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Confirm withdrawal" }));

    expect(await screen.findByRole("heading", { name: "Withdrawal submitted" })).toBeInTheDocument();
    expect(screen.getByText(/WD-TEST-1/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Back to game" }));
    expect(screen.queryByRole("dialog", { name: /Withdraw Funds/i })).not.toBeInTheDocument();
  });

  it("keeps Wallet and Activity available when game rules cannot load", async () => {
    const gateway: BlackRedGateway = {
      ...mockBlackRedGateway,
      loadGame: vi.fn().mockRejectedValue(new Error("offline")),
    };
    render(<BlackRedGameFlow gateway={gateway} />);

    expect(await screen.findByRole("heading", { name: "BlackRed is unavailable" })).toBeInTheDocument();
    expect(screen.getByText(/withdrawals remain available/i)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Open Wallet" })).toHaveAttribute("href", "/wallet");
    expect(screen.getByRole("link", { name: "View Activity" })).toHaveAttribute("href", "/activity");
  });
});
