import Link from "next/link";
import type { ReactNode } from "react";
import { Icon } from "@/components/ui/Icon/Icon";
import { roleHasCapability, type OperationsCapability } from "@/components/operations/operations-permissions";
import { OPERATOR_ROLE_LABELS } from "@/components/operations/operations-navigation";
import type { OperatorRole } from "@/mocks/operator-session";
import styles from "./OperationsAccessBoundary.module.css";

interface OperationsAccessBoundaryProps {
  capability: OperationsCapability;
  role: OperatorRole;
  children: ReactNode;
}

export function OperationsAccessBoundary({ capability, role, children }: OperationsAccessBoundaryProps) {
  if (roleHasCapability(role, capability)) return children;

  return (
    <section className={styles.denied} aria-labelledby="access-denied-title">
      <Icon name="lock" size="empty" />
      <div>
        <h1 id="access-denied-title">This task is outside your access scope</h1>
        <p>{OPERATOR_ROLE_LABELS[role]} does not include <strong>{capability}</strong>. The attempt has not changed any data.</p>
      </div>
      <Link href="/back-office/overview">Return to your overview</Link>
      <p className={styles.help}>If this blocks assigned work, ask a system administrator to review your role. Access changes remain audited.</p>
    </section>
  );
}
