import type { ReactNode } from "react";
import Image from "next/image";
import Link from "next/link";
import styles from "./AuthFlow.module.css";

export function AuthShell({ children, brandContent, brandNote = "18+ only. Play within your limits." }: { children: ReactNode; brandContent?: ReactNode; brandNote?: string }) {
  return (
    <div className={styles.shell}>
      <header className={styles.header}>
        <Link href="/" aria-label="Betplus home" className={styles.logo}>
          <Image src="/assets/betplus-logo-white.png" alt="Betplus" width={862} height={310} priority unoptimized />
        </Link>
        <Link href="/" className={styles.homeLink}>Back to home <span aria-hidden="true">↗</span></Link>
      </header>
      <div className={styles.content}>
        <aside className={styles.brandPanel} aria-label="Betplus">
          {brandContent ?? <><div className={styles.brandCopy}>
            <h2><span>Your Move.</span>{" "}<span>Your Moment.</span>{" "}<span>Your Win.</span></h2>
            <Link href="/#games" className={styles.exploreLink}>Explore the games <span aria-hidden="true">↗</span></Link>
          </div>
          <div className={styles.communityImage} aria-hidden="true">
            <Image src="/assets/hero-community.png" alt="" width={811} height={1024} priority unoptimized />
          </div></>}
          <p className={styles.brandNote}>{brandNote}</p>
        </aside>
        <main className={styles.formPanel}>{children}</main>
      </div>
    </div>
  );
}
