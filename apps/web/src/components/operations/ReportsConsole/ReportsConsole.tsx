"use client";

import { useEffect, useRef, useState } from "react";
import { backOfficeGateway } from "@betplus/api-client";
import { useOperationsUrlFilters } from "@/components/operations/useOperationsUrlFilters";
import { Button } from "@/components/ui/Button/Button";
import { Icon } from "@/components/ui/Icon/Icon";
import { EmptyState } from "@/components/operations/EmptyState/EmptyState";
import styles from "@/components/operations/OperationsConsole.module.css";

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}
function daysAgoIso(days: number) {
  const date = new Date();
  date.setDate(date.getDate() - days);
  return date.toISOString().slice(0, 10);
}

const DEFAULT_FILTERS = { state: "ALL", game: "ALL", from: daysAgoIso(29), to: todayIso() };

interface TrackedJob {
  id: number;
  status: string;
  rowCount: number | null;
  failureReason: string | null;
  downloadUrl: string | null;
}

export function ReportsConsole() {
  const { filters, updateFilter } = useOperationsUrlFilters(DEFAULT_FILTERS);
  // No list-all-my-exports endpoint exists server-side — this app only ever knows
  // about a request's own id, so history is this-session-only. Flagged in the copy
  // below rather than fabricated as a persistent job history.
  const [jobs, setJobs] = useState<TrackedJob[]>([]);
  const [dateError, setDateError] = useState("");
  const [requestError, setRequestError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const unmountedRef = useRef(false);

  useEffect(() => () => { unmountedRef.current = true; }, []);

  function pollJob(id: number) {
    const poll = () => {
      backOfficeGateway.reportStatus(id).then((result) => {
        if (unmountedRef.current) return;
        setJobs((current) => current.map((job) => (job.id === id ? {
          id, status: result.status, rowCount: result.row_count, failureReason: result.failure_reason, downloadUrl: result.download_url,
        } : job)));
        if (result.status === "queued" || result.status === "processing") {
          setTimeout(poll, 3000);
        }
      }).catch(() => {
        if (unmountedRef.current) return;
        setJobs((current) => current.map((job) => (job.id === id ? { ...job, status: "unknown" } : job)));
      });
    };
    poll();
  }

  async function queueExport() {
    if (!backOfficeGateway.hasSession()) {
      setRequestError("Sign in again to request an export.");
      return;
    }
    if (!filters.from || !filters.to || filters.from > filters.to) {
      setDateError("Choose a valid bounded date range; the start date must not follow the end date.");
      return;
    }
    setDateError("");
    setRequestError("");
    setIsSubmitting(true);
    try {
      const result = await backOfficeGateway.requestFinancialReport({
        from: filters.from, to: filters.to,
        game_code: filters.game === "ALL" ? undefined : filters.game,
        state_code: filters.state === "ALL" ? undefined : filters.state,
      });
      setJobs((current) => [{ id: result.id, status: result.status, rowCount: null, failureReason: null, downloadUrl: null }, ...current]);
      pollJob(result.id);
    } catch {
      setRequestError("Could not queue that export. Please try again.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div><p className={styles.context}>Compliance task</p><h1>Reports and regulatory exports</h1><p>Request a bounded financial export by game and attributed state. This runs asynchronously — no report data streams synchronously to this screen.</p></div>
      </header>

      <section className={styles.runStrip} aria-labelledby="reporting-contract-title">
        <Icon name="lock" size="control" />
        <div><h2 id="reporting-contract-title">Asynchronous export contract</h2><p>The request is audit-logged immediately (actor, filter). Row count and a signed, one-hour-expiring download link are attached once processing finishes.</p></div>
        <span><Icon name="check" />No synchronous data streaming</span>
      </section>

      <section className={styles.section} aria-labelledby="report-scope-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="report-scope-title">Report scope</h2><p>Slices stakes, payouts, GGR, RTP actual vs. modelled, and tax by game and attributed state. Provider fees are not yet accrued anywhere in this system and are reported as zero rather than estimated.</p></div>
        </header>
        <div className={styles.controls}>
          <label className={styles.selectField} htmlFor="report-state">Attributed state<select id="report-state" value={filters.state} onChange={(event) => { updateFilter("state", event.target.value); setDateError(""); }}><option value="ALL">All states</option><option value="LAG">Lagos</option></select></label>
          <label className={styles.selectField} htmlFor="report-game">Game<select id="report-game" value={filters.game} onChange={(event) => updateFilter("game", event.target.value)}><option value="ALL">All games</option><option>BLACKRED</option><option>HERITAGE</option><option>CAGED</option><option>BIRDESCAPE</option></select></label>
          <label className={styles.formField} htmlFor="report-from">From<input id="report-from" type="date" value={filters.from} aria-invalid={Boolean(dateError)} onChange={(event) => { updateFilter("from", event.target.value); setDateError(""); }} /></label>
          <label className={styles.formField} htmlFor="report-to">To<input id="report-to" type="date" value={filters.to} aria-invalid={Boolean(dateError)} onChange={(event) => { updateFilter("to", event.target.value); setDateError(""); }} /></label>
          <Button leadingIcon={<Icon name="activity" />} onClick={queueExport} disabled={isSubmitting}>{isSubmitting ? "Queuing…" : "Queue export"}</Button>
        </div>
        {dateError && <p className={styles.fieldError} role="alert"><Icon name="error" />{dateError}</p>}
        {requestError && <p className={styles.fieldError} role="alert"><Icon name="error" />{requestError}</p>}
      </section>

      <section className={styles.section} aria-labelledby="export-jobs-title">
        <header className={styles.sectionHeader}><div><h2 id="export-jobs-title">Export jobs this session</h2><p>There is no server-side history list for report exports — this table only tracks jobs requested in this browser session. Reloading the page loses this list; the underlying export and its audit record still exist.</p></div></header>
        {jobs.length === 0 ? (
          <EmptyState
            icon="calendar"
            title="No exports requested yet"
            description="Queue an export using the form above to generate and download compliance and financial reports."
          />
        ) : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Report export jobs requested this session</caption>
              <thead><tr><th scope="col">Export</th><th scope="col">Status</th><th scope="col">Rows</th><th scope="col">Download</th></tr></thead>
              <tbody>{jobs.map((job) => (
                <tr key={job.id}>
                  <th scope="row" data-label="Export">#{job.id}</th>
                  <td data-label="Status"><span className={styles.statusLabel} data-status={job.status}><Icon name={job.status === "completed" ? "check" : job.status === "failed" ? "error" : "loading"} />{job.status}</span></td>
                  <td data-label="Rows">{job.rowCount?.toLocaleString("en-NG") ?? "—"}</td>
                  <td data-label="Download">
                    {job.downloadUrl
                      ? <a className={styles.tableAction} href={job.downloadUrl}>Download (expires in 1 hour)</a>
                      : job.failureReason ?? "Not ready"}
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
