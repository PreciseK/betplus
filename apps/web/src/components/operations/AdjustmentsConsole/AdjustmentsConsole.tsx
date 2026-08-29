"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { backOfficeGateway, BackOfficeApiError, type BackOfficeChange } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Icon } from "@/components/ui/Icon/Icon";
import { formatKobo, parseNairaInputToKobo } from "@/lib/money";
import styles from "@/components/operations/OperationsConsole.module.css";

const STATE_LABELS: Record<string, string> = {
  DRAFT: "Draft", AWAITING_APPROVAL: "Awaiting approval", APPROVED: "Approved", REJECTED: "Rejected", APPLIED: "Applied",
};

export function AdjustmentsConsole() {
  const [changes, setChanges] = useState<BackOfficeChange[]>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [receipt, setReceipt] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const [playerId, setPlayerId] = useState("");
  const [direction, setDirection] = useState<"credit" | "debit">("credit");
  const [balance, setBalance] = useState<"PLAY" | "WINNINGS">("PLAY");
  const [amount, setAmount] = useState("");
  const [justification, setJustification] = useState("");
  const formRef = useRef<HTMLFormElement>(null);

  function load() {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    backOfficeGateway.changes(undefined, "manual_credit_debit")
      .then((result) => setChanges(result.changes))
      .catch(() => setLoadFailed(true));
  }

  useEffect(load, []);

  async function submitAdjustment(event: React.FormEvent) {
    event.preventDefault();
    setError("");
    const amountKobo = parseNairaInputToKobo(amount);
    const numericPlayerId = Number(playerId);

    if (!numericPlayerId || numericPlayerId <= 0) { setError("Enter a valid player ID."); return; }
    if (amountKobo === null || amountKobo <= 0) { setError("Enter a valid amount."); return; }
    if (justification.trim().length < 10) { setError("Justification must be at least 10 characters."); return; }

    setSubmitting(true);
    try {
      await backOfficeGateway.proposeChange({
        change_type: "manual_credit_debit",
        payload: { player_id: numericPlayerId, direction, balance, amount_kobo: amountKobo },
        justification: justification.trim(),
      });
      setReceipt(`Adjustment proposed for player #${numericPlayerId}. It needs a different checker's approval before it applies.`);
      setPlayerId(""); setAmount(""); setJustification("");
      load();
    } catch (submitError) {
      setError(submitError instanceof BackOfficeApiError ? submitError.message : "Could not propose that adjustment.");
    } finally {
      setSubmitting(false);
    }
  }

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live adjustment data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Finance</p>
          <h1>Manual adjustments</h1>
          <p>Real maker-checker credit/debit proposals — the same workflow every other reviewable change uses.</p>
        </div>
      </header>

      {receipt && <div className={styles.receipt} role="status"><Icon name="check" />{receipt}</div>}

      <section className={styles.section} aria-labelledby="propose-adjustment-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="propose-adjustment-title">Propose an adjustment</h2><p>Applies only after a different operator approves it.</p></div>
        </header>

        <form ref={formRef} onSubmit={submitAdjustment} className={styles.filters} aria-label="Propose a manual credit or debit">
          <label className={styles.selectField} htmlFor="adj-player-id">
            Player ID
            <input id="adj-player-id" type="number" min={1} value={playerId} onChange={(event) => setPlayerId(event.target.value)} />
          </label>
          <label className={styles.selectField} htmlFor="adj-direction">
            Direction
            <select id="adj-direction" value={direction} onChange={(event) => setDirection(event.target.value as "credit" | "debit")}>
              <option value="credit">Credit</option>
              <option value="debit">Debit</option>
            </select>
          </label>
          <label className={styles.selectField} htmlFor="adj-balance">
            Balance
            <select id="adj-balance" value={balance} onChange={(event) => setBalance(event.target.value as "PLAY" | "WINNINGS")}>
              <option value="PLAY">Play balance</option>
              <option value="WINNINGS">Winnings balance</option>
            </select>
          </label>
          <label className={styles.selectField} htmlFor="adj-amount">
            Amount (₦)
            <input id="adj-amount" type="text" inputMode="decimal" value={amount} onChange={(event) => setAmount(event.target.value)} placeholder="e.g. 5000" />
          </label>
          <label className={styles.reasonField} htmlFor="adj-justification" style={{ gridColumn: "1 / -1" }}>
            <span>Justification</span>
            <textarea id="adj-justification" rows={2} value={justification} onChange={(event) => setJustification(event.target.value)} />
          </label>
          {error && <span className={styles.fieldError} role="alert"><Icon name="error" />{error}</span>}
          <Button type="submit" status={submitting ? "loading" : "idle"}>Propose adjustment</Button>
        </form>
      </section>

      <section className={styles.section} aria-labelledby="adjustments-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="adjustments-title">Recent adjustments</h2>
            <p>{changes?.length ?? 0} records. <Link href="/back-office/overview/approvals">Review pending approvals →</Link></p>
          </div>
        </header>

        {changes === undefined ? <p className={styles.muted}>Loading…</p> : changes.length > 0 ? (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Manual credit/debit adjustments</caption>
              <thead><tr><th scope="col">#</th><th scope="col">Player</th><th scope="col">Direction</th><th scope="col" className={styles.money}>Amount</th><th scope="col">Status</th></tr></thead>
              <tbody>{changes.map((change) => (
                <tr key={change.id}>
                  <th scope="row" data-label="#">#{change.id}</th>
                  <td data-label="Player">#{String(change.payload.player_id ?? "—")}</td>
                  <td data-label="Direction">{String(change.payload.direction ?? "—")} · {String(change.payload.balance ?? "—")}</td>
                  <td data-label="Amount" className={styles.money}>{formatKobo(Number(change.payload.amount_kobo ?? 0))}</td>
                  <td data-label="Status"><span className={styles.statusLabel}>{STATE_LABELS[change.status] ?? change.status}</span></td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        ) : (
          <div className={styles.emptyState}><h3>No adjustments yet</h3><p>Propose one above.</p></div>
        )}
      </section>
    </div>
  );
}
