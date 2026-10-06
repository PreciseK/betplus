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
  it("defaults to the Leaderboard tab and displays dynamic live players (18-38)", async () => {
    const gateway = fakeGateway();
    render(<CagedGameFlow gateway={gateway} />);

    // Verify subheader and active player badge
    const liveHeader = await screen.findByText(/\d+ Live Players/);
    expect(liveHeader).toBeInTheDocument();
    expect(screen.getByText("₦1k – ₦150k Stakes")).toBeInTheDocument();

    // Verify top medal ranks are present
    expect(screen.getByText("🥇")).toBeInTheDocument();
    expect(screen.getByText("🥈")).toBeInTheDocument();
    expect(screen.getByText("🥉")).toBeInTheDocument();

    // Verify active players from the pool appear in leaderboard & feed
    const stakeElements = screen.getAllByTestId("player-stake");
    expect(stakeElements.length).toBeGreaterThanOrEqual(18);
    expect(stakeElements.length).toBeLessThanOrEqual(38);

    for (const el of stakeElements) {
      const match = el.textContent?.match(/^₦([\d,]+)\.00$/);
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

    // Total players reflects simulated + real player
    expect(await screen.findByText(/\d+ Live Players/)).toBeInTheDocument();

    // The real player appears in both leaderboard and players feed
    expect(screen.getAllByText("RealVIP_Chidi").length).toBeGreaterThanOrEqual(1);

    // The real player has the LIVE badge
    expect(screen.getByText("LIVE")).toBeInTheDocument();
  });

  it("displays losing players with 💥 Crashed badge when the round crashes", async () => {
    const gateway = fakeGateway({
      loadCurrentRound: vi.fn().mockResolvedValue(
        baseRound({
          status: "CRASHED",
          crashMultiplierHundredths: 105, // very early crash at 1.05x
        }),
      ),
    });

    render(<CagedGameFlow gateway={gateway} />);

    // In a low 1.05x crash, players whose target > 1.05x lose and have 💥 Crashed
    const crashedBadges = await screen.findAllByText("💥 Crashed");
    expect(crashedBadges.length).toBeGreaterThan(0);

    // Negative lost stake labels appear
    const lostLabels = screen.getAllByText(/^-₦/);
    expect(lostLabels.length).toBeGreaterThan(0);
  });

  it("never displays target multipliers to avoid giving a premeditated impression, showing Bet Placed instead", async () => {
    const gateway = fakeGateway();
    render(<CagedGameFlow gateway={gateway} />);

    // Active player feed must show "Bet Placed"
    const betPlacedBadges = await screen.findAllByText("Bet Placed");
    expect(betPlacedBadges.length).toBeGreaterThan(0);

    // Absolutely no "Target" label should be displayed in the player feed
    expect(screen.queryByText(/Target \d/)).toBeNull();
  });

  it("supports switching to Chat tab, viewing online users, and sending messages or emoji reactions", async () => {
    const gateway = fakeGateway();
    render(<CagedGameFlow gateway={gateway} />);

    // Click the Chat tab
    const chatTabBtn = await screen.findByRole("button", { name: "Chat" });
    chatTabBtn.click();

    // Verify online room indicator appears
    expect(await screen.findByText(/\d+ Online/)).toBeInTheDocument();
    expect(screen.getByText("Live Room • English")).toBeInTheDocument();

    // Verify reaction emojis are present
    expect(screen.getByRole("button", { name: "Send 🚀" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Send 🔥" })).toBeInTheDocument();

    // Click reaction emoji to post
    const rocketBtn = screen.getByRole("button", { name: "Send 🚀" });
    rocketBtn.click();

    // Verify message with 🚀 was sent into chat
    expect(screen.getByText("🚀")).toBeInTheDocument();
  });
});
