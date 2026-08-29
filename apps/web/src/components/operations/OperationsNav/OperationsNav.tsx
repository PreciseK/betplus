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

const NAVIGATION_FILTER_DEFAULTS = { game: "all" };

function scopedHref(href: string, game: OperationsGameScope) {
  return game === "all" ? href : `${href}?game=${encodeURIComponent(game)}`;
}

function DestinationLink({ destination, current, game }: { destination: OperationsDestination; current: boolean; game: OperationsGameScope }) {
  return (
    <Link className={styles.link} href={scopedHref(destination.href, game)} aria-current={current ? "page" : undefined}>
      <Icon name={destination.icon} size="navigation" />
      <span>{destination.label}</span>
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
          return <DestinationLink key={group} destination={destination} current={current} game={selectedGame} />;
        }

        const containsCurrent = groupedDestinations.some(
          (destination) => pathname === destination.href || pathname.startsWith(`${destination.href}/`),
        );

        return (
          <details className={styles.group} key={group} open={containsCurrent || undefined}>
            <summary>
              <span>{group}</span>
              <Icon name="chevron-right" />
            </summary>
            <div className={styles.groupBody}>
              <ul>
                {groupedDestinations.map((destination) => {
                  const current = pathname === destination.href || pathname.startsWith(`${destination.href}/`);
                  return <li key={destination.href}><DestinationLink destination={destination} current={current} game={selectedGame} /></li>;
                })}
              </ul>
            </div>
          </details>
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
