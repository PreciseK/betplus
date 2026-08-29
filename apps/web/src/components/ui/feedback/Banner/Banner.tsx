import type { ReactNode } from "react";
import { FeedbackIcon, type FeedbackTone } from "@/components/ui/feedback/FeedbackIcon";
import styles from "./Banner.module.css";

interface BannerProps {
  tone?: FeedbackTone;
  title: string;
  children: ReactNode;
  action?: ReactNode;
}

export function Banner({ tone = "info", title, children, action }: BannerProps) {
  return (
    <section className={styles.banner} data-tone={tone} aria-labelledby={`banner-${slug(title)}`}>
      <FeedbackIcon tone={tone} />
      <div className={styles.copy}>
        <h2 id={`banner-${slug(title)}`}>{title}</h2>
        <div>{children}</div>
      </div>
      {action && <div className={styles.action}>{action}</div>}
    </section>
  );
}

function slug(value: string) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, "");
}
