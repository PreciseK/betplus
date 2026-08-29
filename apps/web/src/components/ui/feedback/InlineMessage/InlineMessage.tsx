import type { ReactNode } from "react";
import { FeedbackIcon, type FeedbackTone } from "@/components/ui/feedback/FeedbackIcon";
import styles from "./InlineMessage.module.css";

interface InlineMessageProps {
  tone?: FeedbackTone;
  title?: string;
  children: ReactNode;
  id?: string;
}

export function InlineMessage({ tone = "info", title, children, id }: InlineMessageProps) {
  return (
    <div className={styles.message} data-tone={tone} id={id} role={tone === "error" ? "alert" : "status"}>
      <FeedbackIcon tone={tone} />
      <div>
        {title && <strong>{title}</strong>}
        <div className={styles.body}>{children}</div>
      </div>
    </div>
  );
}
