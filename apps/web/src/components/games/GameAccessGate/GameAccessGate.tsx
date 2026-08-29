"use client";

import type { ReactNode } from "react";
import { useSignedIn } from "@/lib/player-auth";
import { Button } from "@/components/ui/Button/Button";
import styles from "./GameAccessGate.module.css";

interface GameAccessGateProps {
  gameTitle: string;
  children: ReactNode;
}

/**
 * Wraps a real game flow so it never mounts (and never calls purchaseTicket/revealTicket)
 * for a signed-out visitor — those would just 401. Renders inline, no redirect, so the
 * page itself still loads.
 */
export function GameAccessGate({ gameTitle, children }: GameAccessGateProps) {
  const { signedIn, checked } = useSignedIn();

  if (!checked) return null;

  if (!signedIn) {
    return (
      <div className={styles.gate}>
        <div className={styles.panel}>
          <span className={styles.eyebrow}>Sign in required</span>
          <h1 className={styles.title}>Sign in to play {gameTitle}</h1>
          <p className={styles.description}>
            Create a free Betplus account or sign back in to fund your wallet and play {gameTitle} for real stakes.
          </p>
          <div className={styles.actions}>
            <Button href="/login">Sign in</Button>
            <Button href="/register" variant="secondary">Create an account</Button>
          </div>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
