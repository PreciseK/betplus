"use client";

import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { GamesCatalogue } from "@/components/games/GamesCatalogue/GamesCatalogue";
import styles from "./page.module.css";

export default function GamesPage() {
  return (
    <PlayerPage
      eyebrow="Games"
      title="Choose a game"
      description="Instant-win games with verified fixed odds, clear payout rules, and immediate settlement."
    >
      <div className={styles.container}>
        <GamesCatalogue showToolbar initialFormat="matrix" />
      </div>
    </PlayerPage>
  );
}
