import { notFound } from "next/navigation";
import { Amount } from "@/components/ui/Amount/Amount";
import { Button } from "@/components/ui/Button/Button";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { formatWatTimestamp } from "@/lib/date";
import { getMockReceipt, MOCK_TRANSACTION_REFERENCES } from "@/mocks/wallet";
import styles from "./page.module.css";

export const dynamicParams = false;

export function generateStaticParams() {
  return MOCK_TRANSACTION_REFERENCES.map((reference) => ({ reference }));
}

export default async function ReceiptPage({ params }: { params: Promise<{ reference: string }> }) {
  const { reference } = await params;
  const transaction = getMockReceipt(reference);
  if (!transaction) notFound();

  const isPending = transaction.status === "pending";

  return (
    <PlayerPage eyebrow="Transaction receipt" title={transaction.type} description={`Reference ${transaction.reference}`}>
      <article className={styles.receipt} aria-labelledby="receipt-summary-title">
        <InlineMessage tone={isPending ? "warning" : "success"} title={isPending ? "Confirmation pending" : "Payment confirmed"}>
          {isPending
            ? "This remains in your history while Betplus waits for OPay. Do not repeat the collection unless the status changes to failed or reversed."
            : "OPay confirmed this collection and the amount was credited to Play Balance."}
        </InlineMessage>

        <section aria-labelledby="receipt-summary-title">
          <h2 id="receipt-summary-title">Receipt summary</h2>
          <dl className={styles.summary}>
            <div><dt>Reference</dt><dd>{transaction.reference}</dd></div>
            <div><dt>Date and time</dt><dd><time dateTime={transaction.occurredAt}>{formatWatTimestamp(transaction.occurredAt)}</time></dd></div>
            <div><dt>Provider</dt><dd>{transaction.provider}</dd></div>
            <div><dt>Destination</dt><dd>Play Balance</dd></div>
            <div><dt>Amount</dt><dd><Amount amountKobo={transaction.amountKobo} tone="neutral" /></dd></div>
            <div><dt>Fee</dt><dd><Amount amountKobo={transaction.feeKobo} tone="neutral" /></dd></div>
            <div><dt>Total</dt><dd><Amount amountKobo={transaction.amountKobo + transaction.feeKobo} tone="neutral" size="strong" /></dd></div>
            <div><dt>Status</dt><dd>{isPending ? "Pending" : "Paid"}</dd></div>
          </dl>
        </section>

        <section aria-labelledby="status-history-title">
          <h2 id="status-history-title">Status history</h2>
          <ol className={styles.timeline}>
            {transaction.statusHistory.map((event) => (
              <li key={`${event.status}-${event.at}`}>
                <span aria-hidden="true" />
                <div><strong>{event.status}</strong><time dateTime={event.at}>{formatWatTimestamp(event.at)}</time><p>{event.detail}</p></div>
              </li>
            ))}
          </ol>
        </section>

        <div className={styles.actions}>
          <Button href={`/account/help/${transaction.reference}`} variant="secondary">Get help with this transaction</Button>
          <Button href="/activity">Back to activity</Button>
        </div>
      </article>
    </PlayerPage>
  );
}
