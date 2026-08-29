import Link from "next/link";
import styles from "./StakeHistory.module.css";

/**
 * There is no backend endpoint that lists a player's own past tickets across BlackRed and
 * Heritage — only single-ticket lookup by reference exists (blackRedGateway.revealTicket,
 * heritageGateway.placeTicket's reveal). Until a real "my tickets" list endpoint exists,
 * this shows an honest empty state instead of fabricated stakes.
 */
export function StakeHistory() {
  return (
    <section className={styles.stakeSection} aria-labelledby="stake-history-title">
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

      <div className={styles.feedCard}>
        <div className={styles.emptyState}>
          <div className={styles.emptyIconBox}>ⓘ</div>
          <h3 className={styles.emptyTitle}>Stake history isn&apos;t available here yet</h3>
          <p className={styles.emptySub}>
            You can look up a specific ticket by its reference from your receipt, but a combined list of past
            stakes isn&apos;t wired up yet.
          </p>
          <Link href="/games" className={styles.retryBtn}>
            ⚡ Play Games
          </Link>
        </div>
      </div>
    </section>
  );
}
