"use client";

import { useEffect, useId, useRef, type ReactNode } from "react";
import { Button } from "@/components/ui/Button/Button";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./Dialog.module.css";

interface DialogProps {
  open: boolean;
  title: string;
  children: ReactNode;
  confirmLabel: string;
  cancelLabel?: string;
  busy?: boolean;
  destructive?: boolean;
  balancedActions?: boolean;
  onConfirm: () => void;
  onClose: () => void;
}

export function Dialog({ open, title, children, confirmLabel, cancelLabel = "Cancel", busy = false, destructive = false, balancedActions = false, onConfirm, onClose }: DialogProps) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
  }, [open]);

  return (
    <dialog ref={ref} className={styles.dialog} aria-labelledby={titleId} onCancel={(event) => { event.preventDefault(); onClose(); }} onClose={onClose}>
      <div className={styles.heading}>
        <h2 id={titleId}>{title}</h2>
        <button className={styles.close} type="button" aria-label="Close dialog" onClick={onClose}><Icon name="close" /></button>
      </div>
      <div className={styles.body}>{children}</div>
      <div className={styles.actions}>
        <Button variant="secondary" onClick={onClose}>{cancelLabel}</Button>
        <Button variant={balancedActions ? "secondary" : destructive ? "dark" : "primary"} status={busy ? "loading" : "idle"} statusLabel={busy ? `${confirmLabel}…` : undefined} onClick={onConfirm}>{confirmLabel}</Button>
      </div>
    </dialog>
  );
}
