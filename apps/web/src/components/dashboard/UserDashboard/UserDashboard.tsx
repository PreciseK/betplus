"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { profileGateway, walletGateway } from "@betplus/api-client";
import { HeritageCrownLogo } from "@/components/ui/HeritageCrownLogo/HeritageCrownLogo";
import { Button } from "@/components/ui/Button/Button";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { BlackRedPlayModal } from "@/components/games/BlackRedPlayModal/BlackRedPlayModal";
import { requireAuth, useSignedIn } from "@/lib/player-auth";
import styles from "./UserDashboard.module.css";

const RECOMMENDED_GAMES = [
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
    accentColor: "#ea580c",
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

function timeOfDayGreeting(): string {
  const hour = new Date().getHours();
  if (hour < 12) return "GOOD MORNING";
  if (hour < 17) return "GOOD AFTERNOON";
  return "GOOD EVENING";
}

export function UserDashboard() {
  const router = useRouter();
  const { signedIn, checked } = useSignedIn();
  const [isBlackRedModalOpen, setIsBlackRedModalOpen] = useState(false);
  const [playBalanceKobo, setPlayBalanceKobo] = useState(0);
  const [registeredName, setRegisteredName] = useState<string>();
  const [loadFailed, setLoadFailed] = useState(false);

  useEffect(() => {
    if (!checked || !signedIn) return;
    let active = true;
    Promise.all([profileGateway.loadProfile(), walletGateway.loadWallet()])
      .then(([profile, wallet]) => {
        if (!active) return;
        setRegisteredName(profile.registeredName);
        setPlayBalanceKobo(wallet.playBalanceKobo);
      })
      .catch(() => {
        if (active) setLoadFailed(true);
      });
    return () => {
      active = false;
    };
  }, [checked, signedIn]);

  function startQuickPlay() {
    requireAuth(router, () => setIsBlackRedModalOpen(true));
  }

  return (
    <div className={styles.dashboard}>
      {/* Top Search & Quick Action Bar */}
      <header className={styles.topActionBar}>
        <div className={styles.searchWrapper}>
          <span className={styles.searchIcon}>🔍</span>
          <input
            type="search"
            placeholder="Search games, rules, tickets..."
            className={styles.topSearchInput}
            aria-label="Search games"
          />
        </div>

        <button type="button" className={styles.filterBtn} aria-label="Filters" title="Filters">
          🎚️
        </button>

        <button
          type="button"
          onClick={startQuickPlay}
          className={styles.startStreamBtn}
        >
          <span>⚡ Start Play</span>
          <span className={styles.playCircleIcon}>▶</span>
        </button>
      </header>

      {loadFailed && (
        <InlineMessage tone="error" title="Some details couldn't load">
          Your balance and name couldn't be verified. Figures shown elsewhere on this page may be out of date until you refresh.
        </InlineMessage>
      )}

      {/* Welcome Greeting Area */}
      <section className={styles.welcomeHeroArea} aria-label="Welcome greeting">
        <div className={styles.welcomeEyebrow}>
          <span className={styles.eyebrowDash}>──</span>
          <span className={styles.eyebrowText}>{timeOfDayGreeting()}</span>
        </div>
        <h1 className={styles.welcomeHeading}>
          Welcome back{registeredName ? <>, <span className={styles.welcomeName}>{registeredName}</span></> : null}.
        </h1>
      </section>

      {/* 1. Recommended Games Section (Same Styling as Games Dashboard) */}
      <section className={styles.section} aria-labelledby="recommended-heading">
        <div className={styles.sectionHeader}>
          <h2 id="recommended-heading" className={styles.sectionTitle}>
            Recommended games
          </h2>
          <Link href="/games" className={styles.seeAllLink}>
            View full catalogue (2 games)
          </Link>
        </div>

        <div className={styles.gamesCatalogueGrid}>
          {RECOMMENDED_GAMES.map((game) => (
            <article
              key={game.id}
              className={styles.gameCard}
              style={{ "--card-accent": game.accentColor } as React.CSSProperties}
            >
              {/* Media banner with logo / crown & badge */}
              <div className={styles.gameCardMedia}>
                {game.isCrown ? (
                  <HeritageCrownLogo size={82} />
                ) : game.logoSrc ? (
                  <img
                    src={game.logoSrc}
                    alt={`${game.title} logo`}
                    className={styles.gameCardLogo}
                  />
                ) : null}
                <span className={styles.gameBadge}>{game.badge}</span>
              </div>

              {/* Game details body */}
              <div className={styles.gameCardBody}>
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

              {/* Action Buttons */}
              <div className={styles.gameCardFooter}>
                {game.id === "blackred" ? (
                  <div className={styles.btnGroupRow}>
                    <Button href={game.route} className={styles.secondaryActionBtn}>
                      Dashboard
                    </Button>
                    <Button
                      type="button"
                      onClick={() => setIsBlackRedModalOpen(true)}
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
      </section>

      {/* 2. Showcase Grid: BlackRed Attached Image Arena & Small Monarch Showcase */}
      <section className={styles.section} aria-labelledby="showcase-heading">
        <div className={styles.sectionHeader}>
          <h2 id="showcase-heading" className={styles.sectionTitle}>
            Game Arenas & Highlights
          </h2>
        </div>

        <div className={styles.showcaseGrid}>
          {/* Column 1: BlackRed Card Arena using User Uploaded Cards Image */}
          <Link href="/games/blackred" className={styles.showcaseCard}>
            <div className={styles.blackredArenaMedia}>
              <img
                src="/assets/blackred-arena-cards.png"
                alt="BlackRed Ace and King Cards"
                className={styles.blackredCardsImg}
              />
              <span className={styles.deck3DBadge}>⚡ Instant Fixed-Odds · 2× to 100×</span>
              <div className={styles.showcasePlayOverlay}>
                <span className={styles.overlayPlayBtn}>▶</span>
              </div>
            </div>
            <div className={styles.showcaseInfo}>
              <strong>BlackRed Card Arena</strong>
              <p>Predict sequence of 1 to 5 Black or Red cards with verifiable fixed odds.</p>
            </div>
          </Link>

          {/* Column 2: Monarch Showcase (Small Sized King & Queen Portraits) */}
          <Link href="/games/heritage" className={styles.showcaseCard}>
            <div className={styles.monarchDualContainer}>
              <div className={styles.monarchPairGrid}>
                {/* Small King Portrait */}
                <div className={styles.monarchSmallPortrait}>
                  <img
                    src="/games/heritage/monarchs/yoruba_king.png"
                    alt="Yoruba King"
                    className={styles.monarchPortraitImg}
                  />
                  <div className={styles.monarchLabelChip}>
                    <span>Ọba (King)</span>
                  </div>
                </div>

                {/* Small Queen Portrait */}
                <div className={styles.monarchSmallPortrait}>
                  <img
                    src="/games/heritage/monarchs/yoruba_queen.png"
                    alt="Yoruba Queen"
                    className={styles.monarchPortraitImg}
                  />
                  <div className={styles.monarchLabelChip}>
                    <span>Olórì (Queen)</span>
                  </div>
                </div>
              </div>

              <span className={styles.heritageBadgeTop}>👑 7 Nigerian Royal Dynasties</span>
              <div className={styles.showcasePlayOverlay}>
                <span className={styles.overlayPlayBtn}>▶</span>
              </div>
            </div>
            <div className={styles.showcaseInfo}>
              <strong>Heritage Regalia & Royal Dynasty</strong>
              <p>Match sacred royal crowns, beads and attire for instant payouts and draw entries.</p>
            </div>
          </Link>
        </div>
      </section>

      {/* BlackRed Quick Play Modal */}
      <BlackRedPlayModal
        isOpen={isBlackRedModalOpen}
        onClose={() => setIsBlackRedModalOpen(false)}
        playBalanceKobo={playBalanceKobo}
        onBalanceChange={(newPlayBalanceKobo) => setPlayBalanceKobo(newPlayBalanceKobo)}
      />
    </div>
  );
}
