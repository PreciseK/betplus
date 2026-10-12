"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { profileGateway, walletGateway } from "@betplus/api-client";
import { HeritageCrownLogo } from "@/components/ui/HeritageCrownLogo/HeritageCrownLogo";
import { Button } from "@/components/ui/Button/Button";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { BlackRedPlayModal } from "@/components/games/BlackRedPlayModal/BlackRedPlayModal";
import { GamesCatalogue } from "@/components/games/GamesCatalogue/GamesCatalogue";
import { GAMES } from "@/components/games/gamesData";
import { requireAuth, useSignedIn } from "@/lib/player-auth";
import styles from "./UserDashboard.module.css";

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

      {/* 1. Games Catalogue Section (Interactive 3x3 / Cards / List) */}
      <section className={styles.section} aria-labelledby="catalogue-heading">
        <div className={styles.sectionHeader}>
          <h2 id="catalogue-heading" className={styles.sectionTitle}>
            Featured games
          </h2>
          <Link href="/games" className={styles.seeAllLink}>
            View full catalogue ({GAMES.length} games)
          </Link>
        </div>

        <GamesCatalogue
          showToolbar
          initialFormat="matrix"
          onQuickPlay={startQuickPlay}
        />
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
