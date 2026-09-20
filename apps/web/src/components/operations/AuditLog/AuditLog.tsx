"use client";

import { useEffect, useMemo, useState } from "react";
import { backOfficeGateway, type BackOfficeAuditEvent } from "@betplus/api-client";
import { Icon } from "@/components/ui/Icon/Icon";
import { TextField } from "@/components/ui/TextField/TextField";
import { EmptyState } from "@/components/operations/EmptyState/EmptyState";
import styles from "./AuditLog.module.css";

/** Real field-by-field diff from the stored before/after snapshots — no fabricated risk score. */
function diffFields(before: Record<string, unknown> | null, after: Record<string, unknown> | null) {
  const keys = new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})]);
  return [...keys].map((field) => ({
    field,
    before: before && field in before ? JSON.stringify(before[field]) : "—",
    after: after && field in after ? JSON.stringify(after[field]) : "—",
  }));
}

function eventMatches(event: BackOfficeAuditEvent, query: string) {
  const searchable = [
    event.action,
    event.actor_id,
    event.target_table,
    event.target_id,
    event.ip_address,
  ].join(" ").toLocaleLowerCase();

  return searchable.includes(query.toLocaleLowerCase());
}

export function AuditLog() {
  const [events, setEvents] = useState<BackOfficeAuditEvent[]>([]);
  const [loadFailed, setLoadFailed] = useState(false);
  const [query, setQuery] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [expandedId, setExpandedId] = useState<number | null>(null);

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    let active = true;
    backOfficeGateway
      .auditLog({ date_from: dateFrom || undefined, date_to: dateTo || undefined })
      .then((result) => {
        if (active) setEvents(result.events);
      })
      .catch(() => {
        if (active) setLoadFailed(true);
      });
    return () => { active = false; };
  }, [dateFrom, dateTo]);

  const visibleEvents = useMemo(
    () => events.filter((event) => eventMatches(event, query.trim())),
    [events, query],
  );

  if (loadFailed) {
    return (
      <div className={styles.page}>
        <EmptyState
          icon="error"
          title="Could not load audit log"
          description="Live audit data could not be loaded from the back-office gateway. Sign in again if this persists."
        />
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Security evidence</p>
          <h1>Audit log</h1>
          <p>Read-only operator actions, recorded at the point of decision with the before/after evidence.</p>
        </div>
      </header>

      <section className={styles.integrityStrip} aria-labelledby="integrity-title">
        <Icon name="lock" />
        <div>
          <h2 id="integrity-title">Immutable at the database level</h2>
          <p>A DB trigger refuses any UPDATE or DELETE against this table. There is no hash chain and no off-server copy yet — that infra is not built.</p>
        </div>
        <span>Read only</span>
      </section>

      <section className={styles.logSection} aria-labelledby="events-title">
        <div className={styles.sectionHeading}>
          <div>
            <h2 id="events-title">Operator events</h2>
            <p>Showing {visibleEvents.length} of {events.length} events</p>
          </div>
        </div>

        <div className={styles.filters} aria-label="Audit log filters">
          <TextField
            id="audit-search"
            type="search"
            label="Search events"
            placeholder="Actor, action, table or IP"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
          <label className={styles.selectField} htmlFor="audit-from">
            <span>From</span>
            <input id="audit-from" type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} />
          </label>
          <label className={styles.selectField} htmlFor="audit-to">
            <span>To</span>
            <input id="audit-to" type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
          </label>
        </div>

        {visibleEvents.length > 0 ? (
          <div className={styles.tableWrap}>
            <table className={styles.table} aria-labelledby="events-title">
              <caption className="sr-only">Immutable operator audit events with expandable before/after evidence</caption>
              <thead>
                <tr>
                  <th scope="col">Timestamp</th>
                  <th scope="col">Actor</th>
                  <th scope="col">Action</th>
                  <th scope="col">Target</th>
                  <th scope="col"><span className="sr-only">Evidence</span></th>
                </tr>
              </thead>
              <tbody>
                {visibleEvents.map((event) => {
                  const expanded = expandedId === event.id;
                  const evidenceId = `audit-${event.id}-evidence`;
                  const diff = diffFields(event.before, event.after);
                  return (
                    <>
                      <tr className={styles.eventRow} key={event.id}>
                        <td data-label="Timestamp"><time dateTime={event.created_at}>{new Date(event.created_at).toLocaleString()}</time></td>
                        <td data-label="Actor"><strong>{event.actor_type}</strong><span>#{event.actor_id ?? "—"}</span></td>
                        <th scope="row" data-label="Action"><strong>{event.action}</strong></th>
                        <td data-label="Target"><span>{event.target_table ?? "—"} {event.target_id ? `#${event.target_id}` : ""}</span></td>
                        <td className={styles.evidenceCell}>
                          <button type="button" aria-expanded={expanded} aria-controls={evidenceId} onClick={() => setExpandedId(expanded ? null : event.id)}>
                            {expanded ? "Hide" : "View"} evidence <span aria-hidden="true">{expanded ? "−" : "+"}</span>
                          </button>
                        </td>
                      </tr>
                      {expanded && (
                        <tr className={styles.evidenceRow} key={`${event.id}-evidence`}>
                          <td colSpan={5} id={evidenceId}>
                            <div className={styles.evidence}>
                              <div className={styles.evidenceMeta}>
                                <dl>
                                  <div><dt>Source IP</dt><dd>{event.ip_address ?? "—"}</dd></div>
                                  <div><dt>User agent</dt><dd>{event.user_agent ?? "—"}</dd></div>
                                  <div><dt>Reason</dt><dd>{event.reason ?? "Not recorded for this action"}</dd></div>
                                </dl>
                              </div>
                              <div className={styles.changeSet}>
                                <h3>Before and after</h3>
                                {diff.length > 0 ? (
                                  <>
                                    <div className={styles.changeHeader} aria-hidden="true"><span>Field</span><span>Before</span><span>After</span></div>
                                    {diff.map((row) => (
                                      <div className={styles.change} key={row.field}>
                                        <strong>{row.field}</strong>
                                        <span data-label="Before">{row.before}</span>
                                        <span data-label="After">{row.after}</span>
                                      </div>
                                    ))}
                                  </>
                                ) : (
                                  <p className={styles.muted}>No before/after snapshot recorded for this action.</p>
                                )}
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                    </>
                  );
                })}
              </tbody>
            </table>
          </div>
        ) : (
          <EmptyState
            icon="info"
            title="No matching audit events"
            description="Try another search term or date range."
          />
        )}
      </section>
    </div>
  );
}
