import { ApiError, get, post } from "./http";
import { responsiblePlayGateway } from "./responsiblePlayGateway";

export type HeritageGatewayErrorCode =
  | "INSUFFICIENT_PLAY_BALANCE"
  | "INVALID_SELECTION"
  | "LIMIT_REACHED"
  | "PLAY_BLOCKED"
  | "GAME_UNAVAILABLE";

/** Structurally the same shape as apps/web's HeritageGatewayError (mocks/heritage.ts). */
export class HeritageGatewayError extends Error {
  constructor(public readonly code: HeritageGatewayErrorCode) {
    super(code);
    this.name = "HeritageGatewayError";
  }
}

type ApiTier = { name: string; probability_basis_points: number; multiplier_hundredths: number; outcome_type: string };
type ApiTradition = { code: string; label: string; king_title: string; queen_title: string };

type ApiCatalogueItem = {
  number: number;
  canonical_name: string;
  local_name: string | null;
  origin: string;
  context: string | null;
  slot: "head" | "neck" | "torso" | "waist" | "wrist" | "hand" | "feet";
  depiction: "abstract-placeholder";
  applicable_traditions: string[];
  applicable_leaders: Array<"king" | "queen">;
  layer_priority: number;
  depiction_constraints: string;
  advisor_sign_off_reference: string | null;
  publication_status: "preview-only" | "approved";
};

type ApiBoardPosition = { position: number; number: number; picked: boolean; winning: boolean };

let catalogueCache: Promise<Map<number, ApiCatalogueItem>> | undefined;

/** The 90-item catalogue is static reference data — fetched once, cached for the session. */
function loadCatalogue(): Promise<Map<number, ApiCatalogueItem>> {
  catalogueCache ??= get<{ items: ApiCatalogueItem[] }>("/heritage/catalogue").then(
    (response) => new Map(response.items.map((item) => [item.number, item])),
  );

  return catalogueCache;
}

function toCatalogueItem(item: ApiCatalogueItem) {
  return {
    number: item.number,
    canonicalName: item.canonical_name,
    localName: item.local_name ?? undefined,
    origin: item.origin,
    context: item.context ?? "",
    slot: item.slot,
    depiction: item.depiction,
    applicableTraditions: item.applicable_traditions,
    applicableLeaders: item.applicable_leaders,
    layerPriority: item.layer_priority,
    depictionConstraints: item.depiction_constraints,
    advisorSignOffReference: item.advisor_sign_off_reference,
    publicationStatus: item.publication_status,
  };
}

/**
 * Real Story 7.1-7.4 implementation of apps/web's HeritageGateway
 * (apps/web/src/mocks/heritage.ts). Field names beyond what HeritageController
 * itself defines aren't a third party's contract to guess at — this is Betplus's
 * own /v1/heritage* surface, fully tested against the real engine and catalogue.
 */
export const heritageGateway = {
  async load() {
    const [game, limits] = await Promise.all([
      get<{
        game_name: "Heritage"; description: string;
        play_balance_kobo: number; winnings_balance_kobo: number;
        min_stake_kobo: number; max_stake_kobo: number; currency: "NGN"; state_name: string;
        board_size: 9; pick_size: 5; lowest_tier_label: string;
        prize_table_version: string; engine_version: string;
        tiers: ApiTier[]; traditions: ApiTradition[];
        current_tradition: string | null; current_leader_type: "king" | "queen" | null;
      }>("/games/heritage"),
      responsiblePlayGateway.load().catch(() => undefined),
    ]);

    const dailyStakeLimitKobo = limits?.limits.find((l) => l.key === "stake-daily")?.currentValue ?? 0;

    return {
      playBalanceKobo: game.play_balance_kobo,
      winningsBalanceKobo: game.winnings_balance_kobo,
      depositStakedKobo: 0,
      depositRequiredKobo: 0,
      minStakeKobo: game.min_stake_kobo,
      maxStakeKobo: game.max_stake_kobo,
      taxRateBasisPoints: 0,
      traditions: game.traditions.map((t) => ({ id: t.code, name: t.label, kingTitle: t.king_title, queenTitle: t.queen_title })),
      prizeTiers: game.tiers.map((t) => ({
        id: (t.outcome_type as "jackpot" | "high" | "second-chance" | "loss"),
        label: t.name,
        matches: t.name,
        probabilityBasisPoints: t.probability_basis_points,
        multiplier: t.multiplier_hundredths ? t.multiplier_hundredths / 100 : undefined,
      })),
      // No per-session tracking exists server-side (a "session" is a browser-only
      // concept) — this starts a fresh session at zero rather than inventing history.
      session: {
        rounds: 0,
        totalStakedKobo: 0,
        totalWonKobo: 0,
        netPositionKobo: 0,
        elapsedSeconds: 0,
        activeLimitKobo: dailyStakeLimitKobo,
      },
    };
  },

  /**
   * Positions carry no odds — REQ-HG "your choice changes where items appear,
   * never the predetermined outcome" — so picking 5 of 9 client-side with no
   * server round trip is correct, not a shortcut.
   */
  async quickPick() {
    const positions = [0, 1, 2, 3, 4, 5, 6, 7, 8];
    for (let i = positions.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [positions[i], positions[j]] = [positions[j], positions[i]];
    }

    return positions.slice(0, 5).sort((a, b) => a - b);
  },

  async placeTicket(input: { selectedPositions: number[]; traditionId: string; leader: "king" | "queen"; stakeKobo: number }) {
    let purchase: { reference: string; status: "purchased" };
    try {
      purchase = await post<{ reference: string; status: "purchased" }>("/heritage/tickets", {
        selected_positions: input.selectedPositions,
        stake_kobo: input.stakeKobo,
        idempotency_key: crypto.randomUUID(),
        tradition: input.traditionId,
        leader_type: input.leader,
      });
    } catch (error) {
      throw toHeritageError(error);
    }

    const [reveal, catalogue] = await Promise.all([
      get<{
        reference: string; stake_kobo: number;
        board: number[]; positions: ApiBoardPosition[];
        match_count: number; outcome_tier: "jackpot" | "high" | "second-chance" | "loss";
        second_chance_stake_kobo: number | null;
        won: boolean; gross_prize_kobo: number; tax_withheld_kobo: number; net_credit_kobo: number;
        settled_at?: string;
      }>(`/heritage/tickets/${purchase.reference}/reveal`),
      loadCatalogue(),
    ]);

    return {
      reference: reveal.reference,
      settledAt: reveal.settled_at ?? new Date().toISOString(),
      tier: reveal.outcome_tier,
      matchCount: reveal.match_count,
      stakeKobo: reveal.stake_kobo,
      grossKobo: reveal.gross_prize_kobo,
      taxKobo: reveal.tax_withheld_kobo,
      netKobo: reveal.net_credit_kobo,
      board: reveal.positions.map((pos) => {
        const item = catalogue.get(pos.number);
        if (!item) throw new Error(`Catalogue is missing item ${pos.number}`);

        return { position: pos.position, selected: pos.picked, winning: pos.winning, item: toCatalogueItem(item) };
      }),
      secondChance: reveal.outcome_tier === "second-chance" && reveal.second_chance_stake_kobo !== null
        ? {
            status: "pending" as const,
            entryStakeKobo: reveal.second_chance_stake_kobo,
            numbers: [],
            drawName: "Next 5/90 draw",
            drawAt: "",
            drawsMissed: 0,
            resultNotification: "pending" as const,
          }
        : undefined,
    };
  },
};

function toHeritageError(error: unknown): Error {
  if (error instanceof ApiError && error.status === 422 && typeof error.body.code === "string") {
    return new HeritageGatewayError(error.body.code as HeritageGatewayErrorCode);
  }
  return error instanceof Error ? error : new Error("HERITAGE_REQUEST_FAILED");
}
