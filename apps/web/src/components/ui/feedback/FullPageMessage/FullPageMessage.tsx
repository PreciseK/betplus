import type { ReactNode } from "react";
import { FeedbackIcon, type FeedbackTone } from "@/components/ui/feedback/FeedbackIcon";
import styles from "./FullPageMessage.module.css";

interface FullPageMessageProps { tone?: FeedbackTone; title: string; children: ReactNode; actions?: ReactNode; }

export function FullPageMessage({ tone = "info", title, children, actions }: FullPageMessageProps) {
  return (
    <main className={styles.page}>
      <div className={styles.icon} data-tone={tone}><FeedbackIcon tone={tone} /></div>
      <h1>{title}</h1>
      <div className={styles.body}>{children}</div>
      {actions && <div className={styles.actions}>{actions}</div>}
    </main>
  );
}
