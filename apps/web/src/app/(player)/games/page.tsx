"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { walletGateway } from "@betplus/api-client";
import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { Button } from "@/components/ui/Button/Button";
import { BlackRedPlayModal } from "@/components/games/BlackRedPlayModal/BlackRedPlayModal";
import { HeritageCrownLogo } from "@/components/ui/HeritageCrownLogo/HeritageCrownLogo";
import { requireAuth, useSignedIn } from "@/lib/player-auth";
import styles from "./page.module.css";

type ViewFormat = "grid" | "list";

interface GameItem {
  id: string;
  title: string;
  category: string;
  badge: string;
  description: string;
  multiplier: string;
  stakeRange: string;
  route: string;
  logoSrc?: string;
  accentColor: string;
  emblemText?: string;
  isCrown?: boolean;
}

const GAMES: GameItem[] = [
  {
    id: "blackred",
    title: "BlackRed",
    category: "Instant Fixed-Odds Prediction",
    badge: "50/50 Cards",
    description: "Predict sequence of 1 to 5 Black or Red cards with live 3D deck inspection, riffle shuffle, and instant draw.",
    multiplier: "2× – 100×",
    stakeRange: "₦100 – ₦5,000",
    route: "/games/blackred",
    logoSrc: "/assets/blackred-logo.png",
    accentColor: "#FF0000",
  },
  {
    id: "heritage",
    title: "Heritage",
    category: "Culture-Themed Instant-Win",
    badge: "Regalia Discovery",
    description: "Choose 5 of 9 positions to reveal regalia from across Nigeria with complete settlement board replay.",
    multiplier: "Up to 2,000×",
    stakeRange: "₦100 – ₦5,000",
    route: "/games/heritage",
    isCrown: true,
    accentColor: "#d97706",
  },
];

export default function GamesPage() {
  const router = useRouter();
  const { signedIn, checked } = useSignedIn();
  const [format, setFormat] = useState<ViewFormat>("grid");
  const [isBlackRedModalOpen, setIsBlackRedModalOpen] = useState<boolean>(false);
  const [playBalanceKobo, setPlayBalanceKobo] = useState<number>(0);

  useEffect(() => {
    if (!checked || !signedIn) return;
    let active = true;
    walletGateway.loadWallet().then((wallet) => {
      if (active) setPlayBalanceKobo(wallet.playBalanceKobo);
    }).catch(() => {});
    return () => { active = false; };
  }, [checked, signedIn]);

  const handleGameAction = (gameId: string) => {
    if (gameId === "blackred") {
      requireAuth(router, () => setIsBlackRedModalOpen(true));
    }
  };

  return (
    <PlayerPage
      eyebrow="Games"
      title="Choose a game"
      description="Instant-win games with verified fixed odds, clear payout rules, and immediate settlement."
    >
      <div className={styles.container}>
        {/* Controls Toolbar */}
        <div className={styles.toolbar}>
          <span className={styles.gamesCount}>{GAMES.length} games available</span>

          <div className={styles.viewToggle} role="group" aria-label="Game view format">
            <button
              type="button"
              className={styles.toggleButton}
              aria-pressed={format === "grid"}
              onClick={() => setFormat("grid")}
              title="Switch to Grid View"
            >
              <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <rect x="1" y="1" width="6" height="6" rx="1.5" />
                <rect x="9" y="1" width="6" height="6" rx="1.5" />
                <rect x="1" y="9" width="6" height="6" rx="1.5" />
                <rect x="9" y="9" width="6" height="6" rx="1.5" />
              </svg>
              <span>Grid</span>
            </button>

            <button
              type="button"
              className={styles.toggleButton}
              aria-pressed={format === "list"}
              onClick={() => setFormat("list")}
              title="Switch to List View"
            >
              <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <rect x="1" y="2" width="14" height="3" rx="1" />
                <rect x="1" y="6.5" width="14" height="3" rx="1" />
                <rect x="1" y="11" width="14" height="3" rx="1" />
              </svg>
              <span>List</span>
            </button>
          </div>
        </div>

        {/* Games Presentation */}
        {format === "grid" ? (
          <div className={styles.gridCatalogue} role="region" aria-label="Games Grid">
            {GAMES.map((game) => (
              <article
                key={game.id}
                className={styles.gridCard}
                style={{ "--card-accent": game.accentColor } as React.CSSProperties}
              >
                <div className={styles.gridMedia}>
                  {game.isCrown ? (
                    <HeritageCrownLogo size={80} />
                  ) : game.logoSrc ? (
                    <img src={game.logoSrc} alt={`${game.title} logo`} className={styles.gridLogo} />
                  ) : (
                    <span className={styles.heritageEmblem}>{game.emblemText}</span>
                  )}
                  <span className={styles.badge}>{game.badge}</span>
                </div>

                <div className={styles.gridBody}>
                  <p className={styles.gameCategory}>{game.category}</p>
                  <h2 className={styles.gameTitle}>{game.title}</h2>
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
                  {game.id === "blackred" ? (
                    <div className={styles.btnGroupRow}>
                      <Button href={game.route} className={styles.secondaryActionBtn}>
                        Dashboard
                      </Button>
                      <Button
                        type="button"
                        onClick={() => handleGameAction("blackred")}
                        className={styles.playButton}
                      >
                        ⚡ Quick Play
                      </Button>
                    </div>
                  ) : (
                    <Button href={game.route} className={styles.playButton}>
                      Play {game.title}
                    </Button>
                  )}
                </div>
              </article>
            ))}
          </div>
        ) : (
          <div className={styles.listCatalogue} role="region" aria-label="Games List">
            {GAMES.map((game) => (
              <article
                key={game.id}
                className={styles.listCard}
                style={{ "--card-accent": game.accentColor } as React.CSSProperties}
              >
                <div className={styles.listMedia}>
                  {game.isCrown ? (
                    <HeritageCrownLogo size={56} />
                  ) : game.logoSrc ? (
                    <img src={game.logoSrc} alt={`${game.title} logo`} className={styles.listLogo} />
                  ) : (
                    <span className={styles.heritageEmblemSmall}>{game.emblemText}</span>
                  )}
                </div>

                <div className={styles.listBody}>
                  <div className={styles.listHeader}>
                    <div>
                      <p className={styles.gameCategory}>{game.category}</p>
                      <h2 className={styles.gameTitle}>{game.title}</h2>
                    </div>
                    <span className={styles.badge}>{game.badge}</span>
                  </div>

                  <p className={styles.gameDescription}>{game.description}</p>

                  <div className={styles.statsRow}>
                    <div className={styles.statItem}>
                      <span>Multiplier:</span>
                      <strong>{game.multiplier}</strong>
                    </div>
                    <div className={styles.statItem}>
                      <span>Stakes:</span>
                      <strong>{game.stakeRange}</strong>
                    </div>
                  </div>
                </div>

                <div className={styles.listAction}>
                  {game.id === "blackred" ? (
                    <div className={styles.btnGroupRow} style={{ minWidth: "240px" }}>
                      <Button href={game.route} className={styles.secondaryActionBtn}>
                        Dashboard
                      </Button>
                      <Button
                        type="button"
                        onClick={() => handleGameAction("blackred")}
                        className={styles.playButton}
                      >
                        ⚡ Quick Play
                      </Button>
                    </div>
                  ) : (
                    <Button href={game.route} className={styles.playButton}>
                      Enter {game.title}
                    </Button>
                  )}
                </div>
              </article>
            ))}
          </div>
        )}
      </div>

      {/* Interactive BlackRed Play Area Pop-Up */}
      <BlackRedPlayModal
        isOpen={isBlackRedModalOpen}
        onClose={() => setIsBlackRedModalOpen(false)}
        playBalanceKobo={playBalanceKobo}
        onBalanceChange={(newBal) => setPlayBalanceKobo(newBal)}
      />
    </PlayerPage>
  );
}

