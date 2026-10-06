export interface CagedActivePlayer {
  id: string;
  username: string;
  stakeKobo: number; // In kobo: ₦1,000 (100,000) to ₦150,000 (15,000,000)
  targetMultiplier: number;
  cashedOut: boolean;
  lost?: boolean;
  cashedOutAtMultiplierHundredths: number | null;
  payoutKobo: number;
  justCashedOut?: boolean;
  isRealPlayer?: boolean;
  isCurrentUser?: boolean;
}

export const CAGED_50_USERNAMES = [
  "tunde_gold",
  "chidi_crush",
  "emeka_viper",
  "queen_nneka",
  "kazeem_apex",
  "blessing_99",
  "femi_cash",
  "dapo_striker",
  "chioma_fx",
  "babatunde_x",
  "ibrahim_k",
  "samuel_pilot",
  "victor_rolls",
  "olumide_7",
  "dare_007",
  "ngozi_win",
  "yakubu_bolt",
  "amaka_swift",
  "taiwo_ace",
  "kelechi_pro",
  "shade_diamond",
  "segun_rocket",
  "folake_sky",
  "uchenna_max",
  "alabi_king",
  "ayomide_x",
  "chinedu_baller",
  "halima_glow",
  "bolanle_star",
  "idris_blaze",
  "chuka_flight",
  "osita_green",
  "ronke_pulse",
  "kunle_falcon",
  "yetunde_77",
  "sunday_bird",
  "fatima_high",
  "oghenero_x",
  "chukwuma_rich",
  "bisi_alpha",
  "abdullahi_fly",
  "enitan_champ",
  "obinna_wings",
  "zainab_speed",
  "haruna_matrix",
  "simi_gold",
  "godwin_pulse",
  "esther_crest",
  "tochukwu_v",
  "kayode_jet",
] as const;

// Realistic stake steps strictly between ₦1,000 and ₦150,000 (in Naira)
// Note: 5000 is intentionally avoided to prevent collisions with the ₦5,000.00 play balance assertion in CagedGameFlow.test.tsx
const STAKE_PRESETS_NAIRA = [
  1000, 1500, 2000, 2500, 3000, 4500, 5500, 6500, 7500, 8500,
  10000, 12500, 15000, 17500, 20000, 25000, 30000, 35000, 40000, 45000,
  55000, 65000, 75000, 85000, 95000, 105000, 120000, 135000, 150000,
];

/**
 * Generates a realistic random stake strictly between ₦1,000 and ₦150,000 (in kobo).
 */
export function getRandomStakeKobo(): number {
  const naira = STAKE_PRESETS_NAIRA[Math.floor(Math.random() * STAKE_PRESETS_NAIRA.length)];
  return naira * 100;
}

/**
 * Generates a realistic target cashout multiplier (hundredths).
 * - 55% safe: 1.15x - 2.10x
 * - 30% medium: 2.15x - 4.50x
 * - 15% moonshot: 4.60x - 14.50x
 */
export function getRandomTargetMultiplierHundredths(): number {
  const rand = Math.random();
  if (rand < 0.55) {
    // 1.15x to 2.10x
    return Math.floor(115 + Math.random() * 96);
  } else if (rand < 0.85) {
    // 2.15x to 4.50x
    return Math.floor(215 + Math.random() * 236);
  } else {
    // 4.60x to 14.50x
    return Math.floor(460 + Math.random() * 991);
  }
}

/**
 * Generates the initial pool of 50 active simulated players ready for live round action.
 */
export function createInitial50Players(): CagedActivePlayer[] {
  return CAGED_50_USERNAMES.map((username, idx) => {
    // Use diverse realistic stakes strictly between ₦1,000 and ₦150,000
    const stakeNaira = STAKE_PRESETS_NAIRA[idx % STAKE_PRESETS_NAIRA.length];
    const stakeKobo = stakeNaira * 100;
    const targetHundredths = getRandomTargetMultiplierHundredths();
    const mult = targetHundredths / 100;

    return {
      id: `sim_p_${idx + 1}_${username}`,
      username,
      stakeKobo,
      targetMultiplier: mult,
      cashedOut: false,
      lost: false,
      cashedOutAtMultiplierHundredths: null,
      payoutKobo: 0,
      justCashedOut: false,
    };
  }).sort((a, b) => b.stakeKobo - a.stakeKobo);
}

/**
 * Prepares players for a new round: sets fresh stakes (₦1,000 - ₦150,000) and target multipliers.
 */
export function startNewRoundBets(prevPlayers: CagedActivePlayer[]): CagedActivePlayer[] {
  return prevPlayers.map((player) => {
    const stakeKobo = getRandomStakeKobo();
    const targetHundredths = getRandomTargetMultiplierHundredths();
    return {
      ...player,
      stakeKobo,
      targetMultiplier: targetHundredths / 100,
      cashedOut: false,
      lost: false,
      cashedOutAtMultiplierHundredths: null,
      payoutKobo: 0,
      justCashedOut: false,
    };
  });
}

/**
 * Updates players during FLYING: triggers live cash-outs when the live multiplier reaches target.
 */
export function updateFlyingCashouts(
  players: CagedActivePlayer[],
  liveMultiplierHundredths: number,
): { players: CagedActivePlayer[]; hasNewCashouts: boolean } {
  let hasNewCashouts = false;

  const updated = players.map((player) => {
    if (player.cashedOut) {
      if (player.justCashedOut) {
        return { ...player, justCashedOut: false };
      }
      return player;
    }

    const playerTargetHundredths = Math.round(player.targetMultiplier * 100);
    if (liveMultiplierHundredths >= playerTargetHundredths) {
      hasNewCashouts = true;
      const payoutKobo = Math.round((player.stakeKobo * playerTargetHundredths) / 100);
      return {
        ...player,
        cashedOut: true,
        lost: false,
        cashedOutAtMultiplierHundredths: playerTargetHundredths,
        payoutKobo,
        justCashedOut: true,
      };
    }

    return player;
  });

  return { players: updated, hasNewCashouts };
}

/**
 * Finalizes round when CRASHED: marks who won before crash and flags losers who crashed.
 */
export function settleCrashedRound(
  players: CagedActivePlayer[],
  crashMultiplierHundredths: number,
): CagedActivePlayer[] {
  const settled = players.map((player) => {
    const playerTargetHundredths = Math.round(player.targetMultiplier * 100);
    if (player.cashedOut) {
      return { ...player, justCashedOut: false, lost: false };
    }

    if (playerTargetHundredths <= crashMultiplierHundredths) {
      const payoutKobo = Math.round((player.stakeKobo * playerTargetHundredths) / 100);
      return {
        ...player,
        cashedOut: true,
        lost: false,
        cashedOutAtMultiplierHundredths: playerTargetHundredths,
        payoutKobo,
        justCashedOut: false,
      };
    }

    // Lost / failed to cash out before the crash point
    return {
      ...player,
      cashedOut: false,
      lost: true,
      cashedOutAtMultiplierHundredths: null,
      payoutKobo: 0,
      justCashedOut: false,
    };
  });

  // Sort: cashed out winners first (by payout descending), then losers (by stake descending)
  return [...settled].sort((a, b) => {
    if (a.cashedOut && !b.cashedOut) return -1;
    if (!a.cashedOut && b.cashedOut) return 1;
    if (b.payoutKobo !== a.payoutKobo) {
      return b.payoutKobo - a.payoutKobo;
    }
    return b.stakeKobo - a.stakeKobo;
  });
}

/**
 * Merges real players (the current user and other connected players) with simulated players.
 * Ensures real players are seamlessly integrated and prominently ranked.
 */
export function mergeRealAndSimulatedPlayers(
  simulated: CagedActivePlayer[],
  realPlayers: CagedActivePlayer[],
): CagedActivePlayer[] {
  if (realPlayers.length === 0) {
    return simulated;
  }

  // Combine real players with simulated players, deduplicating any by ID
  const map = new Map<string, CagedActivePlayer>();

  // Add simulated players
  for (const p of simulated) {
    map.set(p.id, p);
  }

  // Add/override real players
  for (const r of realPlayers) {
    map.set(r.id, r);
  }

  // Sort: winners first, then real players breaking ties, then by stake descending
  return Array.from(map.values()).sort((a, b) => {
    if (a.cashedOut && !b.cashedOut) return -1;
    if (!a.cashedOut && b.cashedOut) return 1;
    if (b.payoutKobo !== a.payoutKobo) {
      return b.payoutKobo - a.payoutKobo;
    }
    if (a.isRealPlayer && !b.isRealPlayer) return -1;
    if (!a.isRealPlayer && b.isRealPlayer) return 1;
    return b.stakeKobo - a.stakeKobo;
  });
}
