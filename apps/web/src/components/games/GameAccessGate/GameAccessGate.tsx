"use client";

import type { ReactNode } from "react";
import { useSignedIn } from "@/lib/player-auth";
import { Button } from "@/components/ui/Button/Button";
import { AuthShell } from "@/components/auth/AuthShell/AuthShell";
import authStyles from "@/components/auth/AuthShell/AuthFlow.module.css";
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
      <AuthShell>
        <div className={authStyles.formWrap}>
          <span className={styles.eyebrow}>Sign in required</span>
          <h1 className={authStyles.stepH}>Sign in to play {gameTitle}</h1>
          <p className={authStyles.stepSub}>
            Sign in to your Betplus account to access your wallet and play {gameTitle}.
          </p>
          <Button href="/login" className={authStyles.btnPrimary}>Sign in</Button>
          <p className={authStyles.footerLink}>New to Betplus? <a href="/register">Create an account</a></p>
        </div>
      </AuthShell>
    );
  }

  return <>{children}</>;
}
