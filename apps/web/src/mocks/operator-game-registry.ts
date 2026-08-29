export interface GameRegistryRecord {
  code: "BLACKRED" | "HERITAGE";
  name: "BlackRed" | "Heritage";
  engineEndpoint: string;
  engineVersion: string;
  activePrizeTable: string;
  minStakeKobo: number;
  maxStakeKobo: number;
  enabledChannels: readonly string[];
  enabledStates: readonly string[];
  suspendedChannels: readonly string[];
  suspendedStates: readonly string[];
  status: "Active" | "Partially suspended";
}

export interface PrizeTableDraft {
  reference: string;
  game: "BlackRed" | "Heritage";
  bounds: string;
  aggregateRtp: string;
  netRtp: string;
  withholdingRate: string;
  actuarialCertification: string;
  validation: "Valid · certification required" | "Blocked · RTP outside bounds";
  tiers: readonly {
    label: string;
    chance: string;
    multiplier: string;
    modelledRtp: string;
  }[];
}

export const GAME_REGISTRY: readonly GameRegistryRecord[] = [
  {
    code: "BLACKRED",
    name: "BlackRed",
    engineEndpoint: "https://engine-blackred.internal/v1",
    engineVersion: "blackred-2.8.1",
    activePrizeTable: "BR-PT-v11",
    minStakeKobo: 100_00,
    maxStakeKobo: 20_000_00,
    enabledChannels: ["Web", "Mobile"],
    enabledStates: ["Enugu", "Lagos", "Rivers"],
    suspendedChannels: ["USSD"],
    suspendedStates: ["Kano"],
    status: "Partially suspended",
  },
  {
    code: "HERITAGE",
    name: "Heritage",
    engineEndpoint: "https://engine-heritage.internal/v1",
    engineVersion: "heritage-1.14.0",
    activePrizeTable: "HG-5/90-v7",
    minStakeKobo: 100_00,
    maxStakeKobo: 5_000_00,
    enabledChannels: ["Web", "Mobile", "USSD"],
    enabledStates: ["Enugu", "Lagos", "Rivers"],
    suspendedChannels: [],
    suspendedStates: ["Kano"],
    status: "Partially suspended",
  },
];

export const PRIZE_TABLE_DRAFTS: readonly PrizeTableDraft[] = [
  {
    reference: "BR-PT-v12-DRAFT",
    game: "BlackRed",
    bounds: "Configured aggregate bound 82.00%–88.00%",
    aggregateRtp: "85.40%",
    netRtp: "76.86%",
    withholdingRate: "10% WHT",
    actuarialCertification: "",
    validation: "Valid · certification required",
    tiers: [
      { label: "1 card", chance: "1 in 2", multiplier: "1.85×", modelledRtp: "92.50%" },
      { label: "2 cards", chance: "1 in 4", multiplier: "3.60×", modelledRtp: "90.00%" },
      { label: "3 cards", chance: "1 in 8", multiplier: "7.00×", modelledRtp: "87.50%" },
      { label: "4 cards", chance: "1 in 16", multiplier: "13.50×", modelledRtp: "84.38%" },
      { label: "5 cards", chance: "1 in 32", multiplier: "26.00×", modelledRtp: "81.25%" },
    ],
  },
  {
    reference: "HG-5/90-v8-DRAFT",
    game: "Heritage",
    bounds: "Configured aggregate bound 80.00%–90.00%",
    aggregateRtp: "93.40%",
    netRtp: "84.06%",
    withholdingRate: "10% WHT",
    actuarialCertification: "ACT-HG-2026-081",
    validation: "Blocked · RTP outside bounds",
    tiers: [
      { label: "2 matches", chance: "1 in 9", multiplier: "2.00×", modelledRtp: "88.00%" },
      { label: "3 matches", chance: "1 in 42", multiplier: "16.00×", modelledRtp: "91.20%" },
      { label: "4 matches", chance: "1 in 511", multiplier: "240.00×", modelledRtp: "94.60%" },
      { label: "5 matches", chance: "1 in 43,949", multiplier: "41,000.00×", modelledRtp: "99.80%" },
    ],
  },
];
