"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { walletGateway } from "@betplus/api-client";
import { BlackRedPlayModal } from "@/components/games/BlackRedPlayModal/BlackRedPlayModal";
import { requireAuth, useSignedIn } from "@/lib/player-auth";
import { GAMES, type GameItem } from "../gamesData";
import { GamePosterTile } from "../GamePosterTile/GamePosterTile";
import { GameCatalogueCard } from "../GameCatalogueCard/GameCatalogueCard";
import { GameListItem } from "../GameListItem/GameListItem";
import styles from "./GamesCatalogue.module.css";

export type CatalogueViewFormat = "matrix" | "grid" | "list";

export interface GamesCatalogueProps {
  initialFormat?: CatalogueViewFormat;
  showToolbar?: boolean;
  games?: GameItem[];
  className?: string;
  onQuickPlay?: (gameId: string) => void;
}

export function GamesCatalogue({
  initialFormat = "matrix",
  showToolbar = false,
  games = GAMES,
  className,
  onQuickPlay,
}: GamesCatalogueProps) {
  const router = useRouter();
  const { signedIn, checked } = useSignedIn();
  const [format, setFormat] = useState<CatalogueViewFormat>(initialFormat);
  const [isBlackRedModalOpen, setIsBlackRedModalOpen] = useState<boolean>(false);
  const [playBalanceKobo, setPlayBalanceKobo] = useState<number>(0);

  useEffect(() => {
    if (!checked || !signedIn) return;
    let active = true;
    walletGateway
      .loadWallet()
      .then((wallet) => {
        if (active) setPlayBalanceKobo(wallet.playBalanceKobo);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, [checked, signedIn]);

  const handleQuickPlay = (gameId: string) => {
    if (onQuickPlay) {
      onQuickPlay(gameId);
      return;
    }
    if (gameId === "blackred") {
      requireAuth(router, () => setIsBlackRedModalOpen(true));
    }
  };

  return (
    <div className={`${styles.catalogueContainer} ${className ?? ""}`.trim()}>
      {showToolbar && (
        <div className={styles.toolbar}>
          <span className={styles.countText}>{games.length} {games.length === 1 ? "game" : "games"}</span>

          <div
            className={styles.segmentedControl}
            role="group"
            aria-label="Game view format"
          >
            <button
              type="button"
              className={styles.segmentBtn}
              aria-pressed={format === "matrix"}
              onClick={() => setFormat("matrix")}
              title="Compact grid"
            >
              <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <rect x="1" y="1" width="3.5" height="3.5" rx="1" />
                <rect x="6.25" y="1" width="3.5" height="3.5" rx="1" />
                <rect x="11.5" y="1" width="3.5" height="3.5" rx="1" />
                <rect x="1" y="6.25" width="3.5" height="3.5" rx="1" />
                <rect x="6.25" y="6.25" width="3.5" height="3.5" rx="1" />
                <rect x="11.5" y="6.25" width="3.5" height="3.5" rx="1" />
                <rect x="1" y="11.5" width="3.5" height="3.5" rx="1" />
                <rect x="6.25" y="11.5" width="3.5" height="3.5" rx="1" />
                <rect x="11.5" y="11.5" width="3.5" height="3.5" rx="1" />
              </svg>
              <span>Tiles</span>
            </button>

            <button
              type="button"
              className={styles.segmentBtn}
              aria-pressed={format === "grid"}
              onClick={() => setFormat("grid")}
              title="Detailed Cards Grid"
            >
              <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <rect x="1" y="1" width="6" height="6" rx="1.5" />
                <rect x="9" y="1" width="6" height="6" rx="1.5" />
                <rect x="1" y="9" width="6" height="6" rx="1.5" />
                <rect x="9" y="9" width="6" height="6" rx="1.5" />
              </svg>
              <span>Cards</span>
            </button>

            <button
              type="button"
              className={styles.segmentBtn}
              aria-pressed={format === "list"}
              onClick={() => setFormat("list")}
              title="List View"
            >
              <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <rect x="1" y="2" width="14" height="3" rx="1" />
                <rect x="1" y="6.5" width="14" height="3" rx="1" />
                <rect x="1" y="11" width="14" height="3" rx="1" />
              </svg>
              <span>List</span>
            </button>
          </div>
        </div>
      )}

      {/* Render selected format */}
      {format === "matrix" && (
        <div
          className={styles.matrixGrid}
          role="region"
          aria-label="Game tiles"
        >
          {games.map((game) => (
            <GamePosterTile
              key={game.id}
              game={game}
              onQuickPlay={handleQuickPlay}
            />
          ))}
        </div>
      )}

      {format === "grid" && (
        <div
          className={styles.detailedGrid}
          role="region"
          aria-label="Games Grid"
        >
          {games.map((game) => (
            <GameCatalogueCard
              key={game.id}
              game={game}
              onQuickPlay={handleQuickPlay}
            />
          ))}
        </div>
      )}

      {format === "list" && (
        <div
          className={styles.listContainer}
          role="region"
          aria-label="Games List"
        >
          {games.map((game) => (
            <GameListItem
              key={game.id}
              game={game}
              onQuickPlay={handleQuickPlay}
            />
          ))}
        </div>
      )}

      {/* Quick Play Modal */}
      <BlackRedPlayModal
        isOpen={isBlackRedModalOpen}
        onClose={() => setIsBlackRedModalOpen(false)}
        playBalanceKobo={playBalanceKobo}
        onBalanceChange={(newBal) => setPlayBalanceKobo(newBal)}
      />
    </div>
  );
}
