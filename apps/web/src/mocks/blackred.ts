import { BlackRedGatewayError, type BlackRedEligibilityCode } from "@betplus/api-client";

// Re-exported (not redefined) so `error instanceof BlackRedGatewayError` is true
// regardless of whether the real gateway or this mock threw it — a class defined
// separately here would have a different identity and silently fail that check.
export { BlackRedGatewayError, type BlackRedEligibilityCode };

export type BlackRedColor = "B" | "R";

export interface BlackRedTier {
  positions: 1 | 2 | 3 | 4 | 5;
  multiplierHundredths: number;
  probabilityNumerator: 1;
  probabilityDenominator: 2 | 4 | 8 | 16 | 32;
}

export interface BlackRedDescriptor {
  gameName: "BlackRed";
  description: "Instant fixed-odds prediction";
  playBalanceKobo: number;
  winningsBalanceKobo: number;
  turnoverStakedKobo: number;
  turnoverRequiredKobo: number;
  dailyLimitKobo: number;
  dailyLimitRemainingKobo: number;
  minStakeKobo: number;
  maxStakeKobo: number;
  currency: "NGN";
  stateName: "Lagos";
  taxRateBasisPoints: number;
  taxBasisLabel: "Gross prize";
  rulesetVersion: string;
  prizeTableVersion: string;
  engineVersion: string;
  tiers: BlackRedTier[];
}

export interface BlackRedPurchase {
  reference: string;
  purchasedAt: string;
  prediction: BlackRedColor[];
  stakeKobo: number;
  tier: BlackRedTier;
  playBalanceAfterKobo: number;
  status: "purchased";
}

export interface BlackRedSettlement extends Omit<BlackRedPurchase, "status"> {
  status: "settled";
  result: BlackRedColor[];
  won: boolean;
  grossPrizeKobo: number;
  taxWithheldKobo: number;
  netCreditKobo: number;
  winningsBalanceAfterKobo: number;
  taxRateBasisPoints: number;
  taxBasisLabel: string;
  rulesetVersion: string;
  prizeTableVersion: string;
  engineVersion: string;
  stateName: string;
}


export interface BlackRedGateway {
  loadGame(): Promise<BlackRedDescriptor>;
  purchaseTicket(input: {
    prediction: BlackRedColor[];
    stakeKobo: number;
    idempotencyKey: string;
  }): Promise<BlackRedPurchase>;
  revealTicket(reference: string): Promise<BlackRedSettlement>;
}

export const BLACKRED_WIN_REFERENCE = "BP-BR-240814-WIN";
export const BLACKRED_LOSS_REFERENCE = "BP-BR-240814-LOSS";
export const BLACKRED_TICKET_REFERENCES = [BLACKRED_WIN_REFERENCE, BLACKRED_LOSS_REFERENCE] as const;

const tiers: BlackRedTier[] = [
  { positions: 1, multiplierHundredths: 200, probabilityNumerator: 1, probabilityDenominator: 2 },
  { positions: 2, multiplierHundredths: 1000, probabilityNumerator: 1, probabilityDenominator: 4 },
  { positions: 3, multiplierHundredths: 2000, probabilityNumerator: 1, probabilityDenominator: 8 },
  { positions: 4, multiplierHundredths: 5000, probabilityNumerator: 1, probabilityDenominator: 16 },
  { positions: 5, multiplierHundredths: 10000, probabilityNumerator: 1, probabilityDenominator: 32 },
];

export const MOCK_BLACKRED_DESCRIPTOR: BlackRedDescriptor = {
  gameName: "BlackRed",
  description: "Instant fixed-odds prediction",
  playBalanceKobo: 1_250_000,
  winningsBalanceKobo: 875_000,
  turnoverStakedKobo: 420_000,
  turnoverRequiredKobo: 1_000_000,
  dailyLimitKobo: 5_000_000,
  dailyLimitRemainingKobo: 4_250_000,
  minStakeKobo: 10_000,
  maxStakeKobo: 2_000_000,
  currency: "NGN",
  stateName: "Lagos",
  taxRateBasisPoints: 500,
  taxBasisLabel: "Gross prize",
  rulesetVersion: "LAG-WHT-2026.1",
  prizeTableVersion: "BR-NG-2026.1",
  engineVersion: "blackred-1.0.0",
  tiers,
};

const resolvedTickets = new Map<string, BlackRedSettlement>();

function buildSettlement(
  reference: string,
  prediction: BlackRedColor[],
  stakeKobo: number,
  purchasedAt: string,
): BlackRedSettlement {
  const tier = tiers[prediction.length - 1];
  const result = (["B", "R", "B", "B", "R"] as BlackRedColor[]).slice(0, prediction.length);
  const won = prediction.every((choice, index) => choice === result[index]);
  const grossPrizeKobo = won ? Math.floor((stakeKobo * tier.multiplierHundredths) / 100) : 0;
  const taxWithheldKobo = Math.floor((grossPrizeKobo * MOCK_BLACKRED_DESCRIPTOR.taxRateBasisPoints) / 10_000);
  const netCreditKobo = grossPrizeKobo - taxWithheldKobo;

  return {
    reference,
    purchasedAt,
    prediction,
    stakeKobo,
    tier,
    playBalanceAfterKobo: MOCK_BLACKRED_DESCRIPTOR.playBalanceKobo - stakeKobo,
    status: "settled",
    result,
    won,
    grossPrizeKobo,
    taxWithheldKobo,
    netCreditKobo,
    winningsBalanceAfterKobo: MOCK_BLACKRED_DESCRIPTOR.winningsBalanceKobo + netCreditKobo,
    taxRateBasisPoints: MOCK_BLACKRED_DESCRIPTOR.taxRateBasisPoints,
    taxBasisLabel: MOCK_BLACKRED_DESCRIPTOR.taxBasisLabel,
    rulesetVersion: MOCK_BLACKRED_DESCRIPTOR.rulesetVersion,
    prizeTableVersion: MOCK_BLACKRED_DESCRIPTOR.prizeTableVersion,
    engineVersion: MOCK_BLACKRED_DESCRIPTOR.engineVersion,
    stateName: MOCK_BLACKRED_DESCRIPTOR.stateName,
  };
}

/** Temporary Epic 3 adapter. Replace after api-types publishes the game contract. */
export const mockBlackRedGateway: BlackRedGateway = {
  async loadGame() {
    await Promise.resolve();
    return MOCK_BLACKRED_DESCRIPTOR;
  },
  async purchaseTicket({ prediction, stakeKobo }) {
    await Promise.resolve();
    if (stakeKobo > MOCK_BLACKRED_DESCRIPTOR.playBalanceKobo) {
      throw new BlackRedGatewayError("INSUFFICIENT_PLAY_BALANCE");
    }
    const expected = (["B", "R", "B", "B", "R"] as BlackRedColor[]).slice(0, prediction.length);
    const won = prediction.every((choice, index) => choice === expected[index]);
    const reference = won ? BLACKRED_WIN_REFERENCE : BLACKRED_LOSS_REFERENCE;
    const purchasedAt = "2026-08-14T15:20:00.000Z";
    const settlement = buildSettlement(reference, prediction, stakeKobo, purchasedAt);
    resolvedTickets.set(reference, settlement);

    return {
      reference,
      purchasedAt,
      prediction,
      stakeKobo,
      tier: settlement.tier,
      playBalanceAfterKobo: settlement.playBalanceAfterKobo,
      status: "purchased",
    };
  },
  async revealTicket(reference) {
    await Promise.resolve();
    const settlement = resolvedTickets.get(reference) ?? getMockBlackRedTicket(reference);
    if (!settlement) throw new Error("TICKET_NOT_READY");
    return settlement;
  },
};

const winReceipt = buildSettlement(BLACKRED_WIN_REFERENCE, ["B", "R"], 100_000, "2026-08-14T15:20:00.000Z");
const lossReceipt = buildSettlement(BLACKRED_LOSS_REFERENCE, ["R", "R"], 100_000, "2026-08-14T15:25:00.000Z");

export function getMockBlackRedTicket(reference: string): BlackRedSettlement | undefined {
  return [winReceipt, lossReceipt].find((ticket) => ticket.reference === reference);
}

export function getBlackRedTier(descriptor: BlackRedDescriptor, positions: number): BlackRedTier | undefined {
  return descriptor.tiers.find((tier) => tier.positions === positions);
}

export function calculateGrossReturnKobo(stakeKobo: number, tier: BlackRedTier): number {
  return Math.floor((stakeKobo * tier.multiplierHundredths) / 100);
}

export function calculateTaxKobo(grossKobo: number, taxRateBasisPoints: number): number {
  return Math.floor((grossKobo * taxRateBasisPoints) / 10_000);
}

export function formatProbability(tier: BlackRedTier): string {
  const percentage = (tier.probabilityNumerator / tier.probabilityDenominator) * 100;
  return `${new Intl.NumberFormat("en-NG", { maximumFractionDigits: 3 }).format(percentage)}%`;
}

export function formatMultiplier(tier: BlackRedTier): string {
  const mult = tier.multiplierHundredths / 100;
  return Number.isInteger(mult) ? `${mult}×` : `${mult.toFixed(2)}×`;
}
