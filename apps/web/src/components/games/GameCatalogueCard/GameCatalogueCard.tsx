"use client";

import { HeritageCrownLogo } from "@/components/ui/HeritageCrownLogo/HeritageCrownLogo";
import { Button } from "@/components/ui/Button/Button";
import type { GameItem } from "../gamesData";
import { DoorsLogo } from "../DoorsLogo";
import styles from "./GameCatalogueCard.module.css";

interface GameCatalogueCardProps {
  game: GameItem;
  onQuickPlay?: (gameId: string) => void;
}

export function GameCatalogueCard({ game, onQuickPlay }: GameCatalogueCardProps) {
  return (
    <article
      className={styles.gridCard}
      style={
        {
          "--card-accent": game.accentColor,
          "--theme-gradient": game.themeGradient,
        } as React.CSSProperties
      }
    >
      <div className={styles.gridMedia}>
        {game.live && (
          <span className={styles.liveBadge}>
            <span className={styles.pulseDot} />
            Live
          </span>
        )}

        {game.id === "doors" ? <DoorsLogo /> : game.isCrown ? (
          <HeritageCrownLogo size={82} />
        ) : game.logoSrc ? (
          <img
            src={game.logoSrc}
            alt={`${game.title} logo`}
            className={styles.gridLogo}
          />
        ) : (
          <span className={styles.emblemEmoji}>{game.emblemText ?? "🦅"}</span>
        )}
      </div>

      <div className={styles.gridBody}>
        <p className={styles.gameCategory}>{game.category}</p>
        <h3 className={styles.gameTitle}>{game.title}</h3>
        <p className={styles.gameDescription}>{game.description}</p>

        <div className={styles.statsRow}>
          <div className={styles.statItem}>
            <span>Multiplier</span>
            <strong>{game.multiplier}</strong>
          </div>
          <div className={styles.statItem}>
            <span>Stakes</span>
            <strong>{game.stakeRange}</strong>
          </div>
        </div>
      </div>

      <div className={styles.gridFooter}>
        {game.id === "blackred" && onQuickPlay ? (
          <div className={styles.btnGroupRow}>
            <Button href={game.route} variant="secondary" className={styles.secondaryActionBtn}>
              Open game
            </Button>
            <Button
              type="button"
              aria-label={`Quick play ${game.title}`}
              onClick={() => onQuickPlay("blackred")}
              className={styles.playButton}
            >
              Quick play
            </Button>
          </div>
        ) : (
          <Button href={game.route} className={styles.playButton}>
            Play {game.title}
          </Button>
        )}
      </div>
    </article>
  );
}
