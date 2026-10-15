import Link from "next/link";
import styles from "./StakeHistory.module.css";

/**
 * There is no backend endpoint that lists a player's own past tickets across BlackRed and
 * Heritage — only single-ticket lookup by reference exists (blackRedGateway.revealTicket,
 * heritageGateway.placeTicket's reveal). Until a real "my tickets" list endpoint exists,
 * this shows an honest empty state instead of fabricated stakes.
 */
export function StakeHistory({ hideHeader = true }: { hideHeader?: boolean }) {
  return (
    <section className={styles.stakeSection} aria-label="Stake History">
      {!hideHeader && (
        <header className={styles.sectionHeader}>
          <div className={styles.headerTitleLockup}>
            <div className={styles.eyebrow}>
              <span className={styles.eyebrowDash}>──</span>
              <span className={styles.eyebrowText}>YOUR GAME HISTORY</span>
            </div>
            <h2 id="stake-history-title" className={styles.sectionTitle}>
              Stake History
            </h2>
          </div>
        </header>
      )}

      <div className={styles.feedCard}>
        <div className={styles.emptyState}>

          <h3 className={styles.emptyTitle}>Stake history isn&apos;t available here yet</h3>
          <p className={styles.emptySub}>
            You can look up a specific ticket by its reference from your receipt, but a combined list of past
            stakes is not available yet.
          </p>
          <Link href="/games" className={styles.retryBtn}>
            Browse games
          </Link>
        </div>
      </div>
    </section>
  );
}
