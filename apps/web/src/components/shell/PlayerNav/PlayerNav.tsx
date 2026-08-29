"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Icon, type IconName } from "@/components/ui/Icon/Icon";
import styles from "./PlayerNav.module.css";

const destinations: Array<{ href: string; label: string; icon: IconName }> = [
  { href: "/home", label: "Home", icon: "home" },
  { href: "/games", label: "Games", icon: "games" },
  { href: "/wallet", label: "Wallet", icon: "wallet" },
  { href: "/activity", label: "Activity", icon: "activity" },
  { href: "/account", label: "Account", icon: "account" },
];

export function PlayerNav({ variant }: { variant: "desktop" | "mobile" }) {
  const pathname = usePathname();

  return (
    <nav className={styles[variant]} aria-label={variant === "mobile" ? "Primary navigation" : "Main navigation"}>
      <ul>
        {destinations.map((destination) => {
          const current = pathname === destination.href || pathname.startsWith(`${destination.href}/`);
          return (
            <li key={destination.href}>
              <Link className={styles.link} href={destination.href} aria-current={current ? "page" : undefined}>
                <Icon name={destination.icon} size="navigation" />
                <span>{destination.label}</span>
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
