"use client";

import { useEffect, useState } from "react";
import { backOfficeGateway, type BackOfficeJurisdiction } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "@/components/operations/OperationsConsole.module.css";

function basisPointsToPercent(bp: number) {
  return `${(bp / 100).toFixed(2)}%`;
}

export function JurisdictionConsole() {
  const [states, setStates] = useState<BackOfficeJurisdiction[]>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [selectedCode, setSelectedCode] = useState<string>();
  const [editOpen, setEditOpen] = useState(false);
  const [licenceNumber, setLicenceNumber] = useState("");
  const [issuedAt, setIssuedAt] = useState("");
  const [expiresAt, setExpiresAt] = useState("");
  const [rulesetVersion, setRulesetVersion] = useState("");
  const [remittanceStatus, setRemittanceStatus] = useState("current");
  const [error, setError] = useState("");
  const [receipt, setReceipt] = useState("");

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    backOfficeGateway.jurisdictions().then((result) => {
      setStates(result.states);
      setSelectedCode((current) => current ?? result.states[0]?.state_code);
    }).catch(() => setLoadFailed(true));
  }, []);

  const selected = states?.find((state) => state.state_code === selectedCode);
  const expiring = states?.filter((state) => state.expiry_alert || state.is_expired) ?? [];

  function openEdit() {
    if (!selected) return;
    setLicenceNumber(selected.licence_number);
    setIssuedAt(selected.issued_at);
    setExpiresAt(selected.expires_at);
    setRulesetVersion(selected.ruleset_version);
    setRemittanceStatus(selected.remittance_status);
    setError("");
    setEditOpen(true);
  }

  async function saveLicence() {
    if (!selected || !licenceNumber.trim() || !issuedAt || !expiresAt || !rulesetVersion.trim()) {
      setError("Complete the licence number, dates and ruleset version.");
      return;
    }
    try {
      const result = await backOfficeGateway.upsertJurisdiction({
        state_code: selected.state_code,
        licence_number: licenceNumber.trim(),
        issued_at: issuedAt,
        expires_at: expiresAt,
        ruleset_version: rulesetVersion.trim(),
        remittance_status: remittanceStatus,
      });
      setStates((current) => current?.map((state) => (state.state_code === result.state_code ? {
        ...state, licence_number: licenceNumber.trim(), issued_at: issuedAt, expires_at: result.expires_at,
        ruleset_version: rulesetVersion.trim(), remittance_status: remittanceStatus,
        is_expired: new Date(result.expires_at) < new Date(),
      } : state)));
      setEditOpen(false);
      setReceipt(`${selected.state_code} licence record updated. This applies immediately — licence upsert has no maker-checker gate on this endpoint.`);
    } catch {
      setError("Could not save that licence record. Please try again.");
    }
  }

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live jurisdiction data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Compliance task</p>
          <h1>Jurisdictions and tax</h1>
          <p>Monitor licence expiry and the withholding rates applied to regulated activity by state. Tax rate changes have no back-office maker-checker workflow yet — rates are read from configuration only.</p>
        </div>
      </header>

      {expiring.length > 0 && (
        <section className={styles.warningStrip} aria-labelledby="licence-alert-title">
          <Icon name="warning" size="control" />
          <div>
            <h2 id="licence-alert-title">Licence action required</h2>
            <p>{expiring.map((state) => `${state.state_code} ${state.is_expired ? "has expired" : "expires soon"}`).join(" · ")}.</p>
          </div>
          <span><Icon name="calendar" />60-day alert active</span>
        </section>
      )}

      {receipt && <div className={styles.receipt} role="status"><Icon name="check" />{receipt}</div>}

      <section className={styles.section} aria-labelledby="state-register-title">
        <header className={styles.sectionHeader}><div><h2 id="state-register-title">State licence and ruleset register</h2></div></header>
        {states === undefined ? <p className={styles.muted}>Loading…</p> : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Jurisdiction licence, tax and activity status by state</caption>
              <thead><tr><th scope="col">State</th><th scope="col">Licence</th><th scope="col">Expires</th><th scope="col">Ruleset</th><th scope="col">Activity</th><th scope="col">Remittance</th><th scope="col">Review</th></tr></thead>
              <tbody>{states.map((state) => (
                <tr key={state.state_code}>
                  <th scope="row" data-label="State"><strong>{state.state_code}</strong><span>{state.licence_number}</span></th>
                  <td data-label="Licence"><span className={styles.statusLabel}><Icon name={state.is_expired ? "warning" : "check"} />{state.is_expired ? "Expired" : "Active"}</span></td>
                  <td data-label="Expires">{state.expires_at}</td>
                  <td data-label="Ruleset">{state.ruleset_version}</td>
                  <td data-label="Activity">{state.activity_volume.toLocaleString("en-NG")} tickets</td>
                  <td data-label="Remittance">{state.remittance_status}</td>
                  <td data-label="Review"><button className={styles.tableAction} type="button" onClick={() => setSelectedCode(state.state_code)}>Inspect</button></td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
      </section>

      {selected && (
        <div className={styles.workspace}>
          <aside aria-label="Jurisdiction selection">
            <ul className={styles.recordList}>{(states ?? []).map((state) => (
              <li key={state.state_code}>
                <button className={styles.recordButton} data-selected={state.state_code === selected.state_code ? "true" : undefined} aria-pressed={state.state_code === selected.state_code} type="button" onClick={() => setSelectedCode(state.state_code)}>
                  <strong>{state.state_code}</strong><span>{state.is_expired ? "Expired" : "Active"} · {state.ruleset_version}</span>
                </button>
              </li>
            ))}</ul>
          </aside>

          <article className={styles.inspector} aria-labelledby="state-title">
            <header className={styles.inspectorHeader}>
              <div><p>{selected.licence_number}</p><h2 id="state-title">{selected.state_code}</h2></div>
              <span className={styles.statusLabel}><Icon name={selected.is_expired ? "warning" : "check"} />{selected.is_expired ? "Expired" : "Active"}</span>
            </header>

            <dl className={styles.factsGrid}>
              <Fact label="Issued" value={selected.issued_at} />
              <Fact label="Expires" value={selected.expires_at} />
              <Fact label="Tax ruleset" value={selected.ruleset_version} />
              <Fact label="Resident WHT rate" value={basisPointsToPercent(selected.resident_wht_rate_basis_points)} />
              <Fact label="Non-resident WHT rate" value={basisPointsToPercent(selected.non_resident_wht_rate_basis_points)} />
              <Fact label="Remittance" value={selected.remittance_status} />
              <Fact label="Activity" value={`${selected.activity_volume.toLocaleString("en-NG")} tickets`} />
            </dl>

            <section className={styles.actionBand} aria-labelledby="licence-edit-title">
              <div><h2 id="licence-edit-title">Update licence record</h2><p>Applies immediately — this endpoint has no maker-checker gate. Withholding tax rates come from configuration and can't be changed here.</p></div>
              <Button leadingIcon={<Icon name="activity" />} onClick={openEdit}>Update licence</Button>
            </section>
          </article>
        </div>
      )}

      <Dialog open={editOpen} title={`Update ${selected?.state_code ?? "state"} licence?`} confirmLabel="Save licence record" onClose={() => setEditOpen(false)} onConfirm={saveLicence}>
        <div className={styles.dialogBody}>
          <label className={styles.formField} htmlFor="licence-number">Licence number <span aria-hidden="true">*</span><input id="licence-number" value={licenceNumber} onChange={(event) => setLicenceNumber(event.target.value)} /></label>
          <label className={styles.formField} htmlFor="licence-issued">Issued <span aria-hidden="true">*</span><input id="licence-issued" type="date" value={issuedAt} onChange={(event) => setIssuedAt(event.target.value)} /></label>
          <label className={styles.formField} htmlFor="licence-expires">Expires <span aria-hidden="true">*</span><input id="licence-expires" type="date" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} /></label>
          <label className={styles.formField} htmlFor="licence-ruleset">Ruleset version <span aria-hidden="true">*</span><input id="licence-ruleset" value={rulesetVersion} onChange={(event) => setRulesetVersion(event.target.value)} /></label>
          <label className={styles.formField} htmlFor="licence-remittance">Remittance status<select id="licence-remittance" value={remittanceStatus} onChange={(event) => setRemittanceStatus(event.target.value)}><option value="current">Current</option><option value="overdue">Overdue</option><option value="unknown">Unknown</option></select></label>
          {error && <p className={styles.fieldError} role="alert"><Icon name="error" />{error}</p>}
        </div>
      </Dialog>
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>;
}
