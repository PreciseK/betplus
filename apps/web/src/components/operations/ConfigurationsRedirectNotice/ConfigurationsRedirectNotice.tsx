import Link from "next/link";
import styles from "@/components/operations/OperationsConsole.module.css";

/** The old fixture here duplicated GameRegistryConsole/JurisdictionConsole's real data with a second UI. Redirect instead of maintaining two UIs over the same tables. */
export function ConfigurationsRedirectNotice() {
  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Product</p>
          <h1>Configurations</h1>
          <p>Choose the settings you want to manage.</p>
        </div>
      </header>

      <section className={styles.section}>
        <ul className={styles.linkGrid}>
          <li><Link href="/back-office/games"><strong>Games &amp; prize tables ↗</strong><span>Manage availability, stakes, and payout tables.</span></Link></li>
          <li><Link href="/back-office/game-economics"><strong>Funding &amp; payout rules ↗</strong><span>Prepare and review how each game is funded.</span></Link></li>
          <li><Link href="/back-office/jurisdictions"><strong>Licences &amp; tax ↗</strong><span>Review state licences and withholding rates.</span></Link></li>
        </ul>
      </section>
    </div>
  );
}
