import { Amount } from "@/components/ui/Amount/Amount";
import { Icon, type IconName } from "@/components/ui/Icon/Icon";
import { EmptyState } from "@/components/ui/states/EmptyState/EmptyState";
import { formatWatTimestamp } from "@/lib/date";
import type { PayoutDisplayStatus, PayoutRecord } from "@/mocks/payout";
import styles from "./PayoutHistory.module.css";

const STATUS_PRESENTATION: Record<PayoutDisplayStatus, { label: string; icon: IconName }> = {
  requested: { label: "Requested", icon: "info" },
  processing: { label: "Processing", icon: "loading" },
  paid: { label: "Paid", icon: "check" },
  "needs-attention": { label: "Needs attention", icon: "warning" },
  "manual-review": { label: "Review in progress", icon: "support" },
};

export function PayoutHistory({ payouts }: { payouts: PayoutRecord[] }) {
  return (
    <section className={styles.history} aria-labelledby="payout-history-title">
      <header>
        <div><p>OPay payouts</p><h2 id="payout-history-title">Payout history</h2></div>
        <span>{payouts.length} {payouts.length === 1 ? "payout" : "payouts"}</span>
      </header>
      {payouts.length === 0 ? (
        <EmptyState title="No payouts yet" description="Prize payouts and withdrawals will appear here with a reference and durable status." />
      ) : (
        <div>
          {payouts.map((payout) => {
            const status = STATUS_PRESENTATION[payout.displayStatus];
            return (
              <a className={styles.row} href={`/activity/payout/${payout.reference}`} key={payout.reference}>
                <span className={styles.icon}><Icon name="wallet" size="navigation" /></span>
                <span className={styles.details}>
                  <strong>{payout.kind === "automatic-prize" ? "Automatic prize payout" : "Withdrawal"}</strong>
                  <span>{payout.reference}</span>
                  <time dateTime={payout.createdAt}>{formatWatTimestamp(payout.createdAt)}</time>
                </span>
                <span className={styles.summary}>
                  <Amount amountKobo={payout.amountKobo} tone="neutral" size="strong" />
                  <span data-status={payout.displayStatus}><Icon name={status.icon} />{status.label}</span>
                </span>
                <Icon className={styles.chevron} name="chevron-right" />
              </a>
            );
          })}
        </div>
      )}
    </section>
  );
}
