"use client";

import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { GamesCatalogue } from "@/components/games/GamesCatalogue/GamesCatalogue";
import styles from "./page.module.css";

export default function GamesPage() {
  return (
    <PlayerPage
      title="Games"
    >
      <div className={styles.container}>
        <GamesCatalogue showToolbar initialFormat="matrix" />
      </div>
    </PlayerPage>
  );
}
