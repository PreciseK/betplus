import React from "react";
import styles from "./MonarchSilhouetteWing.module.css";
import type { HeritageTradition, HeritageLeader, HeritageBoardPosition } from "@/mocks/heritage";
import { TRIBE_ATTIRE_LORE, MONARCH_ARTWORK_MAP } from "./heritageCommunityData";

interface MonarchSilhouetteWingProps {
  tradition?: HeritageTradition;
  leader: HeritageLeader;
  equippedSlots: Set<string>;
  revealedBoard?: readonly HeritageBoardPosition[];
  customImagePath?: string;
}

export const MonarchSilhouetteWing: React.FC<MonarchSilhouetteWingProps> = ({
  tradition,
  leader,
  equippedSlots,
  customImagePath,
}) => {
  const leaderTitle = leader === "queen" ? tradition?.queenTitle ?? "Queen" : tradition?.kingTitle ?? "King";
  const tribeId = tradition?.id ?? "yoruba";
  const artworkPath = customImagePath || (leader ? MONARCH_ARTWORK_MAP[tribeId]?.[leader] : undefined);

  return (
    <aside className={styles.monarchStandee} aria-label={`${tradition?.name ?? "Monarch"} Royal Silhouette`}>
      <div className={styles.silhouetteContainer}>
        {/* Ambient Pedestal Lighting */}
        <div className={styles.pedestalGlow} aria-hidden="true" />

        {/* Monarch Image / Silhouette Canvas */}
        <div className={styles.silhouetteFrame}>
          {artworkPath ? (
            <img
              src={artworkPath}
              alt={`${tradition?.name ?? ""} ${leaderTitle}`}
              className={styles.customImage}
            />
          ) : (
            <div className={styles.vectorSilhouette} aria-hidden="true">
              <svg viewBox="0 0 200 360" className={styles.monarchSvg}>
                <defs>
                  <linearGradient id="monarchGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stopColor="#ffd88a" stopOpacity="0.85" />
                    <stop offset="50%" stopColor="#d49a24" stopOpacity="0.45" />
                    <stop offset="100%" stopColor="#1e1610" stopOpacity="0.9" />
                  </linearGradient>
                  <filter id="royalGlow" x="-20%" y="-20%" width="140%" height="140%">
                    <feGaussianBlur stdDeviation="4" result="blur" />
                    <feComposite in="SourceGraphic" in2="blur" operator="over" />
                  </filter>
                </defs>

                {/* Crown / Headdress */}
                <path
                  d="M75 55 L100 22 L125 55 L115 65 L85 65 Z"
                  fill="#ffd88a"
                  filter={equippedSlots.has("head") ? "url(#royalGlow)" : undefined}
                  className={equippedSlots.has("head") ? styles.slotGlow : styles.slotDim}
                />

                {/* Head / Face */}
                <ellipse cx="100" cy="80" rx="22" ry="26" fill="url(#monarchGrad)" />

                {/* Neck & Royal Beads */}
                <rect x="92" y="106" width="16" height="18" rx="4" fill="url(#monarchGrad)" />
                <ellipse
                  cx="100"
                  cy="118"
                  rx="18"
                  ry="8"
                  fill="#f1b82d"
                  stroke="#ffd88a"
                  strokeWidth="2"
                  className={equippedSlots.has("neck") ? styles.slotGlow : styles.slotDim}
                />

                {/* Torso & Royal Robe */}
                <path
                  d="M55 125 Q100 115 145 125 L168 265 Q100 278 32 265 Z"
                  fill="url(#monarchGrad)"
                  stroke="#f1b82d"
                  strokeWidth="1.5"
                  className={equippedSlots.has("torso") ? styles.slotGlow : styles.slotDim}
                />

                {/* Scepter / Staff (Hand) */}
                <line
                  x1="162"
                  y1="110"
                  x2="162"
                  y2="300"
                  stroke="#ffd88a"
                  strokeWidth="4"
                  strokeLinecap="round"
                  className={equippedSlots.has("hand") ? styles.slotGlow : styles.slotDim}
                />
                <circle cx="162" cy="105" r="9" fill="#ffd88a" filter="url(#royalGlow)" />

                {/* Feet / Royal Slippers */}
                <ellipse cx="76" cy="290" rx="15" ry="7" fill="#f1b82d" className={equippedSlots.has("feet") ? styles.slotGlow : styles.slotDim} />
                <ellipse cx="124" cy="290" rx="15" ry="7" fill="#f1b82d" className={equippedSlots.has("feet") ? styles.slotGlow : styles.slotDim} />

                {/* Royal Pedestal Base */}
                <ellipse cx="100" cy="325" rx="85" ry="20" fill="#140f0c" stroke="#f1b82d" strokeWidth="2" />
                <ellipse cx="100" cy="325" rx="65" ry="14" fill="#251b14" stroke="#ffd88a" strokeWidth="1" />
              </svg>
            </div>
          )}
        </div>
      </div>
    </aside>
  );
};
