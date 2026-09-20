import type { ReactNode } from "react";
import { Icon, type IconName } from "@/components/ui/Icon/Icon";
import styles from "./EmptyState.module.css";

export interface EmptyStateProps {
  icon?: IconName;
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
}

export function EmptyState({
  icon = "info",
  title,
  description,
  action,
  className,
}: EmptyStateProps) {
  return (
    <div className={`${styles.emptyState} ${className ?? ""}`}>
      <div className={styles.iconWrap} aria-hidden="true">
        <Icon name={icon} size="empty" />
      </div>
      <div className={styles.copy}>
        <h3>{title}</h3>
        {description && <p>{description}</p>}
      </div>
      {action && <div className={styles.action}>{action}</div>}
    </div>
  );
}
