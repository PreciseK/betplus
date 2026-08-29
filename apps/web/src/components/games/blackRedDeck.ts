export type DeckCardColor = "red" | "black";

export interface DeckCard {
  color: DeckCardColor;
  suit: string;
  rank: string;
}

const RED_SUITS = ["♥", "♦"];
const BLACK_SUITS = ["♠", "♣"];
const RANKS = ["A", "2", "3", "4", "5", "6", "7", "8", "9", "10", "J", "Q", "K"];

/**
 * Purely decorative: a 12-card deck (6 red, 6 black) for the "meet your deck" and
 * shuffle animations. Real money never depends on which specific rank/suit a card
 * shows — the engine only ever produces a colour, never a rank — so client-side
 * Math.random() here is fine precisely because it affects nothing financial.
 */
export function generate12CardDeck(): DeckCard[] {
  const redPool: DeckCard[] = [];
  for (const r of RANKS) {
    for (const s of RED_SUITS) redPool.push({ color: "red", suit: s, rank: r });
  }

  const blackPool: DeckCard[] = [];
  for (const r of RANKS) {
    for (const s of BLACK_SUITS) blackPool.push({ color: "black", suit: s, rank: r });
  }

  const shuffleTake = (arr: DeckCard[], n: number) => {
    const a = [...arr];
    for (let i = a.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [a[i], a[j]] = [a[j], a[i]];
    }
    return a.slice(0, n);
  };

  const deck = [...shuffleTake(redPool, 6), ...shuffleTake(blackPool, 6)];
  for (let i = deck.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [deck[i], deck[j]] = [deck[j], deck[i]];
  }

  return deck;
}

/**
 * Assigns a card FACE (rank/suit — decorative) to each position while forcing its
 * COLOUR to match the real server result at that position (from BlackRedEngine, a
 * pure, replayable function of a pre-committed seed). This is the only place the
 * server's real outcome enters the deck the player sees — never Math.random().
 */
export function drawCardsMatchingServerResult(serverResult: Array<"R" | "B">): DeckCard[] {
  const redPool = RANKS.flatMap((r) => RED_SUITS.map((s) => ({ color: "red" as const, suit: s, rank: r })));
  const blackPool = RANKS.flatMap((r) => BLACK_SUITS.map((s) => ({ color: "black" as const, suit: s, rank: r })));
  const shuffledRed = [...redPool].sort(() => Math.random() - 0.5);
  const shuffledBlack = [...blackPool].sort(() => Math.random() - 0.5);
  let redIdx = 0;
  let blackIdx = 0;

  return serverResult.map((colorCode) => (colorCode === "R" ? shuffledRed[redIdx++] : shuffledBlack[blackIdx++]));
}
