"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { profileGateway, sessionGateway, walletGateway } from "@betplus/api-client";
import { formatKobo } from "@/lib/money";
import { useSignedIn } from "@/lib/player-auth";
import styles from "./PlayerRightPanel.module.css";

const KYC_TIER_LABELS: Record<number, string> = {
  0: "Unverified",
  1: "NIN verified",
  2: "NIN and BVN verified",
};

export function PlayerRightPanel() {
  const router = useRouter();
  const { signedIn, checked, refresh } = useSignedIn();
  const [hideBalances, setHideBalances] = useState(false);

  const [registeredName, setRegisteredName] = useState<string>();
  const [registeredSourceLabel, setRegisteredSourceLabel] = useState<string>();
  const [kycTier, setKycTier] = useState<number>();
  const [playBalanceKobo, setPlayBalanceKobo] = useState(0);
  const [winningsBalanceKobo, setWinningsBalanceKobo] = useState(0);
  const [loadFailed, setLoadFailed] = useState(false);
  const [signingOut, setSigningOut] = useState(false);

  useEffect(() => {
    if (!checked || !signedIn) return;
    let active = true;
    Promise.all([profileGateway.loadProfile(), walletGateway.loadWallet()])
      .then(([profile, wallet]) => {
        if (!active) return;
        setRegisteredName(profile.registeredName);
        setKycTier(profile.kycTier);
        setRegisteredSourceLabel(wallet.registeredSourceLabel);
        setPlayBalanceKobo(wallet.playBalanceKobo);
        setWinningsBalanceKobo(wallet.winningsBalanceKobo);
      })
      .catch(() => {
        if (active) setLoadFailed(true);
      });
    return () => { active = false; };
  }, [checked, signedIn]);

  async function handleSignOut() {
    setSigningOut(true);
    try {
      await sessionGateway.signOut();
    } catch {
      // Sign-out clears the server-side session either way; a network failure here just
      // means the local UI won't reflect it until refresh() re-reads the (still-present)
      // cookie — not worth blocking the redirect over.
    }
    refresh();
    router.push("/");
  }

  const initials = registeredName
    ? registeredName.split(" ").filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase()).join("")
    : "…";

  return (
    <aside className={styles.rightSidebar} aria-label="Player Account & Security Panel">
      <div className={styles.scrollableContent}>
        {!checked ? null : !signedIn ? (
          <section className={styles.emptyStateCard}>
            <span className={styles.emptyStateIcon}>👤</span>
            <strong className={styles.emptyStateTitle}>Sign in to see your account</strong>
            <p className={styles.emptyStateDesc}>Your balance, verification status and account details show up here once you're signed in.</p>
            <div className={styles.emptyStateActions}>
              <Link href="/login" className={styles.depositActionBtn}>Sign in</Link>
              <Link href="/register" className={styles.withdrawActionBtn}>Create account</Link>
            </div>
          </section>
        ) : (
          <>
            <section className={styles.heroCard}>
              <div className={styles.heroProfileRow}>
                <div className={styles.avatarRing}>
                  <span className={styles.avatarImg} style={{ display: "grid", placeItems: "center", fontWeight: 800 }}>
                    {initials}
                  </span>
                </div>
                <div className={styles.profileTextLockup}>
                  <strong className={styles.heroName}>{registeredName ?? "Loading…"}</strong>
                  <span className={styles.verifiedAccountTag}>
                    {kycTier !== undefined ? (KYC_TIER_LABELS[kycTier] ?? `KYC tier ${kycTier}`).toUpperCase() : "…"}
                  </span>
                </div>
              </div>

              <div className={styles.balanceHeaderRow}>
                <span className={styles.balanceSectionLabel}>BETPLUS BALANCE</span>
                <button
                  type="button"
                  className={styles.eyeToggleBtn}
                  onClick={() => setHideBalances(!hideBalances)}
                  aria-label={hideBalances ? "Show balances" : "Hide balances"}
                  title={hideBalances ? "Show balances" : "Hide balances"}
                >
                  {hideBalances ? (
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                      <line x1="1" y1="1" x2="23" y2="23" />
                    </svg>
                  ) : (
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                      <circle cx="12" cy="12" r="3" />
                    </svg>
                  )}
                </button>
              </div>

              <div className={styles.balanceTilesGrid}>
                <div className={styles.balanceTile}>
                  <span className={styles.tileLabel}>PLAY BALANCE</span>
                  <div className={styles.tileValueRow}>
                    <strong className={styles.tileAmount}>{hideBalances ? "••••••" : formatKobo(playBalanceKobo)}</strong>
                  </div>
                </div>

                <div className={styles.balanceTile}>
                  <span className={styles.tileLabel}>WINNINGS BALANCE</span>
                  <div className={styles.tileValueRow}>
                    <strong className={styles.tileAmount}>{hideBalances ? "••••••" : formatKobo(winningsBalanceKobo)}</strong>
                  </div>
                </div>
              </div>

              <div className={styles.heroActionBtnsRow}>
                <Link href="/wallet" className={styles.depositActionBtn}>
                  <span className={styles.actionIcon}>📥</span>
                  <span>Deposit</span>
                </Link>
                <Link href="/wallet" className={styles.withdrawActionBtn}>
                  <span className={styles.actionIcon}>📤</span>
                  <span>Withdraw</span>
                </Link>
              </div>

              {loadFailed && <p className={styles.loadFailedNote}>Some account details couldn&apos;t load. Figures may be out of date.</p>}
            </section>

            <section className={styles.detailsGroup}>
              <div className={styles.groupHeadingRow}>
                <span className={styles.groupDash}>──</span>
                <h3 className={styles.groupTitle}>PERSONAL DETAILS</h3>
              </div>

              <div className={styles.groupCardContainer}>
                <div className={styles.detailRow}>
                  <div className={styles.detailIconBox}>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                      <circle cx="12" cy="7" r="4" />
                    </svg>
                  </div>
                  <div className={styles.detailContent}>
                    <span className={styles.detailFieldLabel}>FULL NAME</span>
                    <strong className={styles.detailFieldValue}>{registeredName ?? "—"}</strong>
                  </div>
                </div>

                <div className={styles.detailRow}>
                  <div className={styles.detailIconBox}>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <rect x="5" y="2" width="14" height="20" rx="2" ry="2" />
                      <line x1="12" y1="18" x2="12.01" y2="18" />
                    </svg>
                  </div>
                  <div className={styles.detailContent}>
                    <span className={styles.detailFieldLabel}>FUNDING SOURCE</span>
                    <strong className={styles.detailFieldValue}>{registeredSourceLabel ?? "—"}</strong>
                  </div>
                </div>
              </div>
            </section>

            <section className={styles.detailsGroup}>
              <div className={styles.groupHeadingRow}>
                <span className={styles.groupDash}>──</span>
                <h3 className={styles.groupTitle}>ACCOUNT</h3>
              </div>

              <div className={styles.groupCardContainer}>
                <button type="button" className={styles.securityActionRow} onClick={handleSignOut} disabled={signingOut}>
                  <div className={`${styles.detailIconBox} ${styles.logoutIconBox}`}>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                      <polyline points="16 17 21 12 16 7" />
                      <line x1="21" y1="12" x2="9" y2="12" />
                    </svg>
                  </div>
                  <div className={styles.securityTextLockup}>
                    <strong className={styles.logoutTitle}>{signingOut ? "Signing out…" : "Log Out"}</strong>
                    <span className={styles.logoutSub}>Sign out of Betplus on this device</span>
                  </div>
                  <span className={styles.chevronIcon}>›</span>
                </button>
              </div>
            </section>
          </>
        )}

        {/* Support links stay visible regardless of sign-in state — they're real external links. */}
        <section className={styles.detailsGroup}>
          <div className={styles.groupHeadingRow}>
            <span className={styles.groupDash}>──</span>
            <h3 className={styles.groupTitle}>SUPPORT</h3>
          </div>

          <div className={styles.supportCardContainer}>
            <div className={styles.quickChannelsRow}>
              <a href="https://wa.me/2348000000000" target="_blank" rel="noreferrer" className={styles.channelChip}>
                <span>💬 WhatsApp</span>
              </a>
              <a href="tel:+2348000000000" className={styles.channelChip}>
                <span>📞 Call</span>
              </a>
              <Link href="/account" className={styles.channelChip}>
                <span>❓ Help</span>
              </Link>
            </div>
          </div>
        </section>
      </div>
    </aside>
  );
}
