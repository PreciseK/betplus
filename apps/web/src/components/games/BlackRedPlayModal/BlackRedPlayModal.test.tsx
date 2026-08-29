import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { BlackRedGatewayError } from "@betplus/api-client";
import { BlackRedPlayModal } from "./BlackRedPlayModal";

const loadGame = vi.fn();
const purchaseTicket = vi.fn();
const revealTicket = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    blackRedGateway: {
      loadGame: (...args: unknown[]) => loadGame(...args),
      purchaseTicket: (...args: unknown[]) => purchaseTicket(...args),
      revealTicket: (...args: unknown[]) => revealTicket(...args),
    },
  };
});

const LIVE_TIERS = [1, 2, 3, 4, 5].map((positions) => ({
  positions,
  multiplierHundredths: [185, 360, 700, 1350, 2600][positions - 1],
  probabilityNumerator: 1,
  probabilityDenominator: 2 ** positions,
}));

describe("BlackRedPlayModal", () => {
  it("places a real ticket at Start the round and shows the settled result, never a client-computed one", async () => {
    loadGame.mockResolvedValue({ tiers: LIVE_TIERS });
    purchaseTicket.mockResolvedValue({ reference: "BR-TEST-1", status: "purchased" });
    revealTicket.mockResolvedValue({
      reference: "BR-TEST-1",
      result: ["R", "B", "R"],
      won: true,
      netCreditKobo: 92_500,
      playBalanceAfterKobo: 1_200_000,
      winningsBalanceAfterKobo: 92_500,
    });

    render(<BlackRedPlayModal isOpen onClose={() => {}} />);

    // Step 1 -> Step 2 (defaults: 3 cards, picks ["red","black","red"]) — waits for
    // the live prize table to load, same as the multiplier shown to the player would.
    fireEvent.click(await screen.findByRole("button", { name: "Choose your colours →" }));
    // Step 2 -> Step 3
    fireEvent.click(await screen.findByRole("button", { name: "Proceed to confirm →" }));
    // Step 3 -> place the real ticket
    fireEvent.click(await screen.findByRole("button", { name: "Start the round ▶" }));

    expect(purchaseTicket).toHaveBeenCalledWith({
      prediction: ["R", "B", "R"],
      stakeKobo: 50_000,
      idempotencyKey: expect.any(String),
    });
    await screen.findByRole("button", { name: /Begin shuffle/i });
    expect(revealTicket).toHaveBeenCalledWith("BR-TEST-1");
  });

  it("shows a real error message instead of proceeding when the purchase is refused", async () => {
    loadGame.mockResolvedValue({ tiers: LIVE_TIERS });
    purchaseTicket.mockRejectedValueOnce(new BlackRedGatewayError("INSUFFICIENT_PLAY_BALANCE"));

    render(<BlackRedPlayModal isOpen onClose={() => {}} />);
    fireEvent.click(await screen.findByRole("button", { name: "Choose your colours →" }));
    fireEvent.click(await screen.findByRole("button", { name: "Proceed to confirm →" }));
    fireEvent.click(await screen.findByRole("button", { name: "Start the round ▶" }));

    expect(await screen.findByText(/higher than your Play Balance/i)).toBeInTheDocument();
  });
});
