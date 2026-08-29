"use client";

import { useEffect, useRef } from "react";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./ErrorSummary.module.css";

interface ErrorSummaryProps { errors: Array<{ fieldId: string; message: string }>; }

export function ErrorSummary({ errors }: ErrorSummaryProps) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => { if (errors.length) ref.current?.focus(); }, [errors]);
  if (!errors.length) return null;
  return (
    <div className={styles.summary} ref={ref} role="alert" tabIndex={-1}>
      <Icon name="error" />
      <div><h2>Check the details below</h2><ul>{errors.map((error) => <li key={error.fieldId}><a href={`#${error.fieldId}`}>{error.message}</a></li>)}</ul></div>
    </div>
  );
}
