"use client";

import { useEffect, useState } from "react";
import { backOfficeGateway, type BackOfficeDeposit } from "@betplus/api-client";
import { formatKobo } from "@/lib/money";
import styles from "@/components/operations/OperationsConsole.module.css";

const STATUSES = ["all", "pending_otp", "processing", "paid", "failed", "unknown"] as const;

export function DepositsConsole() {
  const [status, setStatus] = useState<(typeof STATUSES)[number]>("all");
  const [deposits, setDeposits] = useState<BackOfficeDeposit[]>();
  const [loadFailed, setLoadFailed] = useState(false);

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    let active = true;
    backOfficeGateway.deposits(status === "all" ? undefined : status)
      .then((result) => { if (active) setDeposits(result.deposits); })
      .catch(() => { if (active) setLoadFailed(true); });
    return () => { active = false; };
  }, [status]);

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live deposit data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Finance</p>
          <h1>Deposits</h1>
          <p>Real OPay collection status, from initiation through to a confirmed play-balance credit.</p>
        </div>
        <div className={styles.controls}>
          <label className={styles.selectField} htmlFor="deposit-status">
            Status
            <select id="deposit-status" value={status} onChange={(event) => setStatus(event.target.value as (typeof STATUSES)[number])}>
              {STATUSES.map((option) => <option key={option} value={option}>{option === "all" ? "All statuses" : option}</option>)}
            </select>
          </label>
        </div>
      </header>

      <section className={styles.section} aria-labelledby="deposits-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="deposits-title">Recent deposits</h2><p>{deposits?.length ?? 0} records shown.</p></div>
        </header>

        {deposits === undefined ? <p className={styles.muted}>Loading…</p> : deposits.length > 0 ? (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Deposits by status</caption>
              <thead><tr><th scope="col">Reference</th><th scope="col">Player</th><th scope="col" className={styles.money}>Amount</th><th scope="col">Status</th><th scope="col">Paid at</th></tr></thead>
              <tbody>{deposits.map((deposit) => (
                <tr key={deposit.id}>
                  <th scope="row" data-label="Reference">{deposit.reference}</th>
                  <td data-label="Player"><strong>{deposit.registered_name ?? deposit.player_reference}</strong><span>{deposit.player_reference}</span></td>
                  <td data-label="Amount" className={styles.money}>{formatKobo(deposit.amount_kobo)}</td>
                  <td data-label="Status"><span className={styles.statusLabel}>{deposit.status}</span></td>
                  <td data-label="Paid at">{deposit.paid_at ? new Date(deposit.paid_at).toLocaleString() : "—"}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : (
          <div className={styles.emptyState}><h3>No deposits in this status</h3><p>Change the status filter to see other records.</p></div>
        )}
      </section>
    </div>
  );
}
