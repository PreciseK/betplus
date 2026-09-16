"use client";

import { useEffect, useState } from "react";
import { blackRedGateway } from "@betplus/api-client";
import { PredictionSequence } from "@/components/games/PredictionSequence/PredictionSequence";
import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { Amount } from "@/components/ui/Amount/Amount";
import { Button } from "@/components/ui/Button/Button";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { formatWatTimestamp } from "@/lib/date";
import { formatKobo } from "@/lib/money";
import { formatMultiplier, formatProbability, type BlackRedSettlement } from "@/mocks/blackred";
import styles from "./page.module.css";

export function BlackRedTicketClient({ reference }: { reference: string }) {
  const [ticket, setTicket] = useState<BlackRedSettlement>();
  const [loadFailed, setLoadFailed] = useState(false);

  useEffect(() => {
    let active = true;
    blackRedGateway
      .revealTicket(reference)
      .then((result) => {
        if (active) setTicket(result);
      })
      .catch(() => {
        if (active) setLoadFailed(true);
      });
    return () => {
      active = false;
    };
  }, [reference]);

  if (loadFailed) {
    return (
      <PlayerPage eyebrow="BlackRed ticket" title="Ticket not found" description="">
        <InlineMessage tone="error" title="Ticket not found">
          This ticket reference could not be found or has not settled yet.
        </InlineMessage>
      </PlayerPage>
    );
  }
  if (!ticket) {
    return (
      <PlayerPage eyebrow="BlackRed ticket" title="Loading ticket" description="">
        <InlineMessage tone="info" title="Loading ticket">
          Retrieving this ticket's settled result.
        </InlineMessage>
      </PlayerPage>
    );
  }

  return (
    <PlayerPage eyebrow="BlackRed ticket" title={ticket.won ? "Winning ticket" : "Settled ticket"} description={`Reference ${ticket.reference}`}>
      <article className={styles.receipt}>
        <InlineMessage tone={ticket.won ? "success" : "info"} title={ticket.won ? "Winnings Balance credited" : "Not a winning ticket"}>
          {ticket.won ? `The net prize is ${formatKobo(ticket.netCreditKobo)}.` : "The ticket settled on its predetermined result."}
        </InlineMessage>

        <section aria-labelledby="ticket-outcome-title">
          <h2 id="ticket-outcome-title">Prediction and result</h2>
          <div className={styles.comparison}>
            <PredictionSequence label="Your prediction" sequence={ticket.prediction} />
            <PredictionSequence label="Result" sequence={ticket.result} />
          </div>
        </section>

        <section aria-labelledby="ticket-breakdown-title">
          <h2 id="ticket-breakdown-title">Ticket breakdown</h2>
          <dl className={styles.summary}>
            <div><dt>Reference</dt><dd>{ticket.reference}</dd></div>
            <div><dt>Purchased</dt><dd><time dateTime={ticket.purchasedAt}>{formatWatTimestamp(ticket.purchasedAt)}</time></dd></div>
            <div><dt>Stake</dt><dd><Amount amountKobo={ticket.stakeKobo} tone="neutral" /></dd></div>
            <div><dt>True win probability</dt><dd>{formatProbability(ticket.tier)} · 1 in {ticket.tier.probabilityDenominator}</dd></div>
            <div><dt>Prize multiplier</dt><dd>{formatMultiplier(ticket.tier)}</dd></div>
            <div><dt>Gross prize</dt><dd><Amount amountKobo={ticket.grossPrizeKobo} tone="neutral" /></dd></div>
            <div><dt>Tax rate and basis</dt><dd>{ticket.taxRateBasisPoints / 100}% of {ticket.taxBasisLabel.toLowerCase()}</dd></div>
            <div><dt>Tax withheld</dt><dd><Amount amountKobo={ticket.taxWithheldKobo} tone="neutral" /></dd></div>
            <div><dt>Net credited</dt><dd><Amount amountKobo={ticket.netCreditKobo} tone={ticket.won ? "positive" : "neutral"} size="strong" /></dd></div>
            <div><dt>State rules</dt><dd>{ticket.stateName} · {ticket.rulesetVersion}</dd></div>
          </dl>
        </section>

        <section aria-labelledby="ticket-proof-title">
          <h2 id="ticket-proof-title">Recorded game versions</h2>
          <p>The ticket remains tied to the versions in force at purchase so the outcome and tax can be reproduced.</p>
          <dl className={styles.summary}>
            <div><dt>Prize table</dt><dd>{ticket.prizeTableVersion}</dd></div>
            <div><dt>Game engine</dt><dd>{ticket.engineVersion}</dd></div>
            <div><dt>Tax rules</dt><dd>{ticket.rulesetVersion}</dd></div>
          </dl>
        </section>

        <div className={styles.actions}>
          <Button href={`/account/help/${ticket.reference}`} variant="secondary">Get help with this ticket</Button>
          <Button href="/games">Back to games</Button>
        </div>
      </article>
    </PlayerPage>
  );
}
