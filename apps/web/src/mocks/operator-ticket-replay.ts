export type ReplayGame = "BlackRed" | "Heritage";

export interface ReplayDrawPosition {
  position: number;
  entropyHex: string;
  normalizedValue: string;
  prediction: string;
  draw: string;
  verdict: "Matched" | "Different";
}

export interface TicketReplayRecord {
  reference: string;
  game: ReplayGame;
  playerReference: string;
  status: "Settled";
  outcome: "Won" | "Lost";
  placedAt: string;
  settledAt: string;
  jurisdiction: string;
  channel: string;
  stakeKobo: number;
  prediction: string;
  engine: {
    code: string;
    version: string;
    replayMethod: string;
  };
  prizeTableVersion: string;
  taxRulesetVersion: string;
  seed: {
    sealReference: string;
    sealedAt: string;
    commitmentAlgorithm: string;
    commitmentHash: string;
    replaySeedHex: string;
  };
  canonicalInput: string;
  derivation: readonly {
    title: string;
    input: string;
    operation: string;
    output: string;
  }[];
  drawPositions: readonly ReplayDrawPosition[];
  settlement: {
    multiplier: string;
    chance: string;
    grossKobo: number;
    taxRate: string;
    taxKobo: number;
    netKobo: number;
    ledgerEntries: readonly {
      reference: string;
      account: "Play Balance" | "Winnings Balance";
      purpose: string;
      amountKobo: number;
    }[];
  };
  integrity: {
    recordedOutcomeDigest: string;
    replayOutcomeDigest: string;
    replayedAt: string;
    attestation: string;
  };
}

const BLACKRED_REPLAY: TicketReplayRecord = {
  reference: "BR-982104",
  game: "BlackRed",
  playerReference: "BP-7319",
  status: "Settled",
  outcome: "Won",
  placedAt: "18 Aug 2026, 1:34:42 PM WAT",
  settledAt: "18 Aug 2026, 1:34:43 PM WAT",
  jurisdiction: "Enugu",
  channel: "Web",
  stakeKobo: 1_000_00,
  prediction: "Red → Black → Red",
  engine: {
    code: "engine-blackred",
    version: "blackred-2.8.1",
    replayMethod: "replay",
  },
  prizeTableVersion: "BR-PT-v11",
  taxRulesetVersion: "EN-TAX-2026.06-v2",
  seed: {
    sealReference: "SEAL-BR-260818-8821",
    sealedAt: "18 Aug 2026, 1:34:42 PM WAT",
    commitmentAlgorithm: "SHA-256",
    commitmentHash: "f7b168973e9d019a7db1e7518ffac19e4cba7d434d80e336ad4b57d7b910c131",
    replaySeedHex: "4f2a9c1d775ee30b886201fa50793c13d7a556c0f8bd933fa80c2126d22a901e",
  },
  canonicalInput: "game=BLACKRED|ticket=BR-982104|prediction=RBR|cards=3|stake_kobo=100000|prize_table=BR-PT-v11|jurisdiction=EN",
  derivation: [
    {
      title: "Verify the seal",
      input: "Replay seed + canonical ticket envelope",
      operation: "SHA-256 commitment check",
      output: "f7b16897…c131 · matches the value sealed before settlement",
    },
    {
      title: "Derive deterministic entropy",
      input: "Verified seed + ticket reference BR-982104",
      operation: "HMAC-SHA-256 counter stream, counters 1–3",
      output: "9c, 21, f4 · the same bytes are returned on every replay",
    },
    {
      title: "Map bytes to colours",
      input: "Unsigned byte values 156, 33, 244",
      operation: "0–127 = Black; 128–255 = Red",
      output: "Red → Black → Red",
    },
    {
      title: "Compare the prediction",
      input: "Prediction RBR; deterministic draw RBR",
      operation: "Exact ordered comparison; every position must match",
      output: "3 of 3 matched · ticket outcome Won",
    },
  ],
  drawPositions: [
    { position: 1, entropyHex: "9c", normalizedValue: "156 / 255", prediction: "Red", draw: "Red", verdict: "Matched" },
    { position: 2, entropyHex: "21", normalizedValue: "33 / 255", prediction: "Black", draw: "Black", verdict: "Matched" },
    { position: 3, entropyHex: "f4", normalizedValue: "244 / 255", prediction: "Red", draw: "Red", verdict: "Matched" },
  ],
  settlement: {
    multiplier: "7.00×",
    chance: "1 in 8",
    grossKobo: 7_000_00,
    taxRate: "10% WHT",
    taxKobo: 700_00,
    netKobo: 6_300_00,
    ledgerEntries: [
      { reference: "LED-88829", account: "Play Balance", purpose: "Ticket stake", amountKobo: -1_000_00 },
      { reference: "LED-88828", account: "Winnings Balance", purpose: "Gross payout", amountKobo: 7_000_00 },
      { reference: "LED-88827", account: "Winnings Balance", purpose: "Withholding tax", amountKobo: -700_00 },
    ],
  },
  integrity: {
    recordedOutcomeDigest: "2f3995a5179b8133a4ca33da6848cbc79b51776e76423b92bfc388970198376d",
    replayOutcomeDigest: "2f3995a5179b8133a4ca33da6848cbc79b51776e76423b92bfc388970198376d",
    replayedAt: "18 Aug 2026, 2:12:08 PM WAT",
    attestation: "The replay output is byte-for-byte identical to the immutable settlement record.",
  },
};

const HERITAGE_REPLAY: TicketReplayRecord = {
  reference: "HG-771408",
  game: "Heritage",
  playerReference: "BP-7319",
  status: "Settled",
  outcome: "Won",
  placedAt: "18 Aug 2026, 11:22:16 AM WAT",
  settledAt: "18 Aug 2026, 11:22:19 AM WAT",
  jurisdiction: "Enugu",
  channel: "Web",
  stakeKobo: 500_00,
  prediction: "07, 23, 44, 68, 89",
  engine: {
    code: "engine-heritage",
    version: "heritage-1.14.0",
    replayMethod: "replay",
  },
  prizeTableVersion: "HG-5/90-v7",
  taxRulesetVersion: "EN-TAX-2026.06-v2",
  seed: {
    sealReference: "SEAL-HG-260818-4408",
    sealedAt: "18 Aug 2026, 11:22:16 AM WAT",
    commitmentAlgorithm: "SHA-256",
    commitmentHash: "b258dd16f71f77ade3401fc67b2247f001606f989796cd40b15d33acb7cb9284",
    replaySeedHex: "98bf302af0405ba14f9757dd2f0a327690ed24a7afe522b5f63c11768af0410c",
  },
  canonicalInput: "game=HERITAGE|ticket=HG-771408|selection=07,23,44,68,89|stake_kobo=50000|prize_table=HG-5/90-v7|jurisdiction=EN",
  derivation: [
    {
      title: "Verify the seal",
      input: "Replay seed + canonical ticket envelope",
      operation: "SHA-256 commitment check",
      output: "b258dd16…9284 · matches the value sealed before settlement",
    },
    {
      title: "Build the numbered pool",
      input: "Integers 01–90 in canonical ascending order",
      operation: "Seeded Fisher–Yates shuffle",
      output: "A deterministic permutation of all 90 numbers",
    },
    {
      title: "Take the draw",
      input: "First five positions from the deterministic permutation",
      operation: "Read without replacement, then sort for display",
      output: "07, 23, 44, 68, 89",
    },
    {
      title: "Compare the selection",
      input: "Player selection 07, 23, 44, 68, 89",
      operation: "Exact 5-of-90 set comparison",
      output: "5 of 5 matched · ticket outcome Won",
    },
  ],
  drawPositions: [
    { position: 1, entropyHex: "06", normalizedValue: "Index 7 of 90", prediction: "07", draw: "07", verdict: "Matched" },
    { position: 2, entropyHex: "15", normalizedValue: "Index 22 of 89", prediction: "23", draw: "23", verdict: "Matched" },
    { position: 3, entropyHex: "29", normalizedValue: "Index 42 of 88", prediction: "44", draw: "44", verdict: "Matched" },
    { position: 4, entropyHex: "40", normalizedValue: "Index 65 of 87", prediction: "68", draw: "68", verdict: "Matched" },
    { position: 5, entropyHex: "54", normalizedValue: "Index 85 of 86", prediction: "89", draw: "89", verdict: "Matched" },
  ],
  settlement: {
    multiplier: "5.20×",
    chance: "5 in 90 draw",
    grossKobo: 2_600_00,
    taxRate: "10% WHT",
    taxKobo: 260_00,
    netKobo: 2_340_00,
    ledgerEntries: [
      { reference: "LED-88796", account: "Play Balance", purpose: "Ticket stake", amountKobo: -500_00 },
      { reference: "LED-88795", account: "Winnings Balance", purpose: "Gross payout", amountKobo: 2_600_00 },
      { reference: "LED-88794", account: "Winnings Balance", purpose: "Withholding tax", amountKobo: -260_00 },
    ],
  },
  integrity: {
    recordedOutcomeDigest: "aba01c42dd96df15b8d536a955ea783fce47d79222af060829fa65c9b8cc7610",
    replayOutcomeDigest: "aba01c42dd96df15b8d536a955ea783fce47d79222af060829fa65c9b8cc7610",
    replayedAt: "18 Aug 2026, 2:16:31 PM WAT",
    attestation: "The replay output is byte-for-byte identical to the immutable settlement record.",
  },
};

export const TICKET_REPLAY_FIXTURES: readonly TicketReplayRecord[] = [BLACKRED_REPLAY, HERITAGE_REPLAY];

export function findTicketReplay(rawReference: string) {
  const normalized = rawReference.trim().toLocaleUpperCase();
  return TICKET_REPLAY_FIXTURES.find((ticket) => ticket.reference === normalized) ?? null;
}
