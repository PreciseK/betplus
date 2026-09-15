import { Amount } from "@/components/ui/Amount/Amount";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./BalanceCard.module.css";

export type BalanceKind = "play" | "winnings" | "bonus";
export type BalanceState = "ready" | "hidden" | "loading" | "unavailable";

export interface BalanceCardProps {
  kind: BalanceKind;
  amountKobo?: number;
  state?: BalanceState;
  explanation?: string;
}

const BALANCE_COPY: Record<BalanceKind, { title: string; description: string }> = {
  play: {
    title: "Play Balance",
    description: "Deposited funds available to stake, subject to turnover rules.",
  },
  winnings: {
    title: "Winnings Balance",
    description: "Settled net winnings available under withdrawal rules.",
  },
  bonus: {
    title: "Bonus Balance",
    description: "Promotional credit used first for stakes; 1x playthrough rule converts profit to winnings.",
  },
};

export function BalanceCard({
  kind,
  amountKobo,
  state = "ready",
  explanation,
}: BalanceCardProps) {
  const copy = BALANCE_COPY[kind];
  const resolvedState = state === "ready" && amountKobo === undefined ? "unavailable" : state;

  return (
    <section className={styles.card} aria-labelledby={`${kind}-balance-title`} data-state={resolvedState}>
      <div className={styles.heading}>
        <Icon name={kind === "play" ? "wallet" : kind === "bonus" ? "ticket" : "money"} size="navigation" />
        <div>
          <h2 id={`${kind}-balance-title`}>{copy.title}</h2>
          <p>{explanation ?? copy.description}</p>
        </div>
      </div>

      <div className={styles.value} aria-live="polite" aria-atomic="true">
        {resolvedState === "ready" && amountKobo !== undefined && (
          <Amount amountKobo={amountKobo} size="hero" tone="neutral" accessibleLabel={`${copy.title}: ${formatForSpeech(amountKobo)}`} />
        )}
        {resolvedState === "hidden" && <span className={styles.hidden} aria-label={`${copy.title} hidden`}>••••</span>}
        {resolvedState === "loading" && (
          <span className={styles.stateLabel}><Icon name="loading" size="inline" />Loading balance…</span>
        )}
        {resolvedState === "unavailable" && (
          <span className={styles.stateLabel}><Icon name="warning" size="inline" />Balance unavailable</span>
        )}
      </div>
    </section>
  );
}

function formatForSpeech(amountKobo: number) {
  const absoluteKobo = Math.abs(amountKobo);
  const naira = Math.floor(absoluteKobo / 100).toLocaleString("en-NG");
  const kobo = absoluteKobo % 100;
  const sign = amountKobo < 0 ? "minus " : "";
  return kobo ? `${sign}${naira} Naira and ${kobo} kobo` : `${sign}${naira} Naira`;
}
