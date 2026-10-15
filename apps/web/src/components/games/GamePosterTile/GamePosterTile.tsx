"use client";

import Link from "next/link";
import { HeritageCrownLogo } from "@/components/ui/HeritageCrownLogo/HeritageCrownLogo";
import type { GameItem } from "../gamesData";
import styles from "./GamePosterTile.module.css";

interface GamePosterTileProps {
  game: GameItem;
  onQuickPlay?: (gameId: string) => void;
}

export function GamePosterTile({ game }: GamePosterTileProps) {

  return (
    <Link
      href={game.route}
      className={styles.posterTile}

      aria-label={`Play ${game.title} - ${game.multiplier}`}
    >

      {/* Top Status Badges */}
      <div className={styles.topBar}>
        {game.live ? (
          <span className={styles.liveBadge}>
            <span className={styles.pulseDot} />
            Live
          </span>
        ) : (
          <span className={styles.topSpacer} />
        )}

        <span className={styles.multiplierPill}>
          {game.compactMultiplier ?? game.multiplier}
        </span>
      </div>

      {/* Center Artwork Emblem */}
      <div className={styles.emblemWrapper}>
        {game.isCrown ? (
          <div className={styles.crownContainer}>
            <HeritageCrownLogo size={46} />
          </div>
        ) : game.logoSrc ? (
          <img
            src={game.logoSrc}
            alt={`${game.title} logo`}
            className={styles.logoImage}
            loading="lazy"
          />
        ) : (
          <span className={styles.emblemEmoji}>{game.emblemText ?? "🎮"}</span>
        )}
      </div>

      {/* Bottom Title & Tag */}
      <div className={styles.tileFooter}>
        <h4 className={styles.gameName}>{game.title}</h4>
        <p className={styles.categoryTag}>{game.badge}</p>
      </div>

      {/* Tactile Play Icon (reveals on hover) */}
      <span className={styles.playActionOverlay} aria-hidden="true">
        ▶
      </span>
    </Link>
  );
}
