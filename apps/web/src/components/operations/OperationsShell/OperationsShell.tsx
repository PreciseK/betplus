"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import Link from "next/link";
import { backOfficeGateway } from "@betplus/api-client";
import { OperationsNav } from "@/components/operations/OperationsNav/OperationsNav";
import { OPERATOR_ROLE_LABELS } from "@/components/operations/operations-navigation";
import { Logo } from "@/components/ui/Logo/Logo";
import type { OperatorIdentity, OperatorRole } from "@/mocks/operator-session";
import type { OperationsCapability } from "@/components/operations/operations-permissions";
import { OperationsAccessBoundary } from "@/components/operations/OperationsAccessBoundary/OperationsAccessBoundary";
import { GameScopeSelector } from "@/components/operations/GameScopeSelector/GameScopeSelector";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./OperationsShell.module.css";

interface OperationsShellProps {
  children: ReactNode;
  operatorName: string;
  role: OperatorRole;
  requiredCapability?: OperationsCapability;
}

export function OperationsShell({ children, operatorName, role, requiredCapability }: OperationsShellProps) {
  const [activeOperator, setActiveOperator] = useState<OperatorIdentity | null>(null);
  const [hasSession, setHasSession] = useState<boolean | null>(null);
  const searchInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    function handleShortcut(event: KeyboardEvent) {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        searchInputRef.current?.focus();
      }
    }
    window.addEventListener("keydown", handleShortcut);
    return () => window.removeEventListener("keydown", handleShortcut);
  }, []);

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setHasSession(false);
      if (typeof window !== "undefined") {
        window.location.replace("/back-office?session_expired=1");
      }
      return;
    }
    setHasSession(true);
    const stored = window.sessionStorage.getItem("betplus.operator.session");
    if (stored) {
      try {
        setActiveOperator(JSON.parse(stored));
      } catch {}
    }
  }, []);

  if (hasSession === false) {
    return (
      <div className={styles.viewport}>
        <div style={{ display: "flex", justifyContent: "center", alignItems: "center", minHeight: "100vh", color: "white" }}>
          Redirecting to operator sign in…
        </div>
      </div>
    );
  }

  const effectiveRole = activeOperator?.role ?? role;
  const effectiveName = activeOperator?.displayName ?? operatorName;
  const initials = effectiveName.split(" ").map((part) => part[0]).join("").slice(0, 2);

  return (
    <div className={styles.viewport}>
      <a className="skip-link" href="#operations-main">Skip to operations workspace</a>
      <div className={styles.shell}>
        <aside className={styles.sidebar}>
          <div className={styles.workspaceCard}>
            <div className={styles.workspaceIcon} aria-hidden="true">B+</div>
            <div className={styles.workspaceMeta}>
              <strong>Betplus Operations</strong>
              <span className={styles.workspaceSubtext}>Nigeria Hub</span>
            </div>
            <span className={styles.liveStatusBadge} title="Production live engine">
              <span className={styles.liveDot} /> LIVE
            </span>
          </div>

          <div className={styles.sidebarSearch}>
            <Icon name="search" />
            <input
              ref={searchInputRef}
              type="search"
              placeholder="Quick search…"
              aria-label="Find a player or ticket"
              onKeyDown={(e) => {
                if (e.key === "Enter" && e.currentTarget.value) {
                  window.location.href = `/back-office/players?query=${encodeURIComponent(e.currentTarget.value)}`;
                }
              }}
            />
            <kbd className={styles.shortcutKey}>⌘K</kbd>
          </div>

          <div className={styles.navContainer}>
            <OperationsNav role={effectiveRole} variant="desktop" />
          </div>

          <div className={styles.sidebarProfile}>
            <span className={styles.avatar} aria-hidden="true">{initials}</span>
            <div className={styles.profileDetails}>
              <strong>{effectiveName}</strong>
              <span>{OPERATOR_ROLE_LABELS[effectiveRole]}</span>
            </div>
            <Link
              className={styles.sidebarSignOut}
              href="/back-office"
              title="Sign out"
              onClick={() => backOfficeGateway.clearSession()}
            >
              <Icon name="arrow-right" />
            </Link>
          </div>
        </aside>

        <div className={styles.workspace}>
          <header className={styles.header}>
            <div className={styles.mobileBrand}>
              <Logo href="/back-office/overview" />
            </div>
            <div className={styles.shiftBadge}>
              <span className={styles.livePulseDot} />
              <div className={styles.shiftMeta}>
                <strong>Shift Active</strong>
                <span>{effectiveName} &bull; {OPERATOR_ROLE_LABELS[effectiveRole]}</span>
              </div>
            </div>
            <form className={styles.globalSearch} role="search" action="/back-office/players">
              <Icon name="search" />
              <label className="sr-only" htmlFor="operations-search">Find a player or ticket</label>
              <input id="operations-search" name="query" type="search" placeholder="Find player or ticket…" />
              <button type="submit" aria-label="Run search"><Icon name="arrow-right" /></button>
            </form>
            <div className={styles.headerActions}>
              <GameScopeSelector />
              <Link
                className={styles.signOut}
                href="/back-office"
                onClick={() => backOfficeGateway.clearSession()}
              >
                Sign out
              </Link>
            </div>
          </header>
          <div className={styles.mobileNav}>
            <OperationsNav role={effectiveRole} variant="mobile" />
          </div>
          <main className={styles.main} id="operations-main" tabIndex={-1}>
            {requiredCapability ? (
              <OperationsAccessBoundary capability={requiredCapability} role={effectiveRole}>{children}</OperationsAccessBoundary>
            ) : children}
          </main>
        </div>
      </div>
    </div>
  );
}
