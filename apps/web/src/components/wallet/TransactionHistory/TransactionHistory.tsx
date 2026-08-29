import { TransactionRow } from "@/components/ui/TransactionRow/TransactionRow";
import { EmptyState } from "@/components/ui/states/EmptyState/EmptyState";
import { formatWatTimestamp } from "@/lib/date";
import type { MoneyTransaction } from "@/mocks/wallet";
import styles from "./TransactionHistory.module.css";

export function TransactionHistory({ transactions }: { transactions: MoneyTransaction[] }) {
  return (
    <section className={styles.history} aria-labelledby="transaction-history-title">
      <header>
        <div>
          <p>Money activity</p>
          <h2 id="transaction-history-title">Transaction history</h2>
        </div>
        <span>{transactions.length} {transactions.length === 1 ? "transaction" : "transactions"}</span>
      </header>
      {transactions.length === 0 ? (
        <EmptyState title="No money activity yet" description="Deposits and other money movements will appear here with durable status updates." />
      ) : (
        <div>
          {transactions.map((transaction) => (
            <TransactionRow
              key={transaction.reference}
              type={transaction.type}
              source={transaction.provider}
              timestamp={transaction.occurredAt}
              timestampLabel={formatWatTimestamp(transaction.occurredAt)}
              amountKobo={transaction.amountKobo}
              status={transaction.status}
              href={`/activity/receipt/${transaction.reference}`}
            />
          ))}
        </div>
      )}
    </section>
  );
}
