import type { HTMLAttributes } from "react";
import { formatKobo, type KoboDisplayPrecision } from "@/lib/money";
import styles from "./Amount.module.css";

export type AmountTone = "auto" | "neutral" | "positive" | "negative";
export type AmountSize = "body" | "strong" | "hero";

export interface AmountProps extends HTMLAttributes<HTMLSpanElement> {
  amountKobo: number;
  precision?: KoboDisplayPrecision;
  tone?: AmountTone;
  size?: AmountSize;
  showPositiveSign?: boolean;
  accessibleLabel?: string;
}

export function Amount({
  amountKobo,
  precision = "auto",
  tone = "auto",
  size = "body",
  showPositiveSign = false,
  accessibleLabel,
  className,
  ...props
}: AmountProps) {
  const resolvedTone = tone === "auto"
    ? amountKobo > 0 ? "positive" : amountKobo < 0 ? "negative" : "neutral"
    : tone;
  const formatted = formatKobo(amountKobo, precision);
  const visibleAmount = showPositiveSign && amountKobo > 0 ? `+${formatted}` : formatted;

  return (
    <span
      {...props}
      className={[styles.amount, styles[size], styles[resolvedTone], className].filter(Boolean).join(" ")}
      aria-label={accessibleLabel}
    >
      {visibleAmount}
    </span>
  );
}
