import { describe, expect, it } from "vitest";
import {
  ALL_REGALIA_PIECES,
  REGALIA_SETS,
  pickRegaliaBoardAssignment,
  type RegaliaSlot,
} from "./heritageRegaliaCatalogue";

const SLOTS: RegaliaSlot[] = ["head", "neck", "torso", "hand", "feet"];
const TRIBE_IDS = [
  "yoruba",
  "igbo",
  "hausa-fulani",
  "edo",
  "efik-ibibio",
  "ijaw",
  "middle-belt",
];

describe("heritage regalia catalogue", () => {
  it("has exactly 70 pieces: 7 tribes x king/queen x 5 slots", () => {
    expect(ALL_REGALIA_PIECES).toHaveLength(70);
    expect(Object.keys(REGALIA_SETS)).toHaveLength(14);
  });

  it.each(TRIBE_IDS)("has one piece per slot for %s king and queen", (tribeId) => {
    for (const leader of ["king", "queen"] as const) {
      const set = REGALIA_SETS[`${tribeId}_${leader}`];
      expect(set, `${tribeId}_${leader}`).toHaveLength(5);
      expect(new Set(set.map((piece) => piece.slot))).toEqual(new Set(SLOTS));
      for (const piece of set) {
        expect(piece.imageUrl).toMatch(/^\/games\/heritage\/regalia\//);
      }
    }
  });

  it("splits into the real 5 (one per slot) and 4 distinct decoys from other sets", () => {
    const rng = (() => {
      let seed = 1;
      return () => {
        seed = (seed * 48271) % 2147483647;
        return seed / 2147483647;
      };
    })();
    const { authentic, decoys } = pickRegaliaBoardAssignment("yoruba", "king", rng);

    expect(authentic).toHaveLength(5);
    expect(authentic.every((piece) => piece.setKey === "yoruba_king")).toBe(true);
    expect(new Set(authentic.map((piece) => piece.slot))).toEqual(new Set(SLOTS));
    expect(decoys).toHaveLength(4);
    expect(decoys.every((piece) => piece.setKey !== "yoruba_king")).toBe(true);
    const all = [...authentic, ...decoys];
    expect(new Set(all.map((piece) => `${piece.setKey}:${piece.id}`)).size).toBe(9);
  });

  it("falls back to an all-decoy assignment when no tradition is selected yet", () => {
    const { authentic, decoys } = pickRegaliaBoardAssignment("", "king");
    expect(authentic).toHaveLength(0);
    expect(decoys).toHaveLength(9);
  });
});
