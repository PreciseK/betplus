"use client";

import { useEffect, useMemo, useState } from "react";
import { backOfficeGateway, type BackOfficeReconciliationException } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { Icon } from "@/components/ui/Icon/Icon";
import { formatKobo } from "@/lib/money";
import styles from "@/components/operations/OperationsConsole.module.css";

type StatusFilter = "open" | "resolved";

function titleCase(value: string) {
  return value.replaceAll("_", " ").replace(/\b\w/g, (character) => character.toUpperCase());
}

export function ReconciliationConsole() {
  const [status, setStatus] = useState<StatusFilter>("open");
  const [exceptions, setExceptions] = useState<BackOfficeReconciliationException[]>();
  const [selectedId, setSelectedId] = useState<number>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [resolveOpen, setResolveOpen] = useState(false);
  const [receipt, setReceipt] = useState("");

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    let active = true;
    backOfficeGateway
      .reconciliation(status)
      .then((result) => {
        if (!active) return;
        setExceptions(result.exceptions);
        setSelectedId((current) => (current !== undefined && result.exceptions.some((e) => e.id === current) ? current : result.exceptions[0]?.id));
      })
      .catch(() => {
        if (active) setLoadFailed(true);
      });
    return () => { active = false; };
  }, [status]);

  const selected = useMemo(() => exceptions?.find((exception) => exception.id === selectedId), [exceptions, selectedId]);

  async function resolveSelected() {
    if (!selected) return;
    try {
      await backOfficeGateway.resolveReconciliation(selected.id);
      setExceptions((current) => current?.filter((exception) => exception.id !== selected.id));
      setResolveOpen(false);
      setReceipt(`Exception #${selected.id} marked resolved.`);
    } catch {
      setResolveOpen(false);
      setReceipt("Could not resolve that exception. Please try again.");
    }
  }

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live reconciliation data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Finance task</p>
          <h1>Reconciliation exceptions</h1>
          <p>Investigate only the records that did not reconcile against the nightly ledger check.</p>
        </div>
      </header>

      {receipt && <div className={styles.receipt} role="status"><Icon name="check" />{receipt}</div>}

      <section className={styles.section} aria-labelledby="exception-queue-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="exception-queue-title">Exception queue</h2>
            <p>{exceptions?.length ?? 0} records in the current scope.</p>
          </div>
          <div className={styles.controls}>
            <label className={styles.selectField} htmlFor="reconciliation-status">
              Status
              <select id="reconciliation-status" value={status} onChange={(event) => setStatus(event.target.value as StatusFilter)}>
                <option value="open">Open</option>
                <option value="resolved">Resolved</option>
              </select>
            </label>
          </div>
        </header>

        {exceptions === undefined ? (
          <p className={styles.muted}>Loading…</p>
        ) : exceptions.length > 0 ? (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Reconciliation exceptions requiring finance action</caption>
              <thead><tr><th scope="col">Exception</th><th scope="col">Check</th><th scope="col">Subject</th><th scope="col">Severity</th><th scope="col" className={styles.money}>Difference</th><th scope="col">Action</th></tr></thead>
              <tbody>{exceptions.map((exception) => (
                <tr key={exception.id}>
                  <th scope="row" data-label="Exception"><strong>#{exception.id}</strong><span>{exception.detected_at}</span></th>
                  <td data-label="Check">{titleCase(exception.check_type)}</td>
                  <td data-label="Subject">{exception.subject_id}</td>
                  <td data-label="Severity"><span className={styles.statusLabel}><Icon name={exception.severity === "p0" ? "error" : "warning"} />{exception.severity.toUpperCase()}</span></td>
                  <td data-label="Difference" className={styles.money}>{formatKobo(exception.difference_kobo)}</td>
                  <td data-label="Action"><button className={styles.tableAction} type="button" onClick={() => setSelectedId(exception.id)}>Inspect</button></td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : (
          <div className={styles.emptyState}>
            <h3>No exceptions in this status</h3>
            <p>Change the status filter to see other records.</p>
          </div>
        )}
      </section>

      {selected && (
        <article className={styles.inspector} aria-labelledby="exception-title">
          <header className={styles.inspectorHeader}>
            <div><p>#{selected.id} · {titleCase(selected.check_type)}</p><h2 id="exception-title">Subject {selected.subject_id}</h2></div>
            <span className={styles.statusLabel}><Icon name="warning" />{titleCase(selected.status)}</span>
          </header>

          <div className={styles.evidenceGrid}>
            <section className={styles.evidenceBlock} aria-labelledby="ledger-evidence-title">
              <h3 id="ledger-evidence-title">Ledger discrepancy</h3>
              <dl>
                <Fact label="Check type" value={titleCase(selected.check_type)} />
                <Fact label="Expected" value={formatKobo(selected.expected_kobo)} />
                <Fact label="Actual" value={formatKobo(selected.actual_kobo)} />
                <Fact label="Difference" value={formatKobo(selected.difference_kobo)} />
                <Fact label="Detected" value={selected.detected_at} />
                {selected.resolved_at && <Fact label="Resolved" value={selected.resolved_at} />}
              </dl>
            </section>
          </div>

          {selected.status === "open" && (
            <section className={styles.actionBand} aria-labelledby="resolve-title">
              <div><h2 id="resolve-title">Mark resolved</h2><p>Marks this exception resolved once the underlying discrepancy has been investigated and corrected outside this screen. This does not itself move any money — there is no compensating-entry workflow wired to this action.</p></div>
              <Button leadingIcon={<Icon name="check" />} onClick={() => setResolveOpen(true)}>Mark resolved</Button>
            </section>
          )}
        </article>
      )}

      <Dialog open={resolveOpen} title="Mark this exception resolved?" confirmLabel="Mark resolved" onClose={() => setResolveOpen(false)} onConfirm={resolveSelected}>
        <div className={styles.dialogBody}>
          <p>This records the exception as resolved. It does not move money or create a ledger entry.</p>
          <dl>
            <Fact label="Exception" value={selected ? `#${selected.id}` : ""} />
            <Fact label="Difference" value={selected ? formatKobo(selected.difference_kobo) : ""} />
          </dl>
        </div>
      </Dialog>
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>;
}
