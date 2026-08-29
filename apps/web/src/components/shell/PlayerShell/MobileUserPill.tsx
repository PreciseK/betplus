"use client";

import { useEffect, useState } from "react";
import { profileGateway } from "@betplus/api-client";
import { useSignedIn } from "@/lib/player-auth";
import styles from "./PlayerShell.module.css";

export function MobileUserPill() {
  const { signedIn, checked } = useSignedIn();
  const [registeredName, setRegisteredName] = useState<string>();

  useEffect(() => {
    if (!checked || !signedIn) return;
    let active = true;
    profileGateway.loadProfile().then((profile) => {
      if (active) setRegisteredName(profile.registeredName);
    }).catch(() => {});
    return () => { active = false; };
  }, [checked, signedIn]);

  if (!checked) return null;

  if (!signedIn) {
    return (
      <a href="/login" className={styles.mobileUserPill}>
        <span>Sign in</span>
      </a>
    );
  }

  const firstName = registeredName?.split(" ")[0] ?? "…";

  return (
    <div className={styles.mobileUserPill}>
      <span className={styles.statusLiveDot} aria-hidden="true" />
      <span>{firstName}</span>
    </div>
  );
}
