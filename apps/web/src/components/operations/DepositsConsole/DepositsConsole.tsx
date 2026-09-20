"use client";

import { useEffect, useRef, useState } from "react";
import { backOfficeGateway, type BackOfficeDeposit } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { Icon } from "@/components/ui/Icon/Icon";
import { EmptyState } from "@/components/operations/EmptyState/EmptyState";
import { formatKobo } from "@/lib/money";
import styles from "@/components/operations/OperationsConsole.module.css";

const STATUSES = ["all", "paid", "pending_review", "failed"] as const;

export function DepositsConsole() {
  const [status, setStatus] = useState<(typeof STATUSES)[number]>("all");
  const [deposits, setDeposits] = useState<BackOfficeDeposit[]>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [receipt, setReceipt] = useState("");

  const [approveTarget, setApproveTarget] = useState<BackOfficeDeposit>();
  const [rejectTarget, setRejectTarget] = useState<BackOfficeDeposit>();
  const [rejectionReason, setRejectionReason] = useState("");
  const [rejectionError, setRejectionError] = useState("");
  const rejectionRef = useRef<HTMLTextAreaElement>(null);

  const load = () => {
    backOfficeGateway.deposits(status === "all" ? undefined : status)
      .then((result) => setDeposits(result.deposits))
      .catch(() => setLoadFailed(true));
  };

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

  async function approveDeposit() {
    if (!approveTarget) return;
    try {
      await backOfficeGateway.approveDeposit(approveTarget.id);
      setApproveTarget(undefined);
      setReceipt(`Deposit ${approveTarget.reference} approved and credited.`);
      load();
    } catch {
      setApproveTarget(undefined);
      setReceipt(`Could not approve deposit ${approveTarget.reference}. It may have already been decided.`);
    }
  }

  async function rejectDeposit() {
    if (!rejectTarget) return;
    if (!rejectionReason.trim()) {
      setRejectionError("Enter the reason this deposit is being rejected.");
      requestAnimationFrame(() => rejectionRef.current?.focus());
      return;
    }
    try {
      await backOfficeGateway.rejectDeposit(rejectTarget.id, rejectionReason.trim());
      setRejectTarget(undefined);
      setRejectionError("");
      setRejectionReason("");
      setReceipt(`Deposit ${rejectTarget.reference} rejected. It was never credited.`);
      load();
    } catch {
      setRejectTarget(undefined);
      setReceipt(`Could not reject deposit ${rejectTarget.reference}. It may have already been decided.`);
    }
  }

  if (loadFailed) {
    return (
      <div className={styles.page}>
        <EmptyState
          icon="error"
          title="Could not load deposits"
          description="Live deposit data could not be loaded from the back-office gateway. Try refreshing or signing in again."
        />
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Finance</p>
          <h1>Deposits</h1>
          <p>Real OPay collection status, from initiation through to a confirmed play-balance credit. A pending_review deposit exceeded the auto-credit threshold and is held until approved or rejected here.</p>
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

      {receipt && <p className={styles.muted} role="status">{receipt}</p>}

      <section className={styles.section} aria-labelledby="deposits-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="deposits-title">Recent deposits</h2><p>{deposits?.length ?? 0} records shown.</p></div>
        </header>

        {deposits === undefined ? <p className={styles.muted}>Loading…</p> : deposits.length > 0 ? (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Deposits by status</caption>
              <thead><tr><th scope="col">Reference</th><th scope="col">Player</th><th scope="col" className={styles.money}>Amount</th><th scope="col">Status</th><th scope="col">Paid at</th><th scope="col">Actions</th></tr></thead>
              <tbody>{deposits.map((deposit) => (
                <tr key={deposit.id}>
                  <th scope="row" data-label="Reference">{deposit.reference}</th>
                  <td data-label="Player"><strong>{deposit.registered_name ?? deposit.player_reference}</strong><span>{deposit.player_reference}</span></td>
                  <td data-label="Amount" className={styles.money}>{formatKobo(deposit.amount_kobo)}</td>
                  <td data-label="Status"><span className={styles.statusLabel}>{deposit.status}</span></td>
                  <td data-label="Paid at">{deposit.paid_at ? new Date(deposit.paid_at).toLocaleString() : "—"}</td>
                  <td data-label="Actions">
                    {deposit.status === "pending_review" ? (
                      <div className={styles.rowActions}>
                        <Button variant="quiet" leadingIcon={<Icon name="check" />} onClick={() => setApproveTarget(deposit)}>Approve</Button>
                        <Button variant="quiet" leadingIcon={<Icon name="error" />} onClick={() => setRejectTarget(deposit)}>Reject</Button>
                      </div>
                    ) : "—"}
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : (
          <EmptyState
            icon="money"
            title="No deposits in this status"
            description="Try changing the status filter above to view completed, pending review, or failed deposits."
          />
        )}
      </section>

      <Dialog
        open={Boolean(approveTarget)}
        title="Approve this deposit?"
        confirmLabel="Approve and credit"
        onClose={() => setApproveTarget(undefined)}
        onConfirm={approveDeposit}
      >
        <div className={styles.dialogBody}>
          <dl>
            <div><dt>Deposit</dt><dd>{approveTarget?.reference}</dd></div>
            <div><dt>Player</dt><dd>{approveTarget?.registered_name ?? approveTarget?.player_reference}</dd></div>
            <div><dt>Amount</dt><dd>{approveTarget ? formatKobo(approveTarget.amount_kobo) : ""}</dd></div>
          </dl>
          <p><Icon name="lock" /> Play Balance is credited immediately on approval. Your identity and decision are appended to the immutable audit log.</p>
        </div>
      </Dialog>

      <Dialog
        open={Boolean(rejectTarget)}
        title="Reject this deposit?"
        confirmLabel="Record rejection"
        balancedActions
        onClose={() => { setRejectTarget(undefined); setRejectionError(""); }}
        onConfirm={rejectDeposit}
      >
        <div className={styles.dialogBody}>
          <dl>
            <div><dt>Deposit</dt><dd>{rejectTarget?.reference}</dd></div>
            <div><dt>Amount</dt><dd>{rejectTarget ? formatKobo(rejectTarget.amount_kobo) : ""}</dd></div>
          </dl>
          <label className={styles.formField} htmlFor="deposit-rejection-reason">
            Rejection reason <span aria-hidden="true">*</span>
            <textarea
              ref={rejectionRef}
              id="deposit-rejection-reason"
              rows={3}
              required
              value={rejectionReason}
              aria-invalid={Boolean(rejectionError)}
              aria-describedby={rejectionError ? "deposit-rejection-help deposit-rejection-error" : "deposit-rejection-help"}
              onChange={(event) => { setRejectionReason(event.target.value); if (rejectionError) setRejectionError(""); }}
              onBlur={() => { if (!rejectionReason.trim()) setRejectionError("Enter the reason this deposit is being rejected."); }}
            />
            <span id="deposit-rejection-help" className={styles.fieldHelp}>The deposit is never credited; the player's Play Balance is unaffected.</span>
            {rejectionError && <span id="deposit-rejection-error" className={styles.fieldError} role="alert"><Icon name="error" />{rejectionError}</span>}
          </label>
          <p><Icon name="lock" /> The reason will be recorded with your identity.</p>
        </div>
      </Dialog>
    </div>
  );
}
