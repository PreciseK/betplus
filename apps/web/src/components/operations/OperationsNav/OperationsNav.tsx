"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Icon } from "@/components/ui/Icon/Icon";
import {
  destinationsForRole,
  type OperationsDestination,
  type OperationsNavigationGroup,
} from "@/components/operations/operations-navigation";
import type { OperatorRole } from "@/mocks/operator-session";
import { isOperationsGameScope, type OperationsGameScope } from "@/components/operations/operations-games";
import { useOperationsUrlFilters } from "@/components/operations/useOperationsUrlFilters";
import styles from "./OperationsNav.module.css";

interface OperationsNavProps {
  role: OperatorRole;
  variant: "desktop" | "mobile";
}

const navigationGroups: readonly OperationsNavigationGroup[] = [
  "Home",
  "Customers",
  "Finance",
  "Product",
  "Compliance",
  "Insights",
  "Administration",
];

const GROUP_HEADER_LABELS: Record<OperationsNavigationGroup, string> = {
  Home: "MAIN MENU",
  Customers: "CUSTOMERS & SUPPORT",
  Finance: "FINANCE & RISK",
  Product: "GAMES & PRODUCT",
  Compliance: "COMPLIANCE & AUDIT",
  Insights: "ANALYTICS & INSIGHTS",
  Administration: "SYSTEM & ACCESS",
};

const NAVIGATION_FILTER_DEFAULTS = { game: "all" };

function scopedHref(href: string, game: OperationsGameScope) {
  return game === "all" ? href : `${href}?game=${encodeURIComponent(game)}`;
}

function DestinationLink({ destination, current, game }: { destination: OperationsDestination; current: boolean; game: OperationsGameScope }) {
  return (
    <Link className={styles.link} href={scopedHref(destination.href, game)} aria-current={current ? "page" : undefined}>
      <Icon name={destination.icon} size="navigation" />
      <span className={styles.linkLabel}>{destination.label}</span>
    </Link>
  );
}

function NavigationLinks({ role }: { role: OperatorRole }) {
  const pathname = usePathname();
  const { filters } = useOperationsUrlFilters(NAVIGATION_FILTER_DEFAULTS);
  const selectedGame = isOperationsGameScope(filters.game) ? filters.game : "all";
  const destinations = destinationsForRole(role);

  return (
    <div className={styles.groups}>
      {navigationGroups.map((group) => {
        const groupedDestinations = destinations.filter((destination) => destination.group === group);
        if (groupedDestinations.length === 0) return null;

        if (group === "Home") {
          const destination = groupedDestinations[0];
          const current = pathname === destination.href || pathname.startsWith(`${destination.href}/`);
          return (
            <div key={group} className={styles.groupSection}>
              <span className={styles.sectionHeader}>{GROUP_HEADER_LABELS[group]}</span>
              <DestinationLink destination={destination} current={current} game={selectedGame} />
            </div>
          );
        }

        const containsCurrent = groupedDestinations.some(
          (destination) => pathname === destination.href || pathname.startsWith(`${destination.href}/`),
        );

        const subgroups: { name: string | undefined; items: OperationsDestination[] }[] = [];
        for (const destination of groupedDestinations) {
          const last = subgroups[subgroups.length - 1];
          if (last && last.name === destination.subgroup) {
            last.items.push(destination);
          } else {
            subgroups.push({ name: destination.subgroup, items: [destination] });
          }
        }

        return (
          <div key={group} className={styles.groupSection}>
            <details className={styles.group} open={containsCurrent || undefined}>
              <summary>
                <span className={styles.sectionHeader}>{GROUP_HEADER_LABELS[group]}</span>
                <Icon name="chevron-right" />
              </summary>
              <div className={styles.groupBody}>
                {subgroups.map((subgroup, index) => (
                  <div className={styles.subgroup} key={subgroup.name ?? index}>
                    {subgroup.name ? <p className={styles.subgroupLabel}>{subgroup.name}</p> : null}
                    <ul>
                      {subgroup.items.map((destination) => {
                        const current = pathname === destination.href || pathname.startsWith(`${destination.href}/`);
                        return <li key={destination.href}><DestinationLink destination={destination} current={current} game={selectedGame} /></li>;
                      })}
                    </ul>
                  </div>
                ))}
              </div>
            </details>
          </div>
        );
      })}
    </div>
  );
}

export function OperationsNav({ role, variant }: OperationsNavProps) {
  if (variant === "mobile") {
    return (
      <details className={styles.mobileDisclosure}>
        <summary>Menu</summary>
        <nav aria-label="Back-office navigation"><NavigationLinks role={role} /></nav>
      </details>
    );
  }

  return <nav className={styles.desktop} aria-label="Back-office navigation"><NavigationLinks role={role} /></nav>;
}
