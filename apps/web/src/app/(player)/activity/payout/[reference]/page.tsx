import { notFound } from "next/navigation";
import { Amount } from "@/components/ui/Amount/Amount";
import { Button } from "@/components/ui/Button/Button";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { formatWatTimestamp } from "@/lib/date";
import { getMockPayout, PAYOUT_REFERENCES, type PayoutRecord } from "@/mocks/payout";
import styles from "./page.module.css";

export const dynamicParams = false;

export function generateStaticParams() {
  return PAYOUT_REFERENCES.map((reference) => ({ reference }));
}

export default async function PayoutReceiptPage({ params }: { params: Promise<{ reference: string }> }) {
  const { reference } = await params;
  const payout = getMockPayout(reference);
  if (!payout) notFound();
  const status = getReceiptStatus(payout);

  return (
    <PlayerPage eyebrow="Payout receipt" title={payout.kind === "automatic-prize" ? "Prize payout" : "Withdrawal"} description={`Reference ${payout.reference}`}>
      <article className={styles.receipt} aria-labelledby="payout-summary-title">
        <InlineMessage tone={status.tone} title={status.title}>{status.message}</InlineMessage>

        <section aria-labelledby="payout-summary-title">
          <h2 id="payout-summary-title">Payout summary</h2>
          <dl className={styles.summary}>
            <div><dt>Reference</dt><dd>{payout.reference}</dd></div>
            <div><dt>Requested</dt><dd><time dateTime={payout.createdAt}>{formatWatTimestamp(payout.createdAt)}</time></dd></div>
            <div><dt>Action</dt><dd>{payout.kind === "automatic-prize" ? "Automatic prize payout" : "Withdrawal"}</dd></div>
            <div><dt>From</dt><dd>{payout.sourceLabel}</dd></div>
            <div><dt>Amount</dt><dd><Amount amountKobo={payout.amountKobo} tone="neutral" size="strong" /></dd></div>
            <div><dt>Destination</dt><dd>{payout.destinationLabel}</dd></div>
            <div><dt>OPay order number</dt><dd>{payout.opayOrderNumber ?? "Pending"}</dd></div>
            <div><dt>Status</dt><dd>{displayStatusLabel(payout.displayStatus)}</dd></div>
          </dl>
        </section>

        {payout.kind === "automatic-prize" && (
          <section aria-labelledby="prize-breakdown-title">
            <h2 id="prize-breakdown-title">Prize breakdown</h2>
            <dl className={styles.summary}>
              <div><dt>Game</dt><dd>{payout.game}</dd></div>
              <div><dt>Ticket</dt><dd><a href={`/games/blackred/ticket/${payout.ticketReference}`}>{payout.ticketReference}</a></dd></div>
              <div><dt>Gross prize</dt><dd><Amount amountKobo={payout.grossPrizeKobo ?? 0} tone="neutral" /></dd></div>
              <div><dt>Tax rate and basis</dt><dd>{formatBasisPoints(payout.taxRateBasisPoints ?? 0)} of {payout.taxBasisLabel?.toLowerCase()}</dd></div>
              <div><dt>Tax deducted</dt><dd><Amount amountKobo={payout.taxWithheldKobo ?? 0} tone="neutral" /></dd></div>
              <div><dt>Net payout</dt><dd><Amount amountKobo={payout.netPaidKobo ?? payout.amountKobo} tone="neutral" size="strong" /></dd></div>
            </dl>
          </section>
        )}

        <section aria-labelledby="payout-history-title">
          <h2 id="payout-history-title">Status history</h2>
          <ol className={styles.timeline}>
            {payout.statusHistory.map((event) => (
              <li key={`${event.status}-${event.at}`}>
                <span aria-hidden="true" />
                <div><strong>{event.status}</strong><time dateTime={event.at}>{formatWatTimestamp(event.at)}</time><p>{event.detail}</p></div>
              </li>
            ))}
          </ol>
        </section>

        <div className={styles.actions}>
          <Button href={`/account/help/${payout.reference}`} variant="secondary">Get help with this payout</Button>
          <Button href="/activity">Back to activity</Button>
        </div>
      </article>
    </PlayerPage>
  );
}

function displayStatusLabel(status: PayoutRecord["displayStatus"]) {
  return {
    requested: "Requested",
    processing: "Processing",
    paid: "Paid",
    "needs-attention": "Needs attention",
    "manual-review": "Review in progress",
  }[status];
}

function formatBasisPoints(basisPoints: number) {
  const wholePercent = Math.floor(basisPoints / 100);
  const fractionalPercent = basisPoints % 100;
  return fractionalPercent === 0 ? `${wholePercent}%` : `${wholePercent}.${String(fractionalPercent).padStart(2, "0")}%`;
}

function getReceiptStatus(payout: PayoutRecord): { tone: "info" | "success" | "warning"; title: string; message: string } {
  if (payout.displayStatus === "paid") {
    return { tone: "success", title: "OPay confirmed payment", message: `${payout.statusExpectation}. This receipt is the durable record of the transfer.` };
  }
  if (payout.displayStatus === "manual-review") {
    return { tone: "warning", title: "Review in progress — not failed", message: `${payout.statusExpectation} The payout remains protected and linked to this reference.` };
  }
  if (payout.displayStatus === "needs-attention") {
    return { tone: "warning", title: "Transfer needs attention", message: "The transfer was not confirmed. The money remains in, or has returned to, Winnings Balance while support resolves the final status." };
  }
  return { tone: "info", title: payout.displayStatus === "requested" ? "Payout requested" : "Transfer processing", message: `${payout.statusExpectation}${payout.fundsRemainInWinnings ? " The money remains protected in Winnings Balance while the transfer is processing." : ""}` };
}
