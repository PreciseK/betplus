import { HeritageGatewayError, type HeritageGatewayErrorCode } from "@betplus/api-client";

// Re-exported (not redefined) so `error instanceof HeritageGatewayError` is true
// regardless of whether the real gateway or this mock threw it.
export { HeritageGatewayError, type HeritageGatewayErrorCode };

export type HeritageLeader = "king" | "queen";

export interface HeritageTradition {
  id: string;
  name: string;
  kingTitle?: string;
  queenTitle?: string;
}

export interface HeritagePrizeTier {
  id: "jackpot" | "high" | "second-chance" | "loss";
  label: string;
  matches: string;
  probabilityBasisPoints: number;
  multiplier?: number;
}

export interface HeritageCatalogueItem {
  number: number;
  canonicalName: string;
  localName?: string;
  origin: string;
  context: string;
  slot: "head" | "neck" | "torso" | "waist" | "wrist" | "hand" | "feet";
  depiction: "abstract-placeholder";
  applicableTraditions: readonly string[];
  applicableLeaders: readonly HeritageLeader[];
  layerPriority: number;
  depictionConstraints: string;
  advisorSignOffReference: string | null;
  publicationStatus: "preview-only" | "approved";
}

export interface HeritageBoardPosition {
  position: number;
  selected: boolean;
  winning: boolean;
  item: HeritageCatalogueItem;
}

export type SecondChanceStatus = "pending" | "lodged" | "delayed" | "compensated";

export interface HeritageSecondChance {
  status: SecondChanceStatus;
  entryStakeKobo: number;
  numbers: number[];
  drawName: string;
  drawAt: string;
  partnerReference?: string;
  receiptDueAt?: string;
  drawsMissed: number;
  rolloverReason?: string;
  compensationKobo?: number;
  resultNotification: "pending" | "scheduled" | "sent";
}

export interface HeritageSettlement {
  reference: string;
  settledAt: string;
  tier: HeritagePrizeTier["id"];
  matchCount: number;
  stakeKobo: number;
  grossKobo: number;
  taxKobo: number;
  netKobo: number;
  board: HeritageBoardPosition[];
  secondChance?: HeritageSecondChance;
}

export interface HeritageSnapshot {
  playBalanceKobo: number;
  winningsBalanceKobo: number;
  depositStakedKobo: number;
  depositRequiredKobo: number;
  minStakeKobo: number;
  maxStakeKobo: number;
  taxRateBasisPoints: number;
  traditions: HeritageTradition[];
  prizeTiers: HeritagePrizeTier[];
  session: {
    rounds: number;
    totalStakedKobo: number;
    totalWonKobo: number;
    netPositionKobo: number;
    elapsedSeconds: number;
    activeLimitKobo: number;
  };
}

export interface HeritagePlayInput {
  selectedPositions: number[];
  traditionId: string;
  leader: HeritageLeader;
  stakeKobo: number;
}

export interface HeritageGateway {
  load(): Promise<HeritageSnapshot>;
  quickPick(): Promise<number[]>;
  placeTicket(input: HeritagePlayInput): Promise<HeritageSettlement>;
}

const TRADITIONS: HeritageTradition[] = [
  { id: "yoruba", name: "Yoruba", kingTitle: "Ọba", queenTitle: "Olórì" },
  { id: "igbo", name: "Igbo", kingTitle: "Eze", queenTitle: "Lolo" },
  { id: "hausa-fulani", name: "Hausa–Fulani", kingTitle: "Sarki", queenTitle: "Sarauniya" },
  { id: "edo", name: "Edo (Benin)", kingTitle: "Ọba", queenTitle: "Iyọba" },
  { id: "efik-ibibio", name: "Efik–Ibibio", kingTitle: "Obong", queenTitle: "Ọbọñ an Iban" },
  { id: "ijaw", name: "Ijaw", kingTitle: "Amanyanabo" },
  { id: "middle-belt", name: "Middle Belt", kingTitle: "Etsu / Shehu / Tor" },
];

export const HERITAGE_PRIZE_TIERS: HeritagePrizeTier[] = [
  { id: "jackpot", label: "Jackpot", matches: "5 of 5", probabilityBasisPoints: 200, multiplier: 25 },
  { id: "high", label: "You tried", matches: "4 of 5", probabilityBasisPoints: 800, multiplier: 0.5 },
  { id: "loss", label: "No prize", matches: "1–3 match", probabilityBasisPoints: 9_000 },
];

type CatalogueSeed = Pick<HeritageCatalogueItem, "canonicalName" | "localName" | "origin" | "context" | "slot">;

const FEATURED_CATALOGUE = new Map<number, CatalogueSeed>([
  [7, { canonicalName: "Beaded crown", localName: "Adé", origin: "Yoruba", context: "A sacred royal form represented abstractly pending advisory approval.", slot: "head" }],
  [14, { canonicalName: "Coral collar", origin: "Edo (Benin)", context: "Coral is associated with royal identity and must be depicted with care.", slot: "neck" }],
  [23, { canonicalName: "Royal wrapper", origin: "Igbo", context: "A layered textile form reserved here as an approved-content placeholder.", slot: "waist" }],
  [31, { canonicalName: "Ceremonial turban", origin: "Hausa–Fulani", context: "A structured head covering shown without reference to a living ruler.", slot: "head" }],
  [42, { canonicalName: "Court necklace", origin: "Efik–Ibibio", context: "A neck ornament awaiting its final advisor-supplied local name and description.", slot: "neck" }],
  [55, { canonicalName: "Royal staff", origin: "Ijaw", context: "A symbol of office rendered as a neutral silhouette for this preview.", slot: "hand" }],
  [63, { canonicalName: "Embroidered robe", origin: "Middle Belt", context: "A garment layer whose final tradition-specific treatment requires sign-off.", slot: "torso" }],
  [74, { canonicalName: "Beaded wristwear", origin: "Across Nigeria", context: "A wrist ornament labelled by origin when approved catalogue content is connected.", slot: "wrist" }],
  [88, { canonicalName: "Royal sandals", origin: "Across Nigeria", context: "A footwear layer represented without copying any existing palace regalia.", slot: "feet" }],
]);

const SLOT_SEQUENCE: HeritageCatalogueItem["slot"][] = ["head", "neck", "torso", "waist", "wrist", "hand", "feet"];
const ALL_TRADITIONS = Object.freeze(TRADITIONS.map((tradition) => tradition.id));
const ALL_LEADERS: readonly HeritageLeader[] = Object.freeze(["king", "queen"]);

function createCatalogueItem(number: number): HeritageCatalogueItem {
  const featured = FEATURED_CATALOGUE.get(number);
  return Object.freeze({
    number,
    canonicalName: featured?.canonicalName ?? `Reserved catalogue item ${String(number).padStart(2, "0")}`,
    localName: featured?.localName,
    origin: featured?.origin ?? "Advisory review pending",
    context: featured?.context ?? "This fixed catalogue position remains unpublished until its name, origin and depiction receive recorded cultural-advisor sign-off.",
    slot: featured?.slot ?? SLOT_SEQUENCE[(number - 1) % SLOT_SEQUENCE.length],
    depiction: "abstract-placeholder" as const,
    applicableTraditions: ALL_TRADITIONS,
    applicableLeaders: ALL_LEADERS,
    layerPriority: ((number - 1) % 7) + 1,
    depictionConstraints: "Abstract prototype only; do not copy living monarchs, active palace regalia, sacred objects or protected insignia.",
    advisorSignOffReference: null,
    publicationStatus: "preview-only" as const,
  });
}

// The frontend contract is an immutable 1–90 mapping. Unapproved positions stay
// explicitly withheld instead of being filled with invented cultural claims.
export const HERITAGE_CATALOGUE: readonly HeritageCatalogueItem[] = Object.freeze(
  Array.from({ length: 90 }, (_, index) => createCatalogueItem(index + 1)),
);

const BOARD_CATALOGUE_NUMBERS = [7, 14, 23, 31, 42, 55, 63, 74, 88] as const;
const PREVIEW_CATALOGUE = BOARD_CATALOGUE_NUMBERS.map((number) => HERITAGE_CATALOGUE[number - 1]);

const INITIAL_SNAPSHOT: HeritageSnapshot = {
  playBalanceKobo: 1_250_000,
  winningsBalanceKobo: 875_000,
  depositStakedKobo: 420_000,
  depositRequiredKobo: 1_000_000,
  minStakeKobo: 10_000,
  maxStakeKobo: 2_000_000,
  taxRateBasisPoints: 500,
  traditions: TRADITIONS,
  prizeTiers: HERITAGE_PRIZE_TIERS,
  session: {
    rounds: 7,
    totalStakedKobo: 750_000,
    totalWonKobo: 360_000,
    netPositionKobo: -390_000,
    elapsedSeconds: 14 * 60 + 22,
    activeLimitKobo: 5_000_000,
  },
};

let roundIndex = 0;

function makeBoard(selectedPositions: number[], tier: HeritagePrizeTier["id"]): HeritageBoardPosition[] {
  const selected = new Set(selectedPositions);
  const selectedList = [...selectedPositions].sort((a, b) => a - b);
  const unselectedList = Array.from({ length: 9 }, (_, index) => index + 1).filter((position) => !selected.has(position));
  const matches = tier === "jackpot" ? 5 : tier === "high" ? 4 : tier === "second-chance" ? 3 : 2;
  const winners = new Set([
    ...selectedList.slice(0, matches),
    ...unselectedList.slice(0, 5 - matches),
  ]);

  return PREVIEW_CATALOGUE.map((item, index) => ({
    position: index + 1,
    selected: selected.has(index + 1),
    winning: winners.has(index + 1),
    item,
  }));
}

export interface HeritageSettlementOptions {
  secondChanceStatus?: SecondChanceStatus;
  afterCutOff?: boolean;
  drawsMissed?: number;
}

export function createHeritageSettlement(
  input: HeritagePlayInput,
  tier: HeritagePrizeTier["id"],
  options: HeritageSettlementOptions = {},
): HeritageSettlement {
  const multiplier = tier === "jackpot" ? 25 : tier === "high" ? 0.5 : 0;
  const grossKobo = Math.round(input.stakeKobo * multiplier);
  const taxableWinningsKobo = Math.max(0, grossKobo - input.stakeKobo);
  const taxKobo = Math.round(taxableWinningsKobo * INITIAL_SNAPSHOT.taxRateBasisPoints / 10_000);
  const board = makeBoard(input.selectedPositions, tier);
  const matchCount = board.filter((position) => position.selected && position.winning).length;
  const reference = `BP-HG-260817-${String(roundIndex + 1).padStart(3, "0")}`;

  return {
    reference,
    settledAt: "2026-08-17T16:42:00+01:00",
    tier,
    matchCount,
    stakeKobo: input.stakeKobo,
    grossKobo,
    taxKobo,
    netKobo: grossKobo - taxKobo,
    board,
    secondChance: tier === "second-chance" ? {
      status: options.secondChanceStatus ?? "lodged",
      entryStakeKobo: Math.round(input.stakeKobo * 0.1),
      numbers: board.filter((position) => position.selected).map((position) => position.item.number),
      drawName: options.afterCutOff ? "National 5/90 Next Eligible Draw" : "National 5/90 Evening Draw",
      drawAt: options.afterCutOff ? "2026-08-18T19:30:00+01:00" : "2026-08-17T19:30:00+01:00",
      partnerReference: (options.secondChanceStatus ?? "lodged") === "lodged" ? `NG590-${reference.slice(-3)}-8421` : undefined,
      receiptDueAt: (options.secondChanceStatus ?? "lodged") === "lodged" ? "2026-08-17T16:47:00+01:00" : undefined,
      drawsMissed: options.drawsMissed ?? 0,
      rolloverReason: options.afterCutOff
        ? "The current draw cut-off had passed, so the entry moved to the next eligible draw."
        : (options.secondChanceStatus === "delayed" ? "The draw partner has not acknowledged the entry yet." : undefined),
      compensationKobo: options.secondChanceStatus === "compensated" ? Math.round(input.stakeKobo * 0.1) : undefined,
      resultNotification: options.secondChanceStatus === "compensated" ? "sent" : options.secondChanceStatus === "lodged" || options.secondChanceStatus === undefined ? "scheduled" : "pending",
    } : undefined,
  };
}

export const mockHeritageGateway: HeritageGateway = {
  async load() {
    return structuredClone(INITIAL_SNAPSHOT);
  },

  async quickPick() {
    await Promise.resolve();
    return [1, 3, 5, 7, 9];
  },

  async placeTicket(input) {
    if (input.selectedPositions.length !== 5 || new Set(input.selectedPositions).size !== 5) {
      throw new HeritageGatewayError("INVALID_SELECTION");
    }
    if (input.stakeKobo > INITIAL_SNAPSHOT.playBalanceKobo) {
      throw new HeritageGatewayError("INSUFFICIENT_PLAY_BALANCE");
    }
    if (input.stakeKobo < INITIAL_SNAPSHOT.minStakeKobo || input.stakeKobo > INITIAL_SNAPSHOT.maxStakeKobo) {
      throw new HeritageGatewayError("LIMIT_REACHED");
    }

    await new Promise((resolve) => setTimeout(resolve, 260));
    const tierCycle: HeritagePrizeTier["id"][] = ["high", "loss", "jackpot", "loss"];
    const settlement = createHeritageSettlement(input, tierCycle[roundIndex % tierCycle.length]);
    roundIndex += 1;
    return settlement;
  },
};

export function formatProbability(probabilityBasisPoints: number) {
  const denominator = 10_000 / probabilityBasisPoints;
  const frequency = Number.isInteger(denominator) ? String(denominator) : denominator.toFixed(2);
  return `1 in ${frequency} · ${(probabilityBasisPoints / 100).toFixed(2)}%`;
}

export function getLeaderTitle(tradition: HeritageTradition | undefined, leader: HeritageLeader | "") {
  if (!tradition || !leader) return "Not selected";
  return leader === "king"
    ? `King${tradition.kingTitle ? ` (${tradition.kingTitle})` : ""}`
    : `Queen${tradition.queenTitle ? ` (${tradition.queenTitle})` : ""}`;
}
