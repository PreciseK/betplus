import type { ReactNode } from "react";
import styles from "./PlayerPage.module.css";

interface PlayerPageProps {
  eyebrow?: string;
  title?: string;
  description?: string;
  actions?: ReactNode;
  hideHeader?: boolean;
  children: ReactNode;
}

export function PlayerPage({ eyebrow, title, description, actions, hideHeader, children }: PlayerPageProps) {
  return (
    <div className={styles.page}>
      {!hideHeader && title && (
        <header className={styles.heroBanner}>
          <div className={styles.heroContent}>
            <div className={styles.greetingLockup}>
              {eyebrow && <span className={styles.eyebrow}>{eyebrow}</span>}
              <h1 className={styles.greetingTitle}>{title}</h1>
              {description && <p className={styles.description}>{description}</p>}
            </div>
            {actions && <div className={styles.heroControls}>{actions}</div>}
          </div>
        </header>
      )}
      <div className={styles.innerContentWrapper}>
        {children}
      </div>
    </div>
  );
}
