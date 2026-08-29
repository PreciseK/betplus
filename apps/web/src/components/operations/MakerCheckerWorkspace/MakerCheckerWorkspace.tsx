"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { backOfficeGateway, BackOfficeApiError, type BackOfficeChange } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./MakerCheckerWorkspace.module.css";

type FilterState = "all" | BackOfficeChange["status"];

const STATE_LABELS: Record<BackOfficeChange["status"], string> = {
  DRAFT: "Draft",
  AWAITING_APPROVAL: "Awaiting approval",
  APPROVED: "Approved",
  REJECTED: "Rejected",
  APPLIED: "Applied",
};

function statePosition(state: BackOfficeChange["status"]) {
  if (state === "DRAFT") return 0;
  if (state === "AWAITING_APPROVAL") return 1;
  if (state === "APPROVED" || state === "REJECTED") return 2;
  return 3;
}

/** Diffs before_snapshot against payload field-by-field — real data, not a fabricated risk score. */
function diffFields(before: Record<string, unknown> | null, after: Record<string, unknown>) {
  const keys = new Set([...Object.keys(before ?? {}), ...Object.keys(after)]);
  return [...keys].map((field) => ({
    field,
    before: before && field in before ? JSON.stringify(before[field]) : "—",
    after: field in after ? JSON.stringify(after[field]) : "—",
  }));
}

export function MakerCheckerWorkspace() {
  const [changes, setChanges] = useState<BackOfficeChange[]>([]);
  const [selectedId, setSelectedId] = useState<number>();
  const [filter, setFilter] = useState<FilterState>("AWAITING_APPROVAL");
  const [loadFailed, setLoadFailed] = useState(false);
  const [approveOpen, setApproveOpen] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [rejectionReason, setRejectionReason] = useState("");
  const [rejectionError, setRejectionError] = useState("");
  const [guardMessage, setGuardMessage] = useState("");
  const [receipt, setReceipt] = useState("");
  const rejectionRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    let active = true;
    backOfficeGateway
      .changes(filter === "all" ? undefined : filter)
      .then((result) => {
        if (!active) return;
        setChanges(result.changes);
        setSelectedId((current) => (current !== undefined && result.changes.some((c) => c.id === current) ? current : result.changes[0]?.id));
      })
      .catch(() => {
        if (active) setLoadFailed(true);
      });
    return () => { active = false; };
  }, [filter]);

  const selected = useMemo(() => changes.find((change) => change.id === selectedId), [changes, selectedId]);

  function selectChange(id: number) {
    setSelectedId(id);
    setGuardMessage("");
    setReceipt("");
  }

  function requestApproval() {
    setGuardMessage("");
    setApproveOpen(true);
  }

  async function approveChange() {
    if (!selected) return;
    try {
      const updated = await backOfficeGateway.approveChange(selected.id);
      setChanges((current) => current.map((change) => (change.id === updated.id ? updated : change)));
      setApproveOpen(false);
      setReceipt(`Change #${updated.id} approved and applied.`);
    } catch (error) {
      setApproveOpen(false);
      // The backend refuses self-approval structurally (maker id === checker id) —
      // this message is real, not a client-side guess at the rule.
      setGuardMessage(error instanceof BackOfficeApiError ? error.message : "Could not approve this change.");
    }
  }

  async function rejectChange() {
    if (!selected) return;
    if (!rejectionReason.trim()) {
      setRejectionError("Enter the reason this change is being rejected.");
      requestAnimationFrame(() => rejectionRef.current?.focus());
      return;
    }
    try {
      const updated = await backOfficeGateway.rejectChange(selected.id, rejectionReason.trim());
      setChanges((current) => current.map((change) => (change.id === updated.id ? updated : change)));
      setRejectOpen(false);
      setRejectionError("");
      setRejectionReason("");
      setReceipt(`Change #${updated.id} rejected. The draft and its evidence were preserved.`);
    } catch (error) {
      setRejectOpen(false);
      setGuardMessage(error instanceof BackOfficeApiError ? error.message : "Could not reject this change.");
    }
  }

  if (loadFailed) {
    return (
      <div className={styles.page}>
        <p className={styles.muted}>Live change data could not be loaded. Sign in again if this persists.</p>
      </div>
    );
  }
  if (!selected) {
    return (
      <div className={styles.page}>
        <header className={styles.pageHeader}><div><p className={styles.context}>Shared review workflow</p><h1>Change approvals</h1></div></header>
        <p className={styles.muted}>No changes match this state.</p>
      </div>
    );
  }

  const diff = diffFields(selected.before_snapshot, selected.payload);

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Shared review workflow</p>
          <h1>Change approvals</h1>
          <p>Review the evidence and application timing before recording a decision. The single shared maker-checker workflow covers every reviewable change type.</p>
        </div>
      </header>

      {receipt && <div className={styles.receipt} role="status"><Icon name="check" />{receipt}</div>}
      {guardMessage && <div className={styles.guard} role="alert"><Icon name="lock" />{guardMessage}</div>}

      <div className={styles.workspace}>
        <section className={styles.queue} aria-labelledby="queue-title">
          <div className={styles.queueHeading}>
            <div>
              <h2 id="queue-title">Review queue</h2>
              <p>{changes.length} changes in scope</p>
            </div>
            <label htmlFor="approval-state-filter">
              <span className="sr-only">Filter by workflow state</span>
              <select id="approval-state-filter" value={filter} onChange={(event) => setFilter(event.target.value as FilterState)}>
                <option value="all">All states</option>
                <option value="AWAITING_APPROVAL">Awaiting approval</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
                <option value="APPLIED">Applied</option>
                <option value="DRAFT">Draft</option>
              </select>
            </label>
          </div>

          {changes.length > 0 ? (
            <ul className={styles.queueList}>
              {changes.map((change) => (
                <li key={change.id}>
                  <button
                    type="button"
                    className={styles.queueItem}
                    data-selected={change.id === selected.id ? "true" : undefined}
                    aria-pressed={change.id === selected.id}
                    onClick={() => selectChange(change.id)}
                  >
                    <span className={styles.queueMeta}><span>{STATE_LABELS[change.status]}</span></span>
                    <strong>{change.change_type}</strong>
                    <span>#{change.id}</span>
                    <span>Maker: #{change.maker_id}</span>
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <div className={styles.emptyQueue}>
              <p>No changes match this state.</p>
              <Button variant="secondary" onClick={() => setFilter("all")}>Show all states</Button>
            </div>
          )}
        </section>

        <article className={styles.review} aria-labelledby="review-title">
          <header className={styles.reviewHeader}>
            <div>
              <p>#{selected.id} · {selected.change_type}</p>
              <h2 id="review-title">{selected.change_type}</h2>
            </div>
            <span className={styles.state}>{STATE_LABELS[selected.status]}</span>
          </header>

          <WorkflowProgress state={selected.status} />

          <div className={styles.reviewGrid}>
            <section className={styles.makerEvidence} aria-labelledby="maker-title">
              <h3 id="maker-title">Maker evidence</h3>
              <dl>
                <div><dt>Maker</dt><dd>Institution user #{selected.maker_id}</dd></div>
                <div><dt>Submitted</dt><dd>{selected.submitted_at ?? "—"}</dd></div>
                <div className={styles.fullRow}><dt>Justification</dt><dd>{selected.maker_justification}</dd></div>
              </dl>
            </section>
          </div>

          <section className={styles.diff} aria-labelledby="diff-title">
            <h3 id="diff-title">Before and after</h3>
            <p className={styles.muted}>Computed directly from the change's stored before_snapshot and payload.</p>
            <div className={styles.diffHeader} aria-hidden="true"><span>Field</span><span>Before</span><span>After</span></div>
            {diff.map((row) => (
              <div className={styles.diffRow} key={row.field}>
                <strong>{row.field}</strong>
                <span data-label="Before">{row.before}</span>
                <span data-label="After">{row.after}</span>
              </div>
            ))}
          </section>

          {selected.status === "REJECTED" && (
            <section className={styles.decisionRecord} aria-labelledby="rejection-title">
              <h3 id="rejection-title">Rejection recorded</h3>
              <p>{selected.rejection_reason}</p>
              <span><Icon name="check" />Draft preserved with its original diff and justification</span>
            </section>
          )}

          {selected.status === "APPLIED" && (
            <section className={styles.decisionRecord} aria-labelledby="application-title">
              <h3 id="application-title">Immutable application record</h3>
              <dl>
                <div><dt>Maker</dt><dd>Institution user #{selected.maker_id}</dd></div>
                <div><dt>Checker</dt><dd>Institution user #{selected.checker_id}</dd></div>
                <div><dt>Applied</dt><dd>{selected.applied_at}</dd></div>
              </dl>
            </section>
          )}

          {selected.status === "APPROVED" && (
            <section className={styles.decisionRecord} aria-labelledby="approved-title">
              <h3 id="approved-title">Approved · awaiting application</h3>
              <p>Checker: institution user #{selected.checker_id}.</p>
            </section>
          )}

          {selected.status === "AWAITING_APPROVAL" && (
            <footer className={styles.actions}>
              <div>
                <strong>Decision will be audited</strong>
                <span>Your identity, decision and the change evidence version are recorded. The backend structurally refuses approving your own change.</span>
              </div>
              <Button variant="secondary" onClick={() => { setRejectOpen(true); setRejectionError(""); }}>Reject change</Button>
              <Button onClick={requestApproval}>Approve change</Button>
            </footer>
          )}
        </article>
      </div>

      <Dialog
        open={approveOpen}
        title="Approve this change?"
        confirmLabel="Record approval"
        onClose={() => setApproveOpen(false)}
        onConfirm={approveChange}
      >
        <div className={styles.confirmation}>
          <dl>
            <div><dt>Change</dt><dd>#{selected.id} · {selected.change_type}</dd></div>
          </dl>
          <p className={styles.auditConsequence}><Icon name="lock" /><span><strong>Audit consequence</strong>Your identity, decision and evidence version will be appended to the immutable audit log, and the change applies immediately on approval.</span></p>
        </div>
      </Dialog>

      <Dialog
        open={rejectOpen}
        title="Reject this change?"
        confirmLabel="Record rejection"
        balancedActions
        onClose={() => { setRejectOpen(false); setRejectionError(""); }}
        onConfirm={rejectChange}
      >
        <div className={styles.confirmation}>
          <dl>
            <div><dt>Change</dt><dd>#{selected.id} · {selected.change_type}</dd></div>
          </dl>
          <label className={styles.noteField} htmlFor="rejection-reason">
            <span>Rejection reason <span aria-hidden="true">*</span></span>
            <textarea
              ref={rejectionRef}
              id="rejection-reason"
              rows={3}
              required
              value={rejectionReason}
              aria-invalid={Boolean(rejectionError)}
              aria-describedby={rejectionError ? "rejection-help rejection-error" : "rejection-help"}
              onChange={(event) => { setRejectionReason(event.target.value); if (rejectionError) setRejectionError(""); }}
              onBlur={() => { if (!rejectionReason.trim()) setRejectionError("Enter the reason this change is being rejected."); }}
            />
            <small id="rejection-help">The draft and original evidence will remain available for revision.</small>
            {rejectionError && <span id="rejection-error" className={styles.fieldError} role="alert"><Icon name="error" />{rejectionError}</span>}
          </label>
          <p className={styles.auditConsequence}><Icon name="lock" /><span><strong>Audit consequence</strong>The reason will be recorded with your identity.</span></p>
        </div>
      </Dialog>
    </div>
  );
}

function WorkflowProgress({ state }: { state: BackOfficeChange["status"] }) {
  const currentPosition = statePosition(state);
  const stages = [
    { label: "Draft", position: 0 },
    { label: "Awaiting approval", position: 1 },
    { label: state === "REJECTED" ? "Rejected" : "Approved", position: 2 },
    { label: "Applied", position: 3 },
  ];

  return (
    <ol className={styles.workflow} aria-label="Change workflow">
      {stages.map((stage) => {
        const current = stage.position === currentPosition;
        const complete = stage.position < currentPosition || (state === "APPLIED" && stage.position === currentPosition);
        return (
          <li key={stage.position} data-complete={complete ? "true" : undefined} aria-current={current ? "step" : undefined}>
            <span aria-hidden="true">{complete ? "✓" : stage.position + 1}</span>
            <strong>{stage.label}</strong>
          </li>
        );
      })}
    </ol>
  );
}
