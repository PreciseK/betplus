"use client";

import { useEffect, useState } from "react";
import { backOfficeGateway, type BackOfficePayout } from "@betplus/api-client";
import { formatKobo } from "@/lib/money";
import styles from "@/components/operations/OperationsConsole.module.css";

export function PayoutsConsole() {
  const [reviewOnly, setReviewOnly] = useState(false);
  const [payouts, setPayouts] = useState<BackOfficePayout[]>();
  const [loadFailed, setLoadFailed] = useState(false);

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    let active = true;
    backOfficeGateway.payouts(reviewOnly ? { manual_review_required: true } : undefined)
      .then((result) => { if (active) setPayouts(result.payouts); })
      .catch(() => { if (active) setLoadFailed(true); });
    return () => { active = false; };
  }, [reviewOnly]);

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live payout data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Finance</p>
          <h1>Payouts</h1>
          <p>Real automatic-prize and withdrawal payouts from the OPay dispatch pipeline.</p>
        </div>
        <div className={styles.controls}>
          <label className={styles.selectField} htmlFor="payout-review-filter">
            <span className="sr-only">Filter by review requirement</span>
            <select id="payout-review-filter" value={reviewOnly ? "review" : "all"} onChange={(event) => setReviewOnly(event.target.value === "review")}>
              <option value="all">All payouts</option>
              <option value="review">Needs manual review</option>
            </select>
          </label>
        </div>
      </header>

      <section className={styles.section} aria-labelledby="payouts-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="payouts-title">Recent payouts</h2><p>{payouts?.length ?? 0} records shown.</p></div>
        </header>

        {payouts === undefined ? <p className={styles.muted}>Loading…</p> : payouts.length > 0 ? (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Payouts, optionally filtered to those needing manual review</caption>
              <thead><tr><th scope="col">Reference</th><th scope="col">Player</th><th scope="col">Kind</th><th scope="col" className={styles.money}>Amount</th><th scope="col">Provider status</th><th scope="col">Review</th></tr></thead>
              <tbody>{payouts.map((payout) => (
                <tr key={payout.id}>
                  <th scope="row" data-label="Reference">{payout.reference}</th>
                  <td data-label="Player"><strong>{payout.registered_name ?? payout.player_reference}</strong><span>{payout.player_reference}</span></td>
                  <td data-label="Kind">{payout.kind === "automatic-prize" ? "Automatic prize" : "Withdrawal"}</td>
                  <td data-label="Amount" className={styles.money}>{formatKobo(payout.amount_kobo)}</td>
                  <td data-label="Provider status"><span className={styles.statusLabel}>{payout.provider_status}</span></td>
                  <td data-label="Review">{payout.manual_review_required ? "Needs review" : "—"}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : (
          <div className={styles.emptyState}><h3>No payouts in this view</h3><p>Change the filter to see other records.</p></div>
        )}
      </section>

      <aside className={styles.contractNote} aria-labelledby="payouts-boundary-title">
        <h2 id="payouts-boundary-title">Integration boundary</h2>
        <p>There is no provider-float or reserve-balance table anywhere in the backend, so this screen shows payout records only — no float view is offered rather than fabricating one.</p>
      </aside>
    </div>
  );
}
