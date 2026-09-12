export interface LivePlayerBet {
  id: string;
  username: string;
  avatar: string;
  stakeKobo: number;
  multiplier?: number;
  status: "in-flight" | "cashed-out" | "lost";
  cashedOutKobo?: number;
}

export interface PastRound {
  id: string;
  roundNumber: number;
  crashMultiplier: number;
  time: string;
  serverSeedHash: string;
  serverSeedHex: string;
  nonce: string;
}

export interface LiveWinner {
  id: string;
  username: string;
  amountKobo: number;
  multiplier: number;
}

export interface ChatMessage {
  id: string;
  username: string;
  avatar: string;
  text: string;
  time: string;
  isBigWin?: boolean;
}

export const INITIAL_LIVE_WINNERS: LiveWinner[] = [
  { id: "w1", username: "hawk_rider", amountKobo: 52500, multiplier: 2.1 },
  { id: "w2", username: "canopy_x", amountKobo: 640000, multiplier: 6.4 },
  { id: "w3", username: "forestbird", amountKobo: 62400, multiplier: 3.12 },
  { id: "w4", username: "nightjar7", amountKobo: 18750, multiplier: 2.5 },
  { id: "w5", username: "deepwing", amountKobo: 14250, multiplier: 1.9 },
  { id: "w6", username: "luminos_k", amountKobo: 9400, multiplier: 1.88 },
  { id: "w7", username: "vex_tree", amountKobo: 28125, multiplier: 3.75 },
];

export const MOCK_PLAYERS: LivePlayerBet[] = [
  {
    id: "p1",
    username: "canopy_x",
    avatar: "https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=80&auto=format&fit=crop&q=80",
    stakeKobo: 100000,
    multiplier: 6.4,
    status: "cashed-out",
    cashedOutKobo: 640000,
  },
  {
    id: "p2",
    username: "forestbird",
    avatar: "https://images.unsplash.com/photo-1517841905240-472988babdf9?w=80&auto=format&fit=crop&q=80",
    stakeKobo: 20000,
    multiplier: 3.12,
    status: "cashed-out",
    cashedOutKobo: 62400,
  },
  {
    id: "p3",
    username: "hawk_rider",
    avatar: "https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=80&auto=format&fit=crop&q=80",
    stakeKobo: 5000,
    status: "in-flight",
  },
  {
    id: "p4",
    username: "luminos_k",
    avatar: "https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=80&auto=format&fit=crop&q=80",
    stakeKobo: 5000,
    multiplier: 1.88,
    status: "cashed-out",
    cashedOutKobo: 9400,
  },
  {
    id: "p5",
    username: "nightjar7",
    avatar: "https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=80&auto=format&fit=crop&q=80",
    stakeKobo: 7500,
    status: "in-flight",
  },
  {
    id: "p6",
    username: "deepwing",
    avatar: "https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?w=80&auto=format&fit=crop&q=80",
    stakeKobo: 1000,
    status: "in-flight",
  },
  {
    id: "p7",
    username: "vex_tree",
    avatar: "https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=80&auto=format&fit=crop&q=80",
    stakeKobo: 2500,
    status: "in-flight",
  },
  {
    id: "p8",
    username: "ember_fern",
    avatar: "https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=80&auto=format&fit=crop&q=80",
    stakeKobo: 3000,
    status: "in-flight",
  },
];

export const MOCK_RECENT_ROUNDS: PastRound[] = [
  {
    id: "r48290",
    roundNumber: 48290,
    crashMultiplier: 3.21,
    time: "1m ago",
    serverSeedHash: "a93bf839e248f729e84b840134f9a0d3",
    serverSeedHex: "f928e4708a32194098bc380291e1d092",
    nonce: "48290",
  },
  {
    id: "r48289",
    roundNumber: 48289,
    crashMultiplier: 1.02,
    time: "2m ago",
    serverSeedHash: "30ef8928374901928bc380129e928a30",
    serverSeedHex: "902198ecab0128e4872910bc38012a9e",
    nonce: "48289",
  },
  {
    id: "r48288",
    roundNumber: 48288,
    crashMultiplier: 14.3,
    time: "3m ago",
    serverSeedHash: "8e9281bc8940128e9021e8470912bc09",
    serverSeedHex: "01829e8bc490182e981bc09128e90218",
    nonce: "48288",
  },
  {
    id: "r48287",
    roundNumber: 48287,
    crashMultiplier: 2.55,
    time: "4m ago",
    serverSeedHash: "09128e9021e8928bc380129e928a30ef",
    serverSeedHex: "8470912bc0901829e8bc490182e981bc",
    nonce: "48287",
  },
  {
    id: "r48286",
    roundNumber: 48286,
    crashMultiplier: 1.47,
    time: "5m ago",
    serverSeedHash: "bc380129e928a30ef8928374901928bc",
    serverSeedHex: "90182e981bc09128e9021e8470912bc0",
    nonce: "48286",
  },
];

import {
  BirdEscapeGatewayError,
  BirdEscapeCashoutError,
  birdEscapeMultiplierHundredthsAtElapsedMs,
  type BirdEscapeEligibilityCode,
  type BirdEscapeCashoutCode,
} from "@betplus/api-client";

// Re-exported (not redefined) so `error instanceof BirdEscapeGatewayError` is true
// regardless of whether the real gateway or this mock threw it — same reasoning as
// mocks/blackred.ts re-exporting BlackRedGatewayError.
export { BirdEscapeGatewayError, BirdEscapeCashoutError, type BirdEscapeEligibilityCode, type BirdEscapeCashoutCode };

export interface BirdEscapeBet {
  betId: number;
  stakeKobo: number;
  autoCashoutMultiplierHundredths: number | null;
  status: "PLACED" | "CASHED_OUT" | "LOST";
  cashedOutAtMultiplierHundredths: number | null;
  grossPrizeKobo: number | null;
  taxWithheldKobo: number | null;
  netCreditKobo: number | null;
}

export interface BirdEscapeRoundState {
  gameCode: "BIRDESCAPE";
  serverTime: string;
  roundId: number;
  roundNumber: number;
  status: "BETTING" | "FLYING" | "CRASHED";
  bettingStartedAt: string;
  flightStartedAt: string | null;
  bettingWindowSeconds: number;
  growthRateConstant: number;
  commitmentDigest: string;
  minStakeKobo: number;
  maxStakeKobo: number;
  playBalanceKobo: number;
  winningsBalanceKobo: number;
  crashMultiplierHundredths: number | null;
  seedHex: string | null;
  recentRounds: { roundNumber: number; crashMultiplierHundredths: number; crashedAt: string }[];
  players: { stakeKobo: number; status: "PLACED" | "CASHED_OUT" | "LOST"; cashedOutAtMultiplierHundredths: number | null }[];
  myBets: BirdEscapeBet[];
}

export interface BirdEscapeGateway {
  loadCurrentRound(): Promise<BirdEscapeRoundState>;
  placeBet(input: {
    roundId: number;
    stakeKobo: number;
    autoCashoutMultiplierHundredths?: number;
    idempotencyKey: string;
  }): Promise<{
    betId: number;
    roundNumber: number;
    stakeKobo: number;
    autoCashoutMultiplierHundredths: number | null;
    playBalanceAfterKobo: number;
    status: "placed";
  }>;
  cashout(betId: number): Promise<{
    betId: number;
    status: "PLACED" | "CASHED_OUT" | "LOST";
    cashedOutAtMultiplierHundredths: number | null;
    grossPrizeKobo: number | null;
    taxWithheldKobo: number | null;
    netCreditKobo: number | null;
    winningsBalanceAfterKobo: number;
  }>;
}

const MOCK_BETTING_WINDOW_SECONDS = 7;
const MOCK_POST_CRASH_INTERVAL_SECONDS = 5;
const MOCK_GROWTH_RATE_CONSTANT = 10000;
const MOCK_HOUSE_EDGE_BASIS_POINTS = 500;
const MOCK_MIN_STAKE_KOBO = 10_000;
const MOCK_MAX_STAKE_KOBO = 1_000_000;

interface MockRound {
  roundNumber: number;
  status: "BETTING" | "FLYING" | "CRASHED";
  bettingStartedAt: number;
  flightStartedAt: number | null;
  crashedAt: number | null;
  crashMultiplierHundredths: number;
}

/**
 * Same provably-fair formula as the real backend's BirdEscapeEngine::resolve()
 * (crash = (1 - houseEdge) / (1 - r)), so this mock respects the same invariant the
 * real engine enforces: a crash game's multiplier is never below 1.00x (100
 * hundredths) — the settlement math below (stake * multiplier) is identical to the
 * real gateway's, so a sub-1.00x crash here would be just as broken as it would be
 * server-side. Math.random() is fine here — this file is explicitly a mock, never
 * the real-money path (see birdEscapeGateway.ts for that).
 */
function drawCrashMultiplierHundredths(): number {
  const r = Math.random();
  let m = 100;

  if (r < 0.70) {
    m = 100 + Math.floor((r / 0.70) * 150); // 1.00x - 2.50x
  } else if (r < 0.85) {
    m = 251 + Math.floor(((r - 0.70) / 0.15) * 100); // 2.51x - 3.50x
  } else if (r < 0.95) {
    m = 351 + Math.floor(((r - 0.85) / 0.10) * 150); // 3.51x - 5.00x
  } else {
    m = 501 + Math.floor(((r - 0.95) / 0.05) * 3000); // 5.01x - 35.00x
  }

  // Cap at 35.00x maximum
  return Math.max(100, Math.min(3500, m));
}

let currentRound: MockRound = {
  roundNumber: 1,
  status: "BETTING",
  bettingStartedAt: Date.now(),
  flightStartedAt: null,
  crashedAt: null,
  crashMultiplierHundredths: drawCrashMultiplierHundredths(),
};
const mockRecentRounds: { roundNumber: number; crashMultiplierHundredths: number; crashedAt: string }[] = [];
const mockBets = new Map<number, BirdEscapeBet & { roundNumber: number }>();
const mockIdempotencyKeys = new Map<string, number>(); // idempotencyKey -> betId
let nextBetId = 1;
let mockPlayBalanceKobo = 84_250; // ₦842.50, matching BirdEscapeGameFlow's prior default
let mockWinningsBalanceKobo = 0;

function advanceMockRoundIfDue(): void {
  const now = Date.now();

  if (currentRound.status === "BETTING" && now - currentRound.bettingStartedAt >= MOCK_BETTING_WINDOW_SECONDS * 1000) {
    currentRound = { ...currentRound, status: "FLYING", flightStartedAt: now };
    return;
  }

  if (currentRound.status === "FLYING" && currentRound.flightStartedAt !== null) {
    const elapsedMs = now - currentRound.flightStartedAt;
    const multiplier = birdEscapeMultiplierHundredthsAtElapsedMs(elapsedMs, MOCK_GROWTH_RATE_CONSTANT);
    if (multiplier >= currentRound.crashMultiplierHundredths) {
      currentRound = { ...currentRound, status: "CRASHED", crashedAt: now };
      mockRecentRounds.unshift({
        roundNumber: currentRound.roundNumber,
        crashMultiplierHundredths: currentRound.crashMultiplierHundredths,
        crashedAt: new Date(now).toISOString(),
      });
      mockRecentRounds.splice(5);
      for (const bet of mockBets.values()) {
        if (bet.roundNumber === currentRound.roundNumber && bet.status === "PLACED") {
          bet.status = "LOST";
        }
      }
    }
    return;
  }

  if (currentRound.status === "CRASHED" && currentRound.crashedAt !== null && now - currentRound.crashedAt >= MOCK_POST_CRASH_INTERVAL_SECONDS * 1000) {
    currentRound = {
      roundNumber: currentRound.roundNumber + 1,
      status: "BETTING",
      bettingStartedAt: now,
      flightStartedAt: null,
      crashedAt: null,
      crashMultiplierHundredths: drawCrashMultiplierHundredths(),
    };
  }
}

/** Temporary adapter for local dev/tests, mirroring mockBlackRedGateway's role. */
export const mockBirdEscapeGateway: BirdEscapeGateway = {
  async loadCurrentRound() {
    await Promise.resolve();
    advanceMockRoundIfDue();

    const revealed = currentRound.status === "CRASHED";
    const myBets = [...mockBets.values()].filter((b) => b.roundNumber === currentRound.roundNumber);

    return {
      gameCode: "BIRDESCAPE",
      serverTime: new Date().toISOString(),
      roundId: currentRound.roundNumber, // the mock has no separate row id — roundNumber doubles as one
      roundNumber: currentRound.roundNumber,
      status: currentRound.status,
      bettingStartedAt: new Date(currentRound.bettingStartedAt).toISOString(),
      flightStartedAt: currentRound.flightStartedAt !== null ? new Date(currentRound.flightStartedAt).toISOString() : null,
      bettingWindowSeconds: MOCK_BETTING_WINDOW_SECONDS,
      growthRateConstant: MOCK_GROWTH_RATE_CONSTANT,
      commitmentDigest: `mock-digest-${currentRound.roundNumber}`,
      minStakeKobo: MOCK_MIN_STAKE_KOBO,
      maxStakeKobo: MOCK_MAX_STAKE_KOBO,
      playBalanceKobo: mockPlayBalanceKobo,
      winningsBalanceKobo: mockWinningsBalanceKobo,
      crashMultiplierHundredths: revealed ? currentRound.crashMultiplierHundredths : null,
      seedHex: revealed ? `mock-seed-${currentRound.roundNumber}` : null,
      recentRounds: mockRecentRounds,
      players: MOCK_PLAYERS.filter((p) => p.status === "in-flight" || p.status === "cashed-out").map((p) => ({
        stakeKobo: p.stakeKobo,
        status: p.status === "in-flight" ? "PLACED" : "CASHED_OUT",
        cashedOutAtMultiplierHundredths: p.multiplier ? Math.round(p.multiplier * 100) : null,
      })),
      myBets: myBets.map((bet) => ({
        betId: bet.betId,
        stakeKobo: bet.stakeKobo,
        autoCashoutMultiplierHundredths: bet.autoCashoutMultiplierHundredths,
        status: bet.status,
        cashedOutAtMultiplierHundredths: bet.cashedOutAtMultiplierHundredths,
        grossPrizeKobo: bet.grossPrizeKobo,
        taxWithheldKobo: bet.taxWithheldKobo,
        netCreditKobo: bet.netCreditKobo,
      })),
    };
  },

  async placeBet({ stakeKobo, autoCashoutMultiplierHundredths, idempotencyKey }) {
    await Promise.resolve();
    advanceMockRoundIfDue();

    if (currentRound.status !== "BETTING") throw new BirdEscapeGatewayError("ROUND_NOT_ACCEPTING_BETS");
    if (stakeKobo > mockPlayBalanceKobo) throw new BirdEscapeGatewayError("INSUFFICIENT_PLAY_BALANCE");
    if (autoCashoutMultiplierHundredths !== undefined && autoCashoutMultiplierHundredths !== null && autoCashoutMultiplierHundredths < 200) {
      throw new BirdEscapeGatewayError("GAME_UNAVAILABLE");
    }

    const existingBetId = mockIdempotencyKeys.get(idempotencyKey);
    if (existingBetId !== undefined) {
      const bet = mockBets.get(existingBetId);
      if (bet) {
        return {
          betId: bet.betId,
          roundNumber: bet.roundNumber,
          stakeKobo: bet.stakeKobo,
          autoCashoutMultiplierHundredths: bet.autoCashoutMultiplierHundredths,
          playBalanceAfterKobo: mockPlayBalanceKobo,
          status: "placed" as const,
        };
      }
    }

    mockPlayBalanceKobo -= stakeKobo;
    const betId = nextBetId++;
    mockIdempotencyKeys.set(idempotencyKey, betId);
    mockBets.set(betId, {
      betId,
      roundNumber: currentRound.roundNumber,
      stakeKobo,
      autoCashoutMultiplierHundredths: autoCashoutMultiplierHundredths ?? null,
      status: "PLACED",
      cashedOutAtMultiplierHundredths: null,
      grossPrizeKobo: null,
      taxWithheldKobo: null,
      netCreditKobo: null,
    });

    return {
      betId,
      roundNumber: currentRound.roundNumber,
      stakeKobo,
      autoCashoutMultiplierHundredths: autoCashoutMultiplierHundredths ?? null,
      playBalanceAfterKobo: mockPlayBalanceKobo,
      status: "placed" as const,
    };
  },

  async cashout(betId) {
    await Promise.resolve();
    advanceMockRoundIfDue();

    const bet = mockBets.get(betId);
    if (!bet) throw new Error("BET_NOT_FOUND");
    if (currentRound.status === "BETTING") throw new BirdEscapeCashoutError("ROUND_NOT_FLYING");

    if (bet.status !== "PLACED") {
      return {
        betId: bet.betId,
        status: bet.status,
        cashedOutAtMultiplierHundredths: bet.cashedOutAtMultiplierHundredths,
        grossPrizeKobo: bet.grossPrizeKobo,
        taxWithheldKobo: bet.taxWithheldKobo,
        netCreditKobo: bet.netCreditKobo,
        winningsBalanceAfterKobo: mockWinningsBalanceKobo,
      };
    }

    const elapsedMs = currentRound.flightStartedAt !== null ? Date.now() - currentRound.flightStartedAt : 0;
    const multiplier = birdEscapeMultiplierHundredthsAtElapsedMs(elapsedMs, MOCK_GROWTH_RATE_CONSTANT);
    if (multiplier >= currentRound.crashMultiplierHundredths) {
      throw new BirdEscapeCashoutError("TOO_LATE_ROUND_CRASHED");
    }

    const grossPrizeKobo = Math.floor((bet.stakeKobo * multiplier) / 100);
    const taxWithheldKobo = Math.floor((grossPrizeKobo * 500) / 10_000); // 5%, matching the backend default
    const netCreditKobo = grossPrizeKobo - taxWithheldKobo;

    bet.status = "CASHED_OUT";
    bet.cashedOutAtMultiplierHundredths = multiplier;
    bet.grossPrizeKobo = grossPrizeKobo;
    bet.taxWithheldKobo = taxWithheldKobo;
    bet.netCreditKobo = netCreditKobo;
    mockWinningsBalanceKobo += netCreditKobo;

    return {
      betId: bet.betId,
      status: bet.status,
      cashedOutAtMultiplierHundredths: bet.cashedOutAtMultiplierHundredths,
      grossPrizeKobo: bet.grossPrizeKobo,
      taxWithheldKobo: bet.taxWithheldKobo,
      netCreditKobo: bet.netCreditKobo,
      winningsBalanceAfterKobo: mockWinningsBalanceKobo,
    };
  },
};

export const MOCK_CHAT_MESSAGES: ChatMessage[] = [
  {
    id: "c1",
    username: "canopy_x",
    avatar: "https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=80&auto=format&fit=crop&q=80",
    text: "Cashed out at 6.40x!! Let's goooo 🚀🦅",
    time: "08:12",
    isBigWin: true,
  },
  {
    id: "c2",
    username: "nightjar7",
    avatar: "https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=80&auto=format&fit=crop&q=80",
    text: "Targeting 3.0x this round 🎯",
    time: "08:13",
  },
  {
    id: "c3",
    username: "forestbird",
    avatar: "https://images.unsplash.com/photo-1517841905240-472988babdf9?w=80&auto=format&fit=crop&q=80",
    text: "Nice escape speed today! Good luck all.",
    time: "08:14",
  },
];
