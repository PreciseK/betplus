import { Amount } from "@/components/ui/Amount/Amount";
import { Icon, type IconName } from "@/components/ui/Icon/Icon";
import styles from "./TransactionRow.module.css";

export type TransactionStatus = "pending" | "paid" | "failed" | "reversed";

export interface TransactionRowProps {
  type: string;
  source: string;
  timestamp: string;
  timestampLabel: string;
  amountKobo: number;
  status: TransactionStatus;
  href?: string;
}

const STATUS_COPY: Record<TransactionStatus, { label: string; icon: IconName }> = {
  pending: { label: "Pending", icon: "loading" },
  paid: { label: "Paid", icon: "check" },
  failed: { label: "Failed", icon: "error" },
  reversed: { label: "Reversed", icon: "arrow-left" },
};

export function TransactionRow({
  type,
  source,
  timestamp,
  timestampLabel,
  amountKobo,
  status,
  href,
}: TransactionRowProps) {
  const statusCopy = STATUS_COPY[status];
  const content = (
    <>
      <span className={styles.iconWrap}><Icon name="ticket" size="navigation" /></span>
      <span className={styles.details}>
        <strong>{type}</strong>
        <span>{source}</span>
        <time dateTime={timestamp}>{timestampLabel}</time>
      </span>
      <span className={styles.summary}>
        <Amount amountKobo={amountKobo} size="strong" showPositiveSign />
        <span className={styles.status} data-status={status}>
          <Icon name={statusCopy.icon} size="inline" />{statusCopy.label}
        </span>
      </span>
      {href && <Icon className={styles.chevron} name="chevron-right" size="inline" />}
    </>
  );

  return href ? <a className={styles.row} href={href}>{content}</a> : <div className={styles.row}>{content}</div>;
}
