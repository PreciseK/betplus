import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import type { BirdEscapeGateway, BirdEscapeRoundState } from "@/mocks/birdescape";
import { BirdEscapeGameFlow } from "./BirdEscapeGameFlow";

function baseRound(overrides: Partial<BirdEscapeRoundState> = {}): BirdEscapeRoundState {
  return {
    gameCode: "BIRDESCAPE",
    serverTime: new Date().toISOString(),
    roundId: 1,
    roundNumber: 42,
    status: "BETTING",
    bettingStartedAt: new Date().toISOString(),
    flightStartedAt: null,
    bettingWindowSeconds: 5,
    growthRateConstant: 500_000,
    commitmentDigest: "abc123",
    minStakeKobo: 10_000,
    maxStakeKobo: 1_000_000,
    playBalanceKobo: 500_000,
    winningsBalanceKobo: 0,
    crashMultiplierHundredths: null,
    seedHex: null,
    recentRounds: [],
    players: [],
    myBets: [],
    ...overrides,
  };
}

function fakeGateway(overrides: Partial<BirdEscapeGateway> = {}): BirdEscapeGateway {
  return {
    loadCurrentRound: vi.fn().mockResolvedValue(baseRound()),
    placeBet: vi.fn(),
    cashout: vi.fn(),
    ...overrides,
  };
}

describe("BirdEscapeGameFlow", () => {
  it("renders the balance from the gateway response, never invents it", async () => {
    const gateway = fakeGateway();
    render(<BirdEscapeGameFlow gateway={gateway} />);

    // The balance renders three times (header pill + both bet slots), all sourced
    // from the same gateway response.
    expect(await screen.findAllByText("₦5,000.00")).toHaveLength(3);
    expect(gateway.loadCurrentRound).toHaveBeenCalled();
  });

  it("places a bet through the gateway with the configured stake, never invents an outcome locally", async () => {
    const gateway = fakeGateway({
      placeBet: vi.fn().mockResolvedValue({
        betId: 7,
        roundNumber: 42,
        stakeKobo: 10_000,
        autoCashoutMultiplierHundredths: null,
        playBalanceAfterKobo: 490_000,
        status: "placed",
      }),
    });
    render(<BirdEscapeGameFlow gateway={gateway} />);
    await screen.findAllByRole("button", { name: "Place Bet" });

    fireEvent.click(screen.getAllByRole("button", { name: "Place Bet" })[0]);

    await waitFor(() => expect(gateway.placeBet).toHaveBeenCalledWith(expect.objectContaining({ roundId: 1, stakeKobo: 10_000 })));
    expect(await screen.findByText("✓ Bet Placed (Waiting for flight)")).toBeInTheDocument();
  });

  it("surfaces a real error message when placing a bet fails, rather than pretending it succeeded", async () => {
    const gateway = fakeGateway({
      placeBet: vi.fn().mockRejectedValue(new Error("Stake exceeds Play Balance.")),
    });
    render(<BirdEscapeGameFlow gateway={gateway} />);
    await screen.findAllByRole("button", { name: "Place Bet" });

    fireEvent.click(screen.getAllByRole("button", { name: "Place Bet" })[0]);

    expect(await screen.findByText("Stake exceeds Play Balance.")).toBeInTheDocument();
  });

  it("cashes out through the gateway and displays only the server-returned multiplier and credit", async () => {
    const gateway = fakeGateway({
      loadCurrentRound: vi
        .fn()
        .mockResolvedValueOnce(baseRound())
        .mockResolvedValue(
          baseRound({
            status: "FLYING",
            flightStartedAt: new Date().toISOString(),
            myBets: [
              {
                betId: 7,
                stakeKobo: 10_000,
                autoCashoutMultiplierHundredths: null,
                status: "PLACED",
                cashedOutAtMultiplierHundredths: null,
                grossPrizeKobo: null,
                taxWithheldKobo: null,
                netCreditKobo: null,
              },
            ],
          }),
        ),
      placeBet: vi.fn().mockResolvedValue({
        betId: 7,
        roundNumber: 42,
        stakeKobo: 10_000,
        autoCashoutMultiplierHundredths: null,
        playBalanceAfterKobo: 490_000,
        status: "placed",
      }),
      cashout: vi.fn().mockResolvedValue({
        betId: 7,
        status: "CASHED_OUT",
        cashedOutAtMultiplierHundredths: 250,
        grossPrizeKobo: 25_000,
        taxWithheldKobo: 1_250,
        netCreditKobo: 23_750,
        winningsBalanceAfterKobo: 23_750,
      }),
    });
    render(<BirdEscapeGameFlow gateway={gateway} />);
    await screen.findAllByRole("button", { name: "Place Bet" });

    fireEvent.click(screen.getAllByRole("button", { name: "Place Bet" })[0]);
    await screen.findByText("✓ Bet Placed (Waiting for flight)");

    await screen.findByText(/^Cash Out /, {}, { timeout: 4000 });
    fireEvent.click(screen.getByText(/^Cash Out /));

    await waitFor(() => expect(gateway.cashout).toHaveBeenCalledWith(7));
    expect(await screen.findByText(/Cashed Out @ 2\.50×/)).toBeInTheDocument();
    expect(screen.getByText(/\+₦237\.50/)).toBeInTheDocument();
  }, 10000);
});
