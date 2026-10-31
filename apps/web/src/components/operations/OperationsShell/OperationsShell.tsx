"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { backOfficeGateway } from "@betplus/api-client";
import { OperationsNav } from "@/components/operations/OperationsNav/OperationsNav";
import { OPERATIONS_DESTINATIONS, OPERATOR_ROLE_LABELS } from "@/components/operations/operations-navigation";
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
  const pathname = usePathname();
  const destination = OPERATIONS_DESTINATIONS.find(item => pathname.replace(/\/$/, "") === item.href);

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
        <div className={styles.redirect}>
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
            <Logo href="/back-office/overview" variant="color" height={34} />
            <div className={styles.workspaceMeta}>
              <span className={styles.workspaceSubtext}>Back office</span>
            </div>
          </div>

          <div className={styles.sidebarSearch}>
            <Icon name="search" />
            <input
              ref={searchInputRef}
              type="search"
              placeholder="Find a player"
              aria-label="Find a player"
              onKeyDown={(e) => {
                if (e.key === "Enter" && e.currentTarget.value) {
                  window.location.href = `/back-office/players?query=${encodeURIComponent(e.currentTarget.value)}`;
                }
              }}
            />
            <kbd className={styles.shortcutKey}>Ctrl K</kbd>
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
              aria-label="Sign out"
              onClick={() => backOfficeGateway.clearSession()}
            >
              <Icon name="arrow-right" />
            </Link>
          </div>
        </aside>

        <div className={styles.workspace}>
          <header className={styles.header}>
            <div className={styles.mobileBrand}>
              <Logo href="/back-office/overview" variant="color" height={30} />
            </div>
            <div className={styles.shiftBadge}>

              <div className={styles.shiftMeta}>
                <span>{destination?.group === "Home" ? "Workspace" : destination?.group ?? "Workspace"}</span>
                <Icon name="chevron-right" />
                <strong>{destination?.label ?? "Approvals"}</strong>
              </div>
            </div>
            <form className={styles.globalSearch} role="search" action="/back-office/players">
              <Icon name="search" />
              <label className="sr-only" htmlFor="operations-search">Find a player</label>
              <input id="operations-search" name="query" type="search" placeholder="Find a player" />
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
