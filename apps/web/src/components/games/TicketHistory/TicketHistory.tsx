import { Amount } from "@/components/ui/Amount/Amount";
import { Icon } from "@/components/ui/Icon/Icon";
import { formatWatTimestamp } from "@/lib/date";
import { formatKobo } from "@/lib/money";
import type { BlackRedSettlement } from "@/mocks/blackred";
import styles from "./TicketHistory.module.css";

export function TicketHistory({ tickets }: { tickets: BlackRedSettlement[] }) {
  return (
    <section className={styles.history} aria-labelledby="ticket-history-title">
      <header>
        <div><p>Game activity</p><h2 id="ticket-history-title">BlackRed tickets</h2></div>
        <span>{tickets.length} {tickets.length === 1 ? "ticket" : "tickets"}</span>
      </header>
      <div>
        {tickets.map((ticket) => (
          <a className={styles.row} href={`/games/blackred/ticket/${ticket.reference}`} key={ticket.reference}>
            <span className={styles.icon}><Icon name="games" size="navigation" /></span>
            <span className={styles.details}>
              <strong>BlackRed · {ticket.prediction.length} {ticket.prediction.length === 1 ? "position" : "positions"}</strong>
              <span>{ticket.reference}</span>
              <time dateTime={ticket.purchasedAt}>{formatWatTimestamp(ticket.purchasedAt)}</time>
            </span>
            <span className={styles.summary}>
              <Amount amountKobo={ticket.stakeKobo} tone="neutral" size="strong" />
              <span data-outcome={ticket.won ? "win" : "loss"}><Icon name={ticket.won ? "check" : "close"} />{ticket.won ? `Won ${formatKobo(ticket.netCreditKobo)}` : "Not a win"}</span>
            </span>
            <Icon className={styles.chevron} name="chevron-right" />
          </a>
        ))}
      </div>
    </section>
  );
}
