// Real per-tribe regalia artwork, transcribed from
// apps/web/public/games/heritage/regalia/catalogue.json (70/70 pieces: 7 tribes
// x king/queen x 5 slots). Kept here as typed data (not a public/ JSON import)
// so it can be shuffled into mock board positions without a fetch.
//
// Per REQ-HG (see packages/api-client/src/heritageGateway.ts), which regalia
// image appears on a board position never changes the predetermined odds —
// this module only decides what is *shown*, never what *wins*.

export type RegaliaLeader = "king" | "queen";
export type RegaliaSlot = "head" | "neck" | "torso" | "hand" | "feet";

export interface RegaliaPiece {
  setKey: string;
  tribeId: string;
  leader: RegaliaLeader;
  id: string;
  slot: RegaliaSlot;
  localName: string | null;
  canonicalName: string;
  imageUrl: string;
  description?: string;
}

interface RegaliaPieceSeed {
  set: string;
  id: string;
  localName: string | null;
  english: string;
  slot: RegaliaSlot;
  src: string;
  description?: string;
}

const REGALIA_PIECE_SEED: RegaliaPieceSeed[] = [
  { set: "yoruba_king", id: "ade", localName: "Adé", english: "Beaded crown", slot: "head", src: "/games/heritage/regalia/yoruba_king/ade_v1.png" },
  { set: "yoruba_king", id: "ileke", localName: "Ìlẹ̀kẹ̀", english: "Coral neck beads", slot: "neck", src: "/games/heritage/regalia/yoruba_king/ileke_v1.png" },
  { set: "yoruba_king", id: "agbada", localName: "Agbádá", english: "Embroidered robe", slot: "torso", src: "/games/heritage/regalia/yoruba_king/agbada_v1.png" },
  { set: "yoruba_king", id: "irukere", localName: "Ìrùkẹ̀rẹ̀", english: "Flywhisk", slot: "hand", src: "/games/heritage/regalia/yoruba_king/irukere_v1.png" },
  { set: "yoruba_king", id: "bata", localName: "Bàtà", english: "Embroidered shoes", slot: "feet", src: "/games/heritage/regalia/yoruba_king/bata_v1.png" },

  { set: "yoruba_queen", id: "gele", localName: "Gèlè", english: "Sculpted headwrap", slot: "head", src: "/games/heritage/regalia/yoruba_queen/gele_v1.png" },
  { set: "yoruba_queen", id: "ileke", localName: "Ìlẹ̀kẹ̀", english: "Coral neck beads", slot: "neck", src: "/games/heritage/regalia/yoruba_queen/ileke_v1.png" },
  { set: "yoruba_queen", id: "iro-buba", localName: "Ìró àti Bùbá", english: "Blouse and wrapper", slot: "torso", src: "/games/heritage/regalia/yoruba_queen/iro-buba_v1.png" },
  { set: "yoruba_queen", id: "abebe", localName: "Abẹ́bẹ́", english: "Hand fan", slot: "hand", src: "/games/heritage/regalia/yoruba_queen/abebe_v1.png" },
  { set: "yoruba_queen", id: "bata", localName: "Bàtà", english: "Beaded shoes", slot: "feet", src: "/games/heritage/regalia/yoruba_queen/bata_v1.png" },

  { set: "igbo_king", id: "okpu-eze", localName: "Okpu Eze", english: "Royal cap", slot: "head", src: "/games/heritage/regalia/igbo_king/okpu-eze_v1.png" },
  { set: "igbo_king", id: "aka", localName: "Ákà", english: "Coral neck beads", slot: "neck", src: "/games/heritage/regalia/igbo_king/aka_v1.png" },
  { set: "igbo_king", id: "isiagu", localName: "Isiagụ", english: "Lion-patterned tunic", slot: "torso", src: "/games/heritage/regalia/igbo_king/isiagu_v1.png" },
  { set: "igbo_king", id: "mkpo", localName: "Mkpọ", english: "Carved walking staff", slot: "hand", src: "/games/heritage/regalia/igbo_king/mkpo_v1.png" },
  { set: "igbo_king", id: "akpukpo-ukwu", localName: "Akpụkpọ ụkwụ", english: "Embroidered shoes", slot: "feet", src: "/games/heritage/regalia/igbo_king/akpukpo-ukwu_v1.png" },

  { set: "igbo_queen", id: "okpu-aka", localName: "Okpu ákà", english: "Beaded headpiece", slot: "head", src: "/games/heritage/regalia/igbo_queen/okpu-aka_v1.png" },
  { set: "igbo_queen", id: "aka", localName: "Ákà", english: "Coral neck beads", slot: "neck", src: "/games/heritage/regalia/igbo_queen/aka_v1.png" },
  { set: "igbo_queen", id: "akwa", localName: "Akwa", english: "Blouse and woven wrapper", slot: "torso", src: "/games/heritage/regalia/igbo_queen/akwa_v1.png" },
  { set: "igbo_queen", id: "akupe", localName: "Àkùpè", english: "Feather hand fan", slot: "hand", src: "/games/heritage/regalia/igbo_queen/akupe_v1.png" },
  { set: "igbo_queen", id: "akpukpo-ukwu", localName: "Akpụkpọ ụkwụ", english: "Beaded shoes", slot: "feet", src: "/games/heritage/regalia/igbo_queen/akpukpo-ukwu_v1.png" },

  { set: "hausa-fulani_king", id: "rawani", localName: "Rawani", english: "Ceremonial turban", slot: "head", src: "/games/heritage/regalia/hausa-fulani_king/rawani_v1.png" },
  { set: "hausa-fulani_king", id: "abin-wuya", localName: "Abin wuya", english: "Embroidered neck panel", slot: "neck", src: "/games/heritage/regalia/hausa-fulani_king/abin-wuya_v1.png" },
  { set: "hausa-fulani_king", id: "babbar-riga", localName: "Babbar riga da alkyabba", english: "Robe and ceremonial cloak", slot: "torso", src: "/games/heritage/regalia/hausa-fulani_king/babbar-riga_v1.png" },
  { set: "hausa-fulani_king", id: "sanda", localName: "Sanda", english: "Ceremonial staff", slot: "hand", src: "/games/heritage/regalia/hausa-fulani_king/sanda_v1.png" },
  { set: "hausa-fulani_king", id: "takalmi", localName: "Takalmi", english: "Embroidered slippers", slot: "feet", src: "/games/heritage/regalia/hausa-fulani_king/takalmi_v1.png" },

  { set: "hausa-fulani_queen", id: "dankwali", localName: "Ɗankwali", english: "Headwrap and veil", slot: "head", src: "/games/heritage/regalia/hausa-fulani_queen/dankwali_v1.png" },
  { set: "hausa-fulani_queen", id: "abin-wuya", localName: "Abin wuya", english: "Gold necklace", slot: "neck", src: "/games/heritage/regalia/hausa-fulani_queen/abin-wuya_v1.png" },
  { set: "hausa-fulani_queen", id: "riga", localName: "Riga", english: "Embroidered ceremonial gown", slot: "torso", src: "/games/heritage/regalia/hausa-fulani_queen/riga_v1.png" },
  { set: "hausa-fulani_queen", id: "mafici", localName: "Mafici", english: "Hand fan", slot: "hand", src: "/games/heritage/regalia/hausa-fulani_queen/mafici_v1.png" },
  { set: "hausa-fulani_queen", id: "takalmi", localName: "Takalmi", english: "Embroidered shoes", slot: "feet", src: "/games/heritage/regalia/hausa-fulani_queen/takalmi_v1.png" },

  { set: "edo_king", id: "erhu-ivie", localName: "Erhu Ivie", english: "Coral crown", slot: "head", src: "/games/heritage/regalia/edo_king/erhu-ivie_v1.png", description: "Also called Ede; the king's coral crown." },
  { set: "edo_king", id: "odigba", localName: "Odigba", english: "Layered coral collar", slot: "neck", src: "/games/heritage/regalia/edo_king/odigba_v1.png", description: "The king's coral neck collar, distinct from the Ivie-Obo coral wrist beads worn in the same portrait." },
  { set: "edo_king", id: "ewu-ivie", localName: "Ewu Ivie", english: "Coral-mesh ceremonial attire", slot: "torso", src: "/games/heritage/regalia/edo_king/ewu-ivie_v1.png", description: "The king's coral-beaded tunic, worn over an Ukpon base robe." },
  { set: "edo_king", id: "staff", localName: "Ukhurhe", english: "Bronze-toned ceremonial staff", slot: "hand", src: "/games/heritage/regalia/edo_king/staff_v1.png", description: "The king's ceremonial staff." },
  { set: "edo_king", id: "slippers", localName: "Ibata Ivie", english: "Coral-beaded slippers", slot: "feet", src: "/games/heritage/regalia/edo_king/slippers_v1.png", description: "The king's coral-beaded slippers." },

  { set: "edo_queen", id: "ukpe-okhue", localName: "Ukpe-okhue", english: "Coral-beaded headdress", slot: "head", src: "/games/heritage/regalia/edo_queen/ukpe-okhue_v1.png", description: "Also called Okuku; the Iyoba's coral headdress." },
  { set: "edo_queen", id: "odigba", localName: "Ivie", english: "Coral collar", slot: "neck", src: "/games/heritage/regalia/edo_queen/odigba_v1.png", description: "The queen's coral beads." },
  { set: "edo_queen", id: "ceremonial-attire", localName: "Ukpon-Ododo", english: "Coral and ivory ceremonial attire", slot: "torso", src: "/games/heritage/regalia/edo_queen/ceremonial-attire_v1.png", description: "Also called simply Ukpon; the queen's coral-patterned ceremonial robe." },
  { set: "edo_queen", id: "fan", localName: "Ezuzu", english: "Feather hand fan", slot: "hand", src: "/games/heritage/regalia/edo_queen/fan_v1.png", description: "The queen's ceremonial fan." },
  { set: "edo_queen", id: "slippers", localName: "Ibata Ivie", english: "Beaded shoes", slot: "feet", src: "/games/heritage/regalia/edo_queen/slippers_v1.png", description: "The queen's coral-beaded slippers." },

  { set: "efik-ibibio_king", id: "cap", localName: "Itam", english: "Embroidered feathered cap", slot: "head", src: "/games/heritage/regalia/efik-ibibio_king/cap_v1.png", description: "Also called the Ntinya-crowned cap, worn with an Ntang Ntrukpm eagle feather." },
  { set: "efik-ibibio_king", id: "neck-beads", localName: "Nkwa", english: "Coral neck beads", slot: "neck", src: "/games/heritage/regalia/efik-ibibio_king/neck-beads_v1.png", description: "Also called Nkwa Eto; the king's coral neck beads." },
  { set: "efik-ibibio_king", id: "ceremonial-attire", localName: "Eku and Usobo", english: "Tunic and ceremonial wrapper", slot: "torso", src: "/games/heritage/regalia/efik-ibibio_king/ceremonial-attire_v1.png", description: "The velvet Eku top worn with the heavy George wrapper, also called Okpomkpom." },
  { set: "efik-ibibio_king", id: "staff", localName: "Esang Ubong", english: "Gold-topped staff", slot: "hand", src: "/games/heritage/regalia/efik-ibibio_king/staff_v1.png", description: "The king's royal staff of office." },
  { set: "efik-ibibio_king", id: "shoes", localName: "Ikpa Ukot", english: "Embroidered court shoes", slot: "feet", src: "/games/heritage/regalia/efik-ibibio_king/shoes_v1.png", description: "The king's ceremonial court shoes." },

  { set: "efik-ibibio_queen", id: "headpiece", localName: "Eba", english: "Coral and gold hair ornaments", slot: "head", src: "/games/heritage/regalia/efik-ibibio_queen/headpiece_v1.png", description: "Also called Ibuot Mbopo; the queen's hairstyle, adorned with Edet Idat (or Ukpe) gold/brass hair combs." },
  { set: "efik-ibibio_queen", id: "neck-beads", localName: "Nkwa", english: "Coral neck beads", slot: "neck", src: "/games/heritage/regalia/efik-ibibio_queen/neck-beads_v1.png", description: "Also called Nkwa Eto; the queen's coral neck beads." },
  { set: "efik-ibibio_queen", id: "onyonyo", localName: "Onyonyo", english: "Ceremonial gown", slot: "torso", src: "/games/heritage/regalia/efik-ibibio_queen/onyonyo_v1.png", description: "The queen's flowing ceremonial gown." },
  { set: "efik-ibibio_queen", id: "fan", localName: "Ufat", english: "Feather hand fan", slot: "hand", src: "/games/heritage/regalia/efik-ibibio_queen/fan_v1.png", description: "The queen's ceremonial hand fan." },
  { set: "efik-ibibio_queen", id: "shoes", localName: "Ikpa Ukot", english: "Decorated court shoes", slot: "feet", src: "/games/heritage/regalia/efik-ibibio_queen/shoes_v1.png", description: "The queen's ceremonial court shoes." },

  { set: "ijaw_king", id: "hat", localName: "Ogbogbo", english: "Coral-trimmed royal hat", slot: "head", src: "/games/heritage/regalia/ijaw_king/hat_v1.png", description: "Also called Bolou (bowler hat), worn with an Agbalakoko eagle feather." },
  { set: "ijaw_king", id: "neck-beads", localName: "Ila", english: "Coral neck beads", slot: "neck", src: "/games/heritage/regalia/ijaw_king/neck-beads_v1.png", description: "Also called Pina-ila; the king's coral neck beads." },
  { set: "ijaw_king", id: "etibo-george", localName: "Etibo and Injiri", english: "Embroidered shirt and wrapper", slot: "torso", src: "/games/heritage/regalia/ijaw_king/etibo-george_v1.png", description: "Also called Don (jumper) and Ikagi (wrapper); the king's royal jumper and heavy George wrapper." },
  { set: "ijaw_king", id: "staff", localName: "Okubo", english: "Carved walking staff", slot: "hand", src: "/games/heritage/regalia/ijaw_king/staff_v1.png", description: "Also called Akparakpa; the king's walking staff of office. A separate Azuzu horsehair fly-whisk is also part of Ijaw royal regalia but isn't depicted as its own board piece." },
  { set: "ijaw_king", id: "shoes", localName: "Angala-gbasa", english: "Embroidered palace shoes", slot: "feet", src: "/games/heritage/regalia/ijaw_king/shoes_v1.png", description: "Also called Bue-ere; the king's ceremonial shoes." },

  { set: "ijaw_queen", id: "headtie", localName: "Egenekpe", english: "Sculpted headtie", slot: "head", src: "/games/heritage/regalia/ijaw_queen/headtie_v1.png", description: "Also called Tukpa or Tibibedegi; the queen's George headtie." },
  { set: "ijaw_queen", id: "neck-beads", localName: "Ila", english: "Coral neck beads", slot: "neck", src: "/games/heritage/regalia/ijaw_queen/neck-beads_v1.png", description: "Also called Pina-ila; the queen's coral neck beads." },
  { set: "ijaw_queen", id: "george-attire", localName: "Ewu and Injiri", english: "Blouse and ceremonial wrapper", slot: "torso", src: "/games/heritage/regalia/ijaw_queen/george-attire_v1.png", description: "Also called Keme-sibi (blouse) and Ikagi (wrapper); the queen's embroidered blouse and double-tied George wrapper." },
  { set: "ijaw_queen", id: "fan", localName: "Azuzu", english: "Beaded hand fan", slot: "hand", src: "/games/heritage/regalia/ijaw_queen/fan_v1.png", description: "Also called Efefe; the queen's beaded hand fan." },
  { set: "ijaw_queen", id: "shoes", localName: "Angala-gbasa", english: "Beaded shoes", slot: "feet", src: "/games/heritage/regalia/ijaw_queen/shoes_v1.png", description: "Also called Bue-ere; the queen's ceremonial shoes." },

  { set: "middle-belt_king", id: "anger-cap", localName: "Agbande-Tsa", english: "Striped woven cap", slot: "head", src: "/games/heritage/regalia/middle-belt_king/anger-cap_v1.png", description: "Also called Adzenga; the king's A'nger-woven cap." },
  { set: "middle-belt_king", id: "neck-beads", localName: "Akaa", english: "Coral and brass neck ornament", slot: "neck", src: "/games/heritage/regalia/middle-belt_king/neck-beads_v1.png", description: "Also called Mbakaa; the king's royal coral/traditional beads." },
  { set: "middle-belt_king", id: "anger-attire", localName: "A'nger (over Ivavtyo)", english: "Woven mantle and tunic", slot: "torso", src: "/games/heritage/regalia/middle-belt_king/anger-attire_v1.png", description: "The iconic black-and-white striped Tiv wrapper, worn over an Ivavtyo base tunic." },
  { set: "middle-belt_king", id: "staff", localName: "Gbough", english: "Carved wooden staff", slot: "hand", src: "/games/heritage/regalia/middle-belt_king/staff_v1.png", description: "The king's carved royal staff of office." },
  { set: "middle-belt_king", id: "slippers", localName: "Akov a Anger", english: "Striped leather slippers", slot: "feet", src: "/games/heritage/regalia/middle-belt_king/slippers_v1.png", description: "The king's A'nger-upholstered royal shoes." },

  { set: "middle-belt_queen", id: "anger-headwrap", localName: "Ichoho-Tsa", english: "Striped woven headwrap", slot: "head", src: "/games/heritage/regalia/middle-belt_queen/anger-headwrap_v1.png", description: "Also called the A'nger headtie." },
  { set: "middle-belt_queen", id: "neck-beads", localName: "Akaa", english: "Coral neck beads", slot: "neck", src: "/games/heritage/regalia/middle-belt_queen/neck-beads_v1.png", description: "Also called Mbakaa; the queen's royal coral/traditional beads." },
  { set: "middle-belt_queen", id: "anger-attire", localName: "A'nger (with Riga)", english: "Blouse and woven wrapper", slot: "torso", src: "/games/heritage/regalia/middle-belt_queen/anger-attire_v1.png", description: "The black-and-white striped Tiv wrapper and shoulder drape, paired with a Riga (or Ivavtyo) lace blouse." },
  { set: "middle-belt_queen", id: "fan", localName: "Pepe", english: "Carved wooden hand fan", slot: "hand", src: "/games/heritage/regalia/middle-belt_queen/fan_v1.png", description: "The queen's carved wooden hand fan." },
  { set: "middle-belt_queen", id: "slippers", localName: "Akov a Anger", english: "Beaded leather shoes", slot: "feet", src: "/games/heritage/regalia/middle-belt_queen/slippers_v1.png", description: "The queen's A'nger-upholstered slippers." },
];

function leaderAndTribeFromSetKey(setKey: string): { tribeId: string; leader: RegaliaLeader } {
  const leader: RegaliaLeader = setKey.endsWith("_queen") ? "queen" : "king";
  const tribeId = setKey.slice(0, setKey.length - leader.length - 1);
  return { tribeId, leader };
}

export const REGALIA_SETS: Record<string, RegaliaPiece[]> = {};
export const ALL_REGALIA_PIECES: RegaliaPiece[] = REGALIA_PIECE_SEED.map((seed) => {
  const { tribeId, leader } = leaderAndTribeFromSetKey(seed.set);
  const piece: RegaliaPiece = {
    setKey: seed.set,
    tribeId,
    leader,
    id: seed.id,
    slot: seed.slot,
    localName: seed.localName,
    canonicalName: seed.english,
    imageUrl: seed.src,
    description: seed.description,
  };
  (REGALIA_SETS[seed.set] ??= []).push(piece);
  return piece;
});

function shuffle<T>(items: readonly T[], rng: () => number): T[] {
  const copy = [...items];
  for (let i = copy.length - 1; i > 0; i -= 1) {
    const j = Math.floor(rng() * (i + 1));
    [copy[i], copy[j]] = [copy[j], copy[i]];
  }
  return copy;
}

/**
 * Builds the pieces shown on a Heritage board for a given tradition + leader,
 * split into the real 5-piece set (head/neck/torso/hand/feet) and 4 random
 * decoy pieces drawn from every other tribe/leader set. Kept as two separate
 * lists (not pre-combined into 9 positions) so the caller can place the
 * authentic set exactly on the 5 "winning" positions — guaranteeing a jackpot
 * reveal always shows a complete, distinct 5-slot outfit rather than letting
 * authentic and decoy pieces land on winning/non-winning positions by chance,
 * which could put 2+ pieces of the same slot on the winning set and none of
 * another. Which positions are "winning" is decided entirely by `makeBoard`
 * in mocks/heritage.ts from the stake tier — this only controls what is shown.
 */
export function pickRegaliaBoardAssignment(
  tribeId: string,
  leader: RegaliaLeader,
  rng: () => number = Math.random,
): { authentic: RegaliaPiece[]; decoys: RegaliaPiece[] } {
  const setKey = `${tribeId}_${leader}`;
  const authentic = REGALIA_SETS[setKey] ?? [];
  const decoyPool = ALL_REGALIA_PIECES.filter((piece) => piece.setKey !== setKey);
  const decoyCount = Math.max(0, 9 - authentic.length);
  // Sets share piece names, so skip any name already on the board: no piece appears twice.
  const usedNames = new Set(authentic.map((piece) => piece.canonicalName));
  const decoys = shuffle(decoyPool, rng).filter((piece) => !usedNames.has(piece.canonicalName) && usedNames.add(piece.canonicalName));
  return {
    authentic: shuffle(authentic, rng),
    decoys: decoys.slice(0, decoyCount),
  };
}
