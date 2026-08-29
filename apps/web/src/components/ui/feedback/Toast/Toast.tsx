"use client";

import { useEffect, type ReactNode } from "react";
import { Icon } from "@/components/ui/Icon/Icon";
import { FeedbackIcon, type FeedbackTone } from "@/components/ui/feedback/FeedbackIcon";
import styles from "./Toast.module.css";

interface ToastProps { open: boolean; tone?: FeedbackTone; title: string; children?: ReactNode; action?: ReactNode; durationMs?: number; onClose: () => void; }

export function Toast({ open, tone = "info", title, children, action, durationMs = 6000, onClose }: ToastProps) {
  useEffect(() => {
    if (!open || durationMs <= 0) return;
    const timer = window.setTimeout(onClose, durationMs);
    return () => window.clearTimeout(timer);
  }, [durationMs, onClose, open]);

  if (!open) return null;
  return (
    <aside className={styles.toast} data-tone={tone} role={tone === "error" ? "alert" : "status"} aria-atomic="true">
      <FeedbackIcon tone={tone} />
      <div className={styles.copy}><strong>{title}</strong>{children && <div>{children}</div>}</div>
      {action}
      <button className={styles.close} type="button" aria-label="Dismiss notification" onClick={onClose}><Icon name="close" /></button>
    </aside>
  );
}
