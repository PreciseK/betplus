"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { profileGateway, walletGateway } from "@betplus/api-client";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { BlackRedPlayModal } from "@/components/games/BlackRedPlayModal/BlackRedPlayModal";
import { GamesCatalogue } from "@/components/games/GamesCatalogue/GamesCatalogue";
import { GAMES } from "@/components/games/gamesData";
import { requireAuth, useSignedIn } from "@/lib/player-auth";
import styles from "./UserDashboard.module.css";

export function UserDashboard() {
  const router = useRouter();
  const { signedIn, checked } = useSignedIn();
  const [query, setQuery] = useState("");
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
    return () => { active = false; };
  }, [checked, signedIn]);

  function startQuickPlay() {
    requireAuth(router, () => setIsBlackRedModalOpen(true));
  }

  const searchTerm = query.trim().toLowerCase();
  const games = GAMES.filter((game) =>
    `${game.title} ${game.category} ${game.description}`.toLowerCase().includes(searchTerm),
  );

  return (
    <div className={styles.dashboard}>
      <header className={styles.pageHeader}>
        <div>
          <h1 className={styles.heading}>Dashboard</h1>
          {registeredName && <p className={styles.greeting}>Welcome back, {registeredName}.</p>}
        </div>
        <Link href="/wallet" className={styles.walletLink}>Wallet <span aria-hidden="true">↗</span></Link>
      </header>

      {loadFailed && (
        <InlineMessage tone="error" title="Account details unavailable">
          Refresh the page to load your name and balance.
        </InlineMessage>
      )}

      <section className={styles.gamesSection} aria-labelledby="games-heading">
        <div className={styles.sectionHeader}>
          <h2 id="games-heading" className={styles.sectionTitle}>Games</h2>
          <label className={styles.searchWrapper}>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" aria-hidden="true">
              <circle cx="10.5" cy="10.5" r="6.5" />
              <path d="m16 16 4.5 4.5" />
            </svg>
            <input type="search" placeholder="Search games" aria-label="Search games" value={query} onChange={(event) => setQuery(event.target.value)} className={styles.searchInput} />
          </label>
        </div>
        <GamesCatalogue showToolbar initialFormat="matrix" games={games} onQuickPlay={startQuickPlay} />
        {games.length === 0 && (
          <div className={styles.emptyState} role="status">
            <p>No games match “{query.trim()}”.</p>
            <button type="button" onClick={() => setQuery("")}>Clear search</button>
          </div>
        )}
      </section>

      <BlackRedPlayModal isOpen={isBlackRedModalOpen} onClose={() => setIsBlackRedModalOpen(false)} playBalanceKobo={playBalanceKobo} onBalanceChange={setPlayBalanceKobo} />
    </div>
  );
}
