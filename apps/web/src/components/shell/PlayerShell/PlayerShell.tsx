import type { ReactNode } from "react";
import Link from "next/link";
import { PlayerNav } from "@/components/shell/PlayerNav/PlayerNav";
import { PlayerRightPanel } from "@/components/shell/PlayerRightPanel/PlayerRightPanel";
import { SettingsRailBtn } from "@/components/shell/PlayerShell/SettingsRailBtn";
import { MobileUserPill } from "@/components/shell/PlayerShell/MobileUserPill";
import styles from "./PlayerShell.module.css";

export function PlayerShell({ children }: { children: ReactNode }) {
  return (
    <div className={styles.viewport}>
      <a className="skip-link" href="#main-content">Skip to main content</a>

      {/* Mobile Header */}
      <header className={styles.mobileHeader}>
        <Link href="/home" className={styles.mobileBrand} aria-label="Betplus Home">
          <img
            src="/assets/betplus-logo-white.png"
            alt="Betplus"
            className={styles.mobileLogoImg}
          />
        </Link>
        <MobileUserPill />
      </header>

      {/* Main 3-Column Gaming Console Container */}
      <div className={styles.consoleContainer}>
        {/* 1. Left Slim Sidebar */}
        <aside className={styles.leftRail} aria-label="Navigation sidebar">
          <div className={styles.railTop}>
            <Link href="/home" className={styles.brandGlyph} aria-label="Betplus Home">
              <img
                src="/assets/betplus-favicon.png"
                alt="Betplus"
                className={styles.brandFaviconImg}
              />
            </Link>
          </div>

          <div className={styles.railNav}>
            <PlayerNav variant="desktop" />
          </div>

          <div className={styles.railBottom}>
            <SettingsRailBtn />
          </div>
        </aside>

        {/* 2. Center Main Gaming Stage */}
        <main className={styles.mainStage} id="main-content" tabIndex={-1}>
          {children}
        </main>

        {/* 3. Right Docked High-Contrast Panel */}
        <PlayerRightPanel />
      </div>

      {/* Mobile Bottom Navigation */}
      <PlayerNav variant="mobile" />
    </div>
  );
}
