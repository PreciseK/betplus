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
          <p>Game and jurisdiction configuration already lives in two real, wired consoles — this screen doesn&apos;t duplicate them.</p>
        </div>
      </header>

      <section className={styles.section}>
        <ul style={{ display: "grid", gap: "12px" }}>
          <li><Link href="/back-office/games">Game registry &amp; prize tables →</Link></li>
          <li><Link href="/back-office/jurisdictions">Jurisdiction &amp; licence console →</Link></li>
        </ul>
      </section>
    </div>
  );
}
