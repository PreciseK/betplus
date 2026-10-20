import type { CSSProperties } from "react";
import Image from "next/image";
import type { HeritageCatalogueItem } from "@/mocks/heritage";
import styles from "./RegaliaArtwork.module.css";

const FEATURED_ART: Record<number, number> = {
  7: 0,
  14: 1,
  23: 2,
  31: 3,
  42: 4,
  55: 5,
  63: 6,
  74: 7,
  88: 8,
};
const SLOT_ART: Record<HeritageCatalogueItem["slot"], number> = {
  head: 0,
  neck: 4,
  torso: 6,
  waist: 2,
  wrist: 7,
  hand: 5,
  feet: 8,
};

type RegaliaArtItem = Pick<
  HeritageCatalogueItem,
  "number" | "slot" | "canonicalName"
> & { imageUrl?: string };

export function RegaliaArtwork({
  item,
  className = "",
}: {
  item: RegaliaArtItem;
  className?: string;
}) {
  if (item.imageUrl)
    return (
      <span className={`${styles.artwork} ${className}`}>
        <Image
          src={item.imageUrl}
          alt={item.canonicalName}
          width={400}
          height={400}
          unoptimized
        />
      </span>
    );
  const cell = FEATURED_ART[item.number] ?? SLOT_ART[item.slot];
  return (
    <span
      className={`${styles.artwork} ${styles.concept} ${className}`}
      role="img"
      aria-label={`${item.canonicalName} concept illustration`}
      style={
        {
          "--art-column": cell % 3,
          "--art-row": Math.floor(cell / 3),
        } as CSSProperties
      }
    />
  );
}
