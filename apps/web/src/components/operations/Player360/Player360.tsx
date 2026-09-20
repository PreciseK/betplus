"use client";

import { type FormEvent, type ReactNode, useState } from "react";
import { backOfficeGateway, BackOfficeApiError } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Icon } from "@/components/ui/Icon/Icon";
import { TextField } from "@/components/ui/TextField/TextField";
import { EmptyState } from "@/components/operations/EmptyState/EmptyState";
import { formatKobo } from "@/lib/money";
import styles from "./Player360.module.css";

export type Player360View = "case-resolution" | "tickets" | "payments" | "notifications" | "responsible-play";

const VIEW_COPY: Record<Player360View, { title: string; description: string }> = {
  "case-resolution": { title: "Player overview", description: "Profile, KYC status and balances in one place." },
  tickets: { title: "Game tickets", description: "Submitted stakes and outcomes." },
  payments: { title: "Payments", description: "Deposit and payout history." },
  notifications: { title: "Notifications", description: "Delivery status for messages sent to this player." },
  "responsible-play": { title: "Responsible play", description: "Registry status and active protection." },
};

type PlayerRecord = Awaited<ReturnType<typeof backOfficeGateway.player>>;

export function Player360({ view = "case-resolution" }: { view?: Player360View }) {
  const [query, setQuery] = useState("");
  const [lastSearch, setLastSearch] = useState("");
  const [player, setPlayer] = useState<PlayerRecord>();
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState("");
  const [isLoading, setIsLoading] = useState(false);

  async function searchPlayer(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!query.trim()) return;
    setLastSearch(query.trim());
    setNotFound(false);
    setError("");
    setIsLoading(true);
    try {
      const result = await backOfficeGateway.player(query.trim());
      setPlayer(result);
    } catch (err) {
      setPlayer(undefined);
      if (err instanceof BackOfficeApiError && err.status === 404) {
        setNotFound(true);
      } else {
        setError("Could not load that player. Please try again.");
      }
    } finally {
      setIsLoading(false);
    }
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Support task</p>
          <h1>{VIEW_COPY[view].title}</h1>
          <p>{VIEW_COPY[view].description}</p>
        </div>
        <form className={styles.search} role="search" onSubmit={searchPlayer}>
          <TextField
            id="player-reference"
            type="search"
            label="Player ID"
            placeholder="Example: 42"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
          <Button type="submit" disabled={isLoading}>{isLoading ? "Loading…" : "Open player"}</Button>
        </form>
      </header>

      {error && <p className={styles.fieldError} role="alert"><Icon name="error" />{error}</p>}

      {!player && !notFound && !error && (
        <EmptyState
          icon="account"
          title="Find a player record"
          description="Enter a numeric player ID above to inspect profile data, KYC verifications, wallet balances, ticket logs, and payment receipts."
        />
      )}

      {notFound && (
        <EmptyState
          icon="account"
          title={`No player found for "${lastSearch}"`}
          description="Search by numeric player ID. Phone numbers, names and raw identity numbers are not accepted."
        />
      )}

      {player && (
        <>
          <section className={styles.identityBand} aria-labelledby="player-name">
            <div className={styles.identityPrimary}>
              <span className={styles.avatar} aria-hidden="true">{(player.profile.display_name ?? player.profile.registered_name ?? "Player").slice(0, 2).toUpperCase()}</span>
              <div>
                <p>#{player.profile.id} · {player.profile.account_status}</p>
                <h2 id="player-name">{player.profile.display_name ?? player.profile.registered_name ?? `Player #${player.profile.id}`}</h2>
                <span>{player.profile.msisdn}</span>
              </div>
            </div>
            <dl className={styles.identityFacts}>
              <div><dt>KYC</dt><dd><Icon name="check" />Tier {player.profile.kyc_tier}{player.profile.kyc_status ? ` · ${player.profile.kyc_status}` : ""}</dd></div>
              <div><dt>NIN verified</dt><dd>{player.profile.has_verified_nin ? "Yes" : "No"}</dd></div>
              <div><dt>BVN verified</dt><dd>{player.profile.has_verified_bvn ? "Yes" : "No"}</dd></div>
              <div><dt>Joined</dt><dd>{player.profile.created_at ? new Date(player.profile.created_at).toLocaleDateString("en-NG") : "—"}</dd></div>
            </dl>
            <div className={styles.balances} aria-label="Player balances">
              <div><span>Play Balance</span><strong>{formatKobo(player.balances.play_balance_kobo)}</strong></div>
              <div><span>Winnings Balance</span><strong>{formatKobo(player.balances.winnings_balance_kobo)}</strong></div>
              <div><span>Bonus Balance</span><strong>{formatKobo(player.balances.bonus_balance_kobo ?? 0)}</strong></div>
            </div>
          </section>

          <div className={styles.workspace} data-view={view}>
            <div className={styles.evidenceStream}>
              {view === "case-resolution" && (
                <section className={styles.identityDetail} aria-labelledby="kyc-title">
                  <div>
                    <h2 id="kyc-title">Identity and KYC records</h2>
                    <p><Icon name="lock" />Raw NIN/BVN are never returned by this view — only verification status.</p>
                  </div>
                  {player.kyc_records.length === 0 ? (
                    <EmptyState
                      icon="account"
                      title="No KYC records on file"
                      description="No identity verification documents have been submitted or approved for this player."
                    />
                  ) : (
                    <dl>
                      {player.kyc_records.map((record, index) => (
                        <div key={`${record.id_type}-${index}`}>
                          <dt>{record.id_type.toUpperCase()}</dt>
                          <dd>{record.verification_method} · {record.verified_at ? `verified ${new Date(record.verified_at).toLocaleDateString("en-NG")}` : "not verified"}{record.opay_name_match !== null ? ` · name match: ${record.opay_name_match ? "yes" : "no"}` : ""}</dd>
                        </div>
                      ))}
                    </dl>
                  )}
                </section>
              )}

              {view === "tickets" && (
                <EvidenceSection id="tickets" title="Ticket history" description="Every submitted stake and its settled outcome.">
                  {player.tickets.length === 0 ? (
                    <EmptyState
                      icon="ticket"
                      title="No tickets yet"
                      description="This player has not placed any game stakes yet."
                    />
                  ) : (
                    <div className={styles.tableWrap}>
                      <table className={styles.table}>
                        <caption className="sr-only">Player ticket history</caption>
                        <thead><tr><th scope="col">Reference</th><th scope="col">Game</th><th scope="col">Status</th><th scope="col" className={styles.money}>Stake</th><th scope="col" className={styles.money}>Net</th></tr></thead>
                        <tbody>{player.tickets.map((ticket) => (
                          <tr key={ticket.reference}>
                            <th scope="row" data-label="Reference">{ticket.reference}</th>
                            <td data-label="Game">{ticket.game_code}</td>
                            <td data-label="Status"><Icon name={ticket.won ? "check" : "info"} />{ticket.won === null ? ticket.status : ticket.won ? "Won" : "Lost"}</td>
                            <td data-label="Stake" className={styles.money}>{formatKobo(ticket.stake_kobo)}</td>
                            <td data-label="Net" className={styles.money}>{ticket.net_credit_kobo !== null ? formatKobo(ticket.net_credit_kobo) : "—"}</td>
                          </tr>
                        ))}</tbody>
                      </table>
                    </div>
                  )}
                </EvidenceSection>
              )}

              {view === "payments" && (
                <EvidenceSection id="payments" title="Payment history" description="Deposits and payouts, most recent first.">
                  {player.payments.deposits.length === 0 && player.payments.payouts.length === 0 ? (
                    <EmptyState
                      icon="wallet"
                      title="No payments recorded"
                      description="No deposit or withdrawal transactions have been processed for this player."
                    />
                  ) : (
                    <div className={styles.tableWrap}>
                      <table className={styles.table}>
                        <caption className="sr-only">Player deposit and payout history</caption>
                        <thead><tr><th scope="col">Type</th><th scope="col">Reference</th><th scope="col">Status</th><th scope="col" className={styles.money}>Amount</th></tr></thead>
                        <tbody>
                          {player.payments.deposits.map((deposit) => (
                            <tr key={deposit.reference}>
                              <td data-label="Type">Deposit</td>
                              <th scope="row" data-label="Reference">{deposit.reference}</th>
                              <td data-label="Status">{deposit.status}</td>
                              <td data-label="Amount" className={styles.money}>{formatKobo(deposit.amount_kobo)}</td>
                            </tr>
                          ))}
                          {player.payments.payouts.map((payout) => (
                            <tr key={payout.reference}>
                              <td data-label="Type">{payout.kind === "automatic-prize" ? "Prize payout" : "Withdrawal"}</td>
                              <th scope="row" data-label="Reference">{payout.reference}</th>
                              <td data-label="Status">{payout.provider_status}</td>
                              <td data-label="Amount" className={styles.money}>{formatKobo(payout.amount_kobo)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </EvidenceSection>
              )}

              {view === "notifications" && (
                <EvidenceSection id="notifications" title="Notification delivery history" description="Every message sent to this player's number.">
                  {player.notification_history.length === 0 ? (
                    <EmptyState
                      icon="info"
                      title="No notifications sent"
                      description="No SMS or transaction alert messages have been dispatched to this player."
                    />
                  ) : (
                    <ul className={styles.notificationList}>
                      {player.notification_history.map((notification, index) => (
                        <li key={`${notification.category}-${index}`}>
                          <Icon name={notification.status === "delivered" || notification.status === "sent" ? "check" : "warning"} />
                          <div><strong>{notification.category}</strong></div>
                          <div><strong>{notification.status}</strong><span>{new Date(notification.queued_at).toLocaleString("en-NG")}</span></div>
                        </li>
                      ))}
                    </ul>
                  )}
                </EvidenceSection>
              )}

              {view === "responsible-play" && (
                <EvidenceSection id="responsible-play" title="Responsible-play status" description="Registry result and active player-protection remain visible to support.">
                  <dl>
                    <div><dt>Registry state</dt><dd><Icon name={player.rg_status.registry_status === "clear" ? "check" : "warning"} />{player.rg_status.registry_status}</dd></div>
                    <div><dt>Active protection</dt><dd>{player.rg_status.protection ? `${player.rg_status.protection.type} until ${player.rg_status.protection.ends_at ? new Date(player.rg_status.protection.ends_at).toLocaleString("en-NG") : "indefinite"}` : "None"}</dd></div>
                  </dl>
                </EvidenceSection>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );
}

function EvidenceSection({ id, title, description, children }: { id: string; title: string; description: string; children: ReactNode }) {
  return (
    <section className={styles.evidenceSection} id={id} aria-labelledby={`${id}-title`}>
      <header><div><h2 id={`${id}-title`}>{title}</h2><p>{description}</p></div><a href="#player-name">Back to player</a></header>
      {children}
    </section>
  );
}
