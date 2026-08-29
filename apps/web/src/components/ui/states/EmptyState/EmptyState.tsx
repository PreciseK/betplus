import type { ReactNode } from "react";
import { Icon, type IconName } from "@/components/ui/Icon/Icon";
import styles from "./EmptyState.module.css";

interface EmptyStateProps {
  icon?: IconName;
  title: string;
  description: string;
  action?: ReactNode;
}

export function EmptyState({ icon = "activity", title, description, action }: EmptyStateProps) {
  return (
    <section className={styles.state} aria-labelledby={`empty-${slug(title)}`}>
      <span className={styles.icon} aria-hidden="true"><Icon name={icon} size="empty" /></span>
      <h2 id={`empty-${slug(title)}`}>{title}</h2>
      <p>{description}</p>
      {action && <div className={styles.action}>{action}</div>}
    </section>
  );
}

function slug(value: string) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, "");
}
