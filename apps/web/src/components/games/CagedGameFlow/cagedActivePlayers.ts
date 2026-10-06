export interface CagedActivePlayer {
  id: string;
  username: string; // Masked phone number, e.g. 0803xx1948
  stakeKobo: number; // In kobo: ₦1,000 (100,000) to ₦150,000 (15,000,000)
  targetMultiplier: number;
  cashedOut: boolean;
  lost?: boolean;
  cashedOutAtMultiplierHundredths: number | null;
  payoutKobo: number;
  justCashedOut?: boolean;
  isRealPlayer?: boolean;
  isCurrentUser?: boolean;
  avatarColor?: string;
  placeBetDelayMs?: number;
}

export const AVATAR_GRADIENTS = [
  "linear-gradient(135deg, #10b981 0%, #047857 100%)", // Emerald
  "linear-gradient(135deg, #f59e0b 0%, #b45309 100%)", // Amber
  "linear-gradient(135deg, #6366f1 0%, #4338ca 100%)", // Indigo
  "linear-gradient(135deg, #ec4899 0%, #be185d 100%)", // Pink
  "linear-gradient(135deg, #06b6d4 0%, #0e7490 100%)", // Cyan
  "linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)", // Purple
  "linear-gradient(135deg, #14b8a6 0%, #0f766e 100%)", // Teal
  "linear-gradient(135deg, #f43f5e 0%, #be123c 100%)", // Rose
  "linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)", // Blue
  "linear-gradient(135deg, #84cc16 0%, #4d7c0f 100%)", // Lime
] as const;

// 50 realistic masked Nigerian phone numbers with 'xx' hiding middle digits
export const CAGED_50_USERNAMES = [
  "0803xx1948",
  "0812xx8492",
  "0906xx3310",
  "0705xx7124",
  "0802xx5912",
  "0816xx4401",
  "0809xx6723",
  "0813xx9081",
  "0708xx1129",
  "0903xx4872",
  "0806xx3128",
  "0814xx5590",
  "0902xx6643",
  "0815xx8219",
  "0805xx4931",
  "0810xx7714",
  "0818xx3820",
  "0808xx9921",
  "0905xx1284",
  "0807xx6402",
  "0701xx8390",
  "0817xx4501",
  "0907xx2918",
  "0811xx9342",
  "0706xx5180",
  "0909xx7721",
  "0803xx4198",
  "0812xx3602",
  "0906xx8149",
  "0705xx9214",
  "0802xx1843",
  "0816xx7029",
  "0809xx3381",
  "0813xx6274",
  "0708xx4902",
  "0903xx1583",
  "0806xx8247",
  "0814xx2931",
  "0902xx8490",
  "0815xx3172",
  "0805xx7629",
  "0810xx4831",
  "0818xx9104",
  "0808xx2458",
  "0905xx6721",
  "0807xx1938",
  "0701xx4280",
  "0817xx8319",
  "0907xx5640",
  "0811xx2719",
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
 * Picks a realistic dynamic active player count between 18 and 38 for the round.
 */
export function getRandomActiveCount(min: number = 18, max: number = 38): number {
  return Math.floor(min + Math.random() * (max - min + 1));
}

/**
 * Generates an active cohort of simulated players from the 50 usernames pool.
 * If count is provided, generates exactly that many players.
 * If count is omitted, picks a dynamic number between 18 and 38.
 */
export function createInitialActivePlayers(count?: number): CagedActivePlayer[] {
  const targetCount = count ?? getRandomActiveCount(18, 38);
  const shuffledNames = [...CAGED_50_USERNAMES].sort(() => Math.random() - 0.5).slice(0, targetCount);

  return shuffledNames.map((username, idx) => {
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
      avatarColor: AVATAR_GRADIENTS[idx % AVATAR_GRADIENTS.length],
      placeBetDelayMs: 0,
    };
  }).sort((a, b) => b.stakeKobo - a.stakeKobo);
}

/**
 * Alias for createInitialActivePlayers, allowing backwards compatibility.
 */
export function createInitial50Players(count?: number): CagedActivePlayer[] {
  return createInitialActivePlayers(count);
}

/**
 * Prepares players for a new round: picks a realistic dynamic active cohort (18 - 38 players)
 * from the pool so not all 50 players play every time. Players dynamically enter, sit out,
 * and rotate between sessions. Sets fresh stakes (₦1,000 - ₦150,000) and staggered bet placement delays.
 */
export function startNewRoundBets(
  prevPlayers: CagedActivePlayer[],
  bettingWindowSeconds: number = 5,
  targetCount?: number,
): CagedActivePlayer[] {
  const activeCount = targetCount ?? getRandomActiveCount(18, 38);
  const maxDelayMs = Math.max(800, (bettingWindowSeconds || 5) * 1000 - 300);

  // Realistic player retention: ~65% of current active players continue into next round,
  // while the rest sit out and new players from the inactive pool jump in.
  const prevUsernames = new Set(prevPlayers.map((p) => p.username));
  const availablePool = [...CAGED_50_USERNAMES];

  // Candidates who played last round vs candidates who sat out
  const continuingPool = availablePool.filter((name) => prevUsernames.has(name)).sort(() => Math.random() - 0.5);
  const sittingOutPool = availablePool.filter((name) => !prevUsernames.has(name)).sort(() => Math.random() - 0.5);

  const desiredContinuing = Math.min(continuingPool.length, Math.round(activeCount * 0.65));
  const desiredNew = activeCount - desiredContinuing;

  const selectedUsernames = [
    ...continuingPool.slice(0, desiredContinuing),
    ...sittingOutPool.slice(0, desiredNew),
  ];

  // If we still need more to meet activeCount, fill from remaining
  if (selectedUsernames.length < activeCount) {
    const remaining = availablePool.filter((name) => !selectedUsernames.includes(name));
    selectedUsernames.push(...remaining.slice(0, activeCount - selectedUsernames.length));
  }

  const shuffledIndices = Array.from({ length: selectedUsernames.length }, (_, i) => i).sort(
    () => Math.random() - 0.5,
  );

  return selectedUsernames
    .map((username, idx) => {
      const stakeKobo = getRandomStakeKobo();
      const targetHundredths = getRandomTargetMultiplierHundredths();
      const staggerOrder = shuffledIndices[idx];
      // Stagger from 80ms up to maxDelayMs so bets stream in dynamically
      const placeBetDelayMs = Math.floor(
        80 + (staggerOrder / selectedUsernames.length) * (maxDelayMs - 80) + Math.random() * 50,
      );

      // Preserve avatar gradient if player was previously active, else pick from list
      const prev = prevPlayers.find((p) => p.username === username);
      const avatarColor =
        prev?.avatarColor ??
        AVATAR_GRADIENTS[Math.abs(username.charCodeAt(3) || 0) % AVATAR_GRADIENTS.length];

      return {
        id: `sim_p_${idx + 1}_${username}`,
        username,
        stakeKobo,
        targetMultiplier: targetHundredths / 100,
        cashedOut: false,
        lost: false,
        cashedOutAtMultiplierHundredths: null,
        payoutKobo: 0,
        justCashedOut: false,
        avatarColor,
        placeBetDelayMs,
      };
    })
    .sort((a, b) => b.stakeKobo - a.stakeKobo);
}

/**
 * Updates players during FLYING: triggers live cash-outs when the live multiplier reaches target.
 * Prevents invalid cashouts above the known crash multiplier.
 */
export function updateFlyingCashouts(
  players: CagedActivePlayer[],
  liveMultiplierHundredths: number,
  crashMultiplierHundredths?: number | null,
): { players: CagedActivePlayer[]; hasNewCashouts: boolean } {
  let hasNewCashouts = false;

  const cap =
    crashMultiplierHundredths !== null && crashMultiplierHundredths !== undefined
      ? crashMultiplierHundredths
      : Infinity;

  const updated = players.map((player) => {
    if (player.cashedOut) {
      if (player.justCashedOut) {
        return { ...player, justCashedOut: false };
      }
      return player;
    }

    const playerTargetHundredths = Math.round(player.targetMultiplier * 100);
    if (
      liveMultiplierHundredths >= playerTargetHundredths &&
      playerTargetHundredths <= cap
    ) {
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
 * Never allows a player whose target or cashout multiplier exceeded the crash multiplier to win.
 * Preserves strict sorting from highest stake to lowest stake (does not push winners up).
 */
export function settleCrashedRound(
  players: CagedActivePlayer[],
  crashMultiplierHundredths: number,
): CagedActivePlayer[] {
  const settled = players.map((player) => {
    const playerTargetHundredths = Math.round(player.targetMultiplier * 100);

    // If player's target or supposedly cashed-out multiplier exceeded the crash point:
    // They did not escape in time! They crashed and lost!
    if (
      playerTargetHundredths > crashMultiplierHundredths ||
      (player.cashedOutAtMultiplierHundredths !== null &&
        player.cashedOutAtMultiplierHundredths > crashMultiplierHundredths)
    ) {
      return {
        ...player,
        cashedOut: false,
        lost: true,
        cashedOutAtMultiplierHundredths: null,
        payoutKobo: 0,
        justCashedOut: false,
      };
    }

    // Player genuinely cashed out at or before the crash multiplier
    if (player.cashedOut && player.cashedOutAtMultiplierHundredths !== null) {
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

  // Always arrange strictly from highest stake to lowest stake (do NOT push winners up)
  return [...settled].sort((a, b) => b.stakeKobo - a.stakeKobo);
}

/**
 * Merges real players (the current user and other connected players) with simulated players.
 * Keeps all players ordered strictly from highest stake to lowest stake across all round phases.
 */
export function mergeRealAndSimulatedPlayers(
  simulated: CagedActivePlayer[],
  realPlayers: CagedActivePlayer[],
): CagedActivePlayer[] {
  if (realPlayers.length === 0) {
    return [...simulated].sort((a, b) => b.stakeKobo - a.stakeKobo);
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

  // Sort strictly from highest stake to lowest stake; real players break ties
  return Array.from(map.values()).sort((a, b) => {
    if (b.stakeKobo !== a.stakeKobo) {
      return b.stakeKobo - a.stakeKobo;
    }
    if (a.isRealPlayer && !b.isRealPlayer) return -1;
    if (!a.isRealPlayer && b.isRealPlayer) return 1;
    return 0;
  });
}
