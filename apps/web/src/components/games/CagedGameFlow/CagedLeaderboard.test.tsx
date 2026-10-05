import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import type { CagedGateway, CagedRoundState } from "@/mocks/caged";
import { CagedGameFlow } from "./CagedGameFlow";
import { CAGED_50_USERNAMES } from "./cagedActivePlayers";

function baseRound(overrides: Partial<CagedRoundState> = {}): CagedRoundState {
  return {
    gameCode: "CAGED",
    serverTime: new Date().toISOString(),
    roundId: 1,
    roundNumber: 42,
    status: "BETTING",
    bettingStartedAt: new Date().toISOString(),
    flightStartedAt: null,
    bettingWindowSeconds: 5,
    growthRateConstant: 500_000,
    commitmentDigest: "abc123digest",
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

function fakeGateway(overrides: Partial<CagedGateway> = {}): CagedGateway {
  return {
    loadCurrentRound: vi.fn().mockResolvedValue(baseRound()),
    placeBet: vi.fn(),
    cashout: vi.fn(),
    ...overrides,
  };
}

describe("CagedGameFlow Leaderboard & Active Players", () => {
  it("defaults to the Leaderboard tab and displays 50 live players", async () => {
    const gateway = fakeGateway();
    render(<CagedGameFlow gateway={gateway} />);

    // Verify subheader and active player badge
    expect(await screen.findByText("50 Live Players")).toBeInTheDocument();
    expect(screen.getByText("₦1k – ₦150k Stakes")).toBeInTheDocument();

    // Verify top medal ranks are present
    expect(screen.getByText("🥇")).toBeInTheDocument();
    expect(screen.getByText("🥈")).toBeInTheDocument();
    expect(screen.getByText("🥉")).toBeInTheDocument();

    // Verify active players from the 50 usernames appear in leaderboard & feed
    expect(screen.getAllByText(CAGED_50_USERNAMES[0]).length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText(CAGED_50_USERNAMES[1]).length).toBeGreaterThanOrEqual(1);

    // Verify that displayed stakes match the ₦1,000 to ₦150,000 requirement
    const stakeElements = screen.getAllByText(/^Stake: ₦/);
    expect(stakeElements.length).toBe(50);

    for (const el of stakeElements) {
      const match = el.textContent?.match(/^Stake: ₦([\d,]+)\.00$/);
      expect(match).toBeTruthy();
      if (match) {
        const nairaAmount = parseInt(match[1].replace(/,/g, ""), 10);
        expect(nairaAmount).toBeGreaterThanOrEqual(1000);
        expect(nairaAmount).toBeLessThanOrEqual(150000);
      }
    }
  });

  it("includes real players from the backend into the leaderboard and player counts", async () => {
    const gateway = fakeGateway({
      loadCurrentRound: vi.fn().mockResolvedValue(
        baseRound({
          players: [
            {
              username: "RealVIP_Chidi",
              stakeKobo: 250_000,
              status: "CASHED_OUT",
              cashedOutAtMultiplierHundredths: 550,
            },
          ],
        }),
      ),
    });

    render(<CagedGameFlow gateway={gateway} />);

    // Total players reflects simulated + real player (50 + 1 = 51)
    expect(await screen.findByText("51 Live Players")).toBeInTheDocument();

    // The real player appears in both leaderboard and players feed
    expect(screen.getAllByText("RealVIP_Chidi").length).toBeGreaterThanOrEqual(1);

    // The real player has the LIVE badge
    expect(screen.getByText("LIVE")).toBeInTheDocument();
  });
});
