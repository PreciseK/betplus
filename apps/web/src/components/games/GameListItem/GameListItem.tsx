"use client";

import Link from "next/link";
import { HeritageCrownLogo } from "@/components/ui/HeritageCrownLogo/HeritageCrownLogo";
import type { GameItem } from "../gamesData";
import styles from "./GameListItem.module.css";

interface GameListItemProps {
  game: GameItem;
  onQuickPlay?: (gameId: string) => void;
}

export function GameListItem({ game, onQuickPlay }: GameListItemProps) {
  return (
    <article
      className={styles.listItem}
      style={
        {
          "--accent": game.accentColor,
          "--theme-gradient": game.themeGradient,
        } as React.CSSProperties
      }
    >
      {/* Thumbnail */}
      <div className={styles.thumbWrapper}>
        {game.isCrown ? (
          <HeritageCrownLogo size={36} />
        ) : game.logoSrc ? (
          <img
            src={game.logoSrc}
            alt={`${game.title} logo`}
            className={styles.thumbLogo}
            loading="lazy"
          />
        ) : (
          <span className={styles.thumbEmoji}>{game.emblemText ?? "🎮"}</span>
        )}
      </div>

      {/* Main Details */}
      <div className={styles.mainInfo}>
        <div className={styles.titleRow}>
          <h3 className={styles.gameTitle}>{game.title}</h3>
          {game.live ? (
            <span className={styles.liveBadge}>
              <span className={styles.pulseDot} />
              LIVE
            </span>
          ) : (
            <span className={styles.badgePill}>{game.badge}</span>
          )}
        </div>

        {/* Desktop Description */}
        <p className={styles.gameDescription}>{game.description}</p>

        {/* Mobile Subtitle: Multiplier + Stake */}
        <p className={styles.mobileSubtitle}>
          {game.multiplier} · {game.stakeRange}
        </p>
      </div>

      {/* Desktop Stat Chips */}
      <div className={styles.statsGroup}>
        <div className={styles.statChip}>
          <span className={styles.statLabel}>Multiplier</span>
          <span className={styles.statValue}>{game.multiplier}</span>
        </div>
        <div className={styles.statChip}>
          <span className={styles.statLabel}>Stakes</span>
          <span className={styles.statValue}>{game.stakeRange}</span>
        </div>
      </div>

      {/* Action CTA */}
      <div className={styles.actionGroup}>
        {game.id === "blackred" && onQuickPlay ? (
          <>
            <Link href={game.route} className={styles.secondaryBtn}>
              Dashboard
            </Link>
            <button
              type="button"
              onClick={() => onQuickPlay("blackred")}
              className={styles.playBtn}
            >
              ⚡ Quick Play
            </button>
          </>
        ) : (
          <Link href={game.route} className={styles.playBtn}>
            Play {game.title}
          </Link>
        )}
      </div>
    </article>
  );
}
