"use client";

import { useEffect, useState } from "react";
import {
  backOfficeGateway,
  type BackOfficeGame,
  type BackOfficePrizeTable,
  type BackOfficePrizeTablePreset,
  type BackOfficePrizeTableTier,
} from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { Icon } from "@/components/ui/Icon/Icon";
import { formatKobo } from "@/lib/money";
import styles from "@/components/operations/OperationsConsole.module.css";

type SuspensionScope = "Platform-wide" | "Channel" | "State";
type DraftResult = BackOfficePrizeTable & { gate_errors: string[] };

const GOOD_PRESET_FALLBACK: BackOfficePrizeTableTier[] = [1, 2, 3, 4, 5].map((positions) => ({
  positions,
  multiplier_hundredths: [185, 360, 700, 1350, 2600][positions - 1],
  probability_numerator: 1,
  probability_denominator: 2 ** positions,
}));

function toDatetimeLocalInput(iso: string): string {
  return iso.slice(0, 16);
}

function formatMultiplier(multiplierHundredths: number): string {
  return `${(multiplierHundredths / 100).toFixed(2)}×`;
}

function formatProbability(numerator: number, denominator: number): string {
  return `1 in ${denominator} (${((numerator / denominator) * 100).toFixed(3)}%)`;
}

export function GameRegistryConsole() {
  const [games, setGames] = useState<BackOfficeGame[]>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [selectedGameCode, setSelectedGameCode] = useState<string>();
  const [suspensionOpen, setSuspensionOpen] = useState(false);
  const [scope, setScope] = useState<SuspensionScope>("Platform-wide");
  const [suspensionReason, setSuspensionReason] = useState("");
  const [suspensionError, setSuspensionError] = useState("");
  const [receipt, setReceipt] = useState("");

  // Read — existing prize tables for the selected game (drafts, published, retired).
  const [prizeTables, setPrizeTables] = useState<BackOfficePrizeTable[]>();
  const [prizeTablesError, setPrizeTablesError] = useState("");

  // Presets — named starting points (Fair/Good/Best), BlackRed only (see GameRegistryController::presets).
  const [presets, setPresets] = useState<BackOfficePrizeTablePreset[]>([]);
  const [selectedPresetKey, setSelectedPresetKey] = useState<string>();

  // Create/Update — one form serves both; draftId set means "editing that row".
  const [draftId, setDraftId] = useState<number | null>(null);
  const [draftVersion, setDraftVersion] = useState("");
  const [draftEffectiveAt, setDraftEffectiveAt] = useState("");
  const [draftCertRef, setDraftCertRef] = useState("");
  const [draftTiers, setDraftTiers] = useState<BackOfficePrizeTableTier[]>(GOOD_PRESET_FALLBACK);
  const [draftResult, setDraftResult] = useState<DraftResult>();
  const [draftError, setDraftError] = useState("");

  // Delete
  const [deleteTarget, setDeleteTarget] = useState<BackOfficePrizeTable>();
  const [deleteError, setDeleteError] = useState("");

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    backOfficeGateway.games().then((result) => {
      setGames(result.games);
      setSelectedGameCode((current) => current ?? result.games[0]?.game_code);
    }).catch(() => setLoadFailed(true));
  }, []);

  useEffect(() => {
    if (!selectedGameCode) return;
    backOfficeGateway.prizeTables(selectedGameCode)
      .then((result) => { setPrizeTables(result.prize_tables); setPrizeTablesError(""); })
      .catch(() => setPrizeTablesError("Could not load prize tables for this game."));
    backOfficeGateway.prizeTablePresets(selectedGameCode)
      .then((result) => setPresets(result.presets))
      .catch(() => setPresets([]));
  }, [selectedGameCode]);

  const selectedGame = games?.find((game) => game.game_code === selectedGameCode);

  function refreshPrizeTables() {
    if (!selectedGameCode) return;
    backOfficeGateway.prizeTables(selectedGameCode).then((result) => setPrizeTables(result.prize_tables)).catch(() => {});
  }

  async function routeSuspension() {
    if (!suspensionReason.trim() || !selectedGame) {
      setSuspensionError("Record the regulatory or operational reason.");
      return;
    }
    const nextStatus = scope === "Platform-wide" ? "suspended" : selectedGame.status;
    try {
      await backOfficeGateway.updateGame(selectedGame.game_code, { status: nextStatus });
      setGames((current) => current?.map((game) => (game.game_code === selectedGame.game_code ? { ...game, status: nextStatus } : game)));
      setSuspensionOpen(false);
      setSuspensionReason("");
      setReceipt(`${selectedGame.game_code} status updated to "${nextStatus}". This applies immediately — updating game status has no maker-checker gate on this endpoint.`);
    } catch {
      setSuspensionError("Could not update game status. Please try again.");
    }
  }

  function applyPreset(preset: BackOfficePrizeTablePreset) {
    setSelectedPresetKey(preset.key);
    setDraftTiers(preset.tiers.map((tier) => ({
      positions: tier.positions,
      multiplier_hundredths: tier.multiplier_hundredths,
      probability_numerator: tier.probability_numerator,
      probability_denominator: tier.probability_denominator,
    })));
  }

  function updateTierMultiplier(positions: number, multiplierText: string) {
    setSelectedPresetKey(undefined); // a manual edit no longer matches any named preset
    const parsed = Number.parseFloat(multiplierText);
    setDraftTiers((current) => current.map((tier) => (
      tier.positions === positions
        ? { ...tier, multiplier_hundredths: Number.isFinite(parsed) ? Math.round(parsed * 100) : tier.multiplier_hundredths }
        : tier
    )));
  }

  function startCreateDraft() {
    setDraftId(null);
    setDraftVersion("");
    setDraftEffectiveAt("");
    setDraftCertRef("");
    setDraftTiers(presets.find((p) => p.key === "good")?.tiers ?? GOOD_PRESET_FALLBACK);
    setSelectedPresetKey(presets.length > 0 ? "good" : undefined);
    setDraftResult(undefined);
    setDraftError("");
  }

  function startEditDraft(table: BackOfficePrizeTable) {
    setDraftId(table.id);
    setDraftVersion(table.version);
    setDraftEffectiveAt(toDatetimeLocalInput(table.effective_at));
    setDraftCertRef(table.actuarial_cert_ref ?? "");
    setDraftTiers(table.tiers);
    setSelectedPresetKey(undefined);
    setDraftResult(undefined);
    setDraftError("");
  }

  async function submitDraft() {
    if (!selectedGame || !draftVersion.trim() || !draftEffectiveAt) {
      setDraftError("Choose a game, version and effective date.");
      return;
    }
    setDraftError("");
    try {
      const result = draftId === null
        ? await backOfficeGateway.createPrizeTable({
            game_code: selectedGame.game_code,
            version: draftVersion.trim(),
            effective_at: draftEffectiveAt,
            actuarial_cert_ref: draftCertRef.trim() || null,
            tiers: draftTiers,
          })
        : await backOfficeGateway.updatePrizeTable(draftId, {
            version: draftVersion.trim(),
            effective_at: draftEffectiveAt,
            actuarial_cert_ref: draftCertRef.trim() || null,
            tiers: draftTiers,
          });
      setDraftResult(result);
      setReceipt(`Draft ${result.version} ${draftId === null ? "created" : "updated"} (id #${result.id}). ${result.gate_errors.length === 0 ? "It passes the publication gate and can be proposed for approval." : "It does not yet pass the publication gate — see below."}`);
      setDraftId(result.id);
      refreshPrizeTables();
    } catch {
      setDraftError(`Could not ${draftId === null ? "create" : "update"} that draft. Please try again.`);
    }
  }

  async function proposePublication() {
    if (!draftResult) return;
    try {
      await backOfficeGateway.proposeChange({
        change_type: "prize_table_publish",
        payload: { prize_table_id: draftResult.id },
        justification: `Publish prize table ${draftResult.version} for ${draftResult.game_code}.`,
      });
      setReceipt(`Draft ${draftResult.version} proposed for approval. See Change approvals to review or approve it. The active prize table is unchanged until a different operator approves.`);
    } catch {
      setDraftError("Could not propose this table for publication. Please try again.");
    }
  }

  async function runDelete() {
    if (!deleteTarget) return;
    try {
      await backOfficeGateway.deletePrizeTable(deleteTarget.id);
      setDeleteTarget(undefined);
      setDeleteError("");
      if (draftId === deleteTarget.id) startCreateDraft();
      refreshPrizeTables();
      setReceipt(`Draft ${deleteTarget.version} deleted.`);
    } catch {
      setDeleteError("Could not delete this draft. Only a draft (not yet published) can be deleted.");
    }
  }

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live game registry data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Game operations task</p>
          <h1>Games and prize tables</h1>
          <p>Manage runtime game availability and draft prize tables that pass the real publication gate before they can be proposed for approval.</p>
        </div>
      </header>

      <section className={styles.runStrip} aria-labelledby="registry-runtime-title">
        <Icon name="games" size="control" />
        <div><h2 id="registry-runtime-title">Configuration controls live runtime</h2><p>Game status changes apply immediately, without an application deployment.</p></div>
      </section>

      {receipt && <div className={styles.receipt} role="status"><Icon name="check" />{receipt}</div>}

      <section className={styles.section} aria-labelledby="game-registry-title">
        <header className={styles.sectionHeader}><div><h2 id="game-registry-title">Game registry</h2></div></header>
        {games === undefined ? <p className={styles.muted}>Loading…</p> : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Configured Betplus game registry</caption>
              <thead><tr><th scope="col">Game</th><th scope="col">Engine</th><th scope="col" className={styles.money}>Stake range</th><th scope="col">Channels</th><th scope="col">States</th><th scope="col">Status</th><th scope="col">Configure</th></tr></thead>
              <tbody>{games.map((game) => (
                <tr key={game.game_code}>
                  <th scope="row" data-label="Game"><strong>{game.game_code}</strong></th>
                  <td data-label="Engine">{game.engine_version}</td>
                  <td data-label="Stake range" className={styles.money}>{formatKobo(game.min_stake_kobo)}–{formatKobo(game.max_stake_kobo)}</td>
                  <td data-label="Channels">{game.enabled_channels.join(", ") || "None"}</td>
                  <td data-label="States">{game.enabled_states.join(", ") || "None"}</td>
                  <td data-label="Status"><span className={styles.statusLabel}><Icon name={game.status === "active" ? "check" : "warning"} />{game.status}</span></td>
                  <td data-label="Configure"><button className={styles.tableAction} type="button" onClick={() => setSelectedGameCode(game.game_code)}>Inspect</button></td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
      </section>

      {selectedGame && (
        <article className={styles.inspector} aria-labelledby="selected-game-title">
          <header className={styles.inspectorHeader}>
            <div><p>{selectedGame.game_code} · {selectedGame.engine_version}</p><h2 id="selected-game-title">Runtime configuration</h2></div>
            <span className={styles.statusLabel}><Icon name="warning" />{selectedGame.status}</span>
          </header>
          <dl className={styles.factsGrid}>
            <Fact label="Engine version" value={selectedGame.engine_version} />
            <Fact label="Stake range" value={`${formatKobo(selectedGame.min_stake_kobo)}–${formatKobo(selectedGame.max_stake_kobo)}`} />
            <Fact label="Enabled channels" value={selectedGame.enabled_channels.join(", ") || "None"} />
            <Fact label="Enabled states" value={selectedGame.enabled_states.join(", ") || "None"} />
          </dl>
          <section className={styles.actionBand} aria-labelledby="suspension-title">
            <div><h2 id="suspension-title">Suspend or reactivate</h2><p>Changes the game's runtime status directly — this endpoint applies immediately and is not maker-checker gated.</p></div>
            <Button variant="secondary" leadingIcon={<Icon name="lock" />} onClick={() => setSuspensionOpen(true)}>Configure status</Button>
          </section>
        </article>
      )}

      <section className={styles.section} aria-labelledby="prize-tables-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="prize-tables-title">Prize tables for {selectedGameCode ?? "this game"}</h2><p>Drafts can be edited or deleted here. Published tables are permanent history and neither action is offered for them.</p></div>
          <Button leadingIcon={<Icon name="activity" />} onClick={startCreateDraft}>New draft</Button>
        </header>
        {prizeTablesError && <p className={styles.fieldError} role="alert"><Icon name="error" />{prizeTablesError}</p>}
        {prizeTables === undefined ? <p className={styles.muted}>Loading…</p> : prizeTables.length === 0 ? (
          <div className={styles.emptyState}><p>No prize tables yet for this game.</p></div>
        ) : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Prize tables for {selectedGameCode}</caption>
              <thead><tr><th scope="col">Version</th><th scope="col">Status</th><th scope="col">Effective</th><th scope="col">Tiers</th><th scope="col">Actions</th></tr></thead>
              <tbody>{prizeTables.map((table) => (
                <tr key={table.id}>
                  <th scope="row" data-label="Version"><strong>{table.version}</strong></th>
                  <td data-label="Status"><span className={styles.statusLabel}><Icon name={table.status === "published" ? "check" : "warning"} />{table.status}</span></td>
                  <td data-label="Effective">{new Date(table.effective_at).toLocaleString()}</td>
                  <td data-label="Tiers">{table.tiers.map((t) => formatMultiplier(t.multiplier_hundredths)).join(", ")}</td>
                  <td data-label="Actions">
                    {table.status === "draft" ? (
                      <>
                        <button className={styles.tableAction} type="button" onClick={() => startEditDraft(table)}>Edit</button>
                        {" · "}
                        <button className={styles.tableAction} type="button" onClick={() => { setDeleteTarget(table); setDeleteError(""); }}>Delete</button>
                      </>
                    ) : <span className={styles.muted}>—</span>}
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
      </section>

      <section className={styles.section} aria-labelledby="prize-drafting-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="prize-drafting-title">{draftId === null ? "Draft a prize table" : `Editing draft #${draftId}`}</h2>
            <p>{draftId === null ? "Creating a draft is direct (not maker-checker gated); publishing it is a separate, gated action below." : "Saving replaces this draft's tiers entirely. It has not been published, so this is safe."}</p>
          </div>
        </header>

        {presets.length > 0 && (
          <div className={styles.controls} role="group" aria-label="Prize table presets">
            {presets.map((preset) => (
              <Button
                key={preset.key}
                variant={selectedPresetKey === preset.key ? "primary" : "secondary"}
                onClick={() => applyPreset(preset)}
              >
                {preset.label}
              </Button>
            ))}
          </div>
        )}

        <div className={styles.controls}>
          <label className={styles.formField} htmlFor="draft-version">Version <span aria-hidden="true">*</span><input id="draft-version" value={draftVersion} onChange={(event) => setDraftVersion(event.target.value)} placeholder="2026.2" /></label>
          <label className={styles.formField} htmlFor="draft-effective">Effective at <span aria-hidden="true">*</span><input id="draft-effective" type="datetime-local" value={draftEffectiveAt} onChange={(event) => setDraftEffectiveAt(event.target.value)} /></label>
          <label className={styles.formField} htmlFor="draft-cert">Actuarial certification reference<input id="draft-cert" value={draftCertRef} onChange={(event) => setDraftCertRef(event.target.value)} /></label>
        </div>

        <div className={styles.tableWrap}>
          <table className={styles.table}>
            <caption className="sr-only">Tier multipliers for this draft</caption>
            <thead><tr><th scope="col">Positions</th><th scope="col">True win probability</th><th scope="col">Multiplier</th></tr></thead>
            <tbody>{draftTiers.map((tier) => (
              <tr key={tier.positions}>
                <th scope="row" data-label="Positions">{tier.positions}</th>
                <td data-label="Probability">{formatProbability(tier.probability_numerator, tier.probability_denominator)}</td>
                <td data-label="Multiplier">
                  <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    value={(tier.multiplier_hundredths / 100).toFixed(2)}
                    onChange={(event) => updateTierMultiplier(tier.positions, event.target.value)}
                    aria-label={`Multiplier for ${tier.positions} position(s)`}
                  />
                </td>
              </tr>
            ))}</tbody>
          </table>
        </div>

        <div className={styles.controls}>
          <Button leadingIcon={<Icon name="activity" />} onClick={submitDraft}>{draftId === null ? "Create draft" : "Save changes"}</Button>
          {draftId !== null && <Button variant="secondary" onClick={startCreateDraft}>Start a new draft instead</Button>}
        </div>
        {draftError && <p className={styles.fieldError} role="alert"><Icon name="error" />{draftError}</p>}

        {draftResult && (
          <>
            <ul className={styles.validationList} aria-label="Prize table publication gate results">
              {draftResult.gate_errors.length === 0 ? (
                <li><Icon name="check" /><div><strong>Passes the publication gate</strong><span>Probabilities, RTP ceiling and certification reference all check out.</span></div></li>
              ) : draftResult.gate_errors.map((error) => (
                <li key={error}><Icon name="error" /><div><strong>Blocked</strong><span>{error}</span></div></li>
              ))}
            </ul>
            <section className={styles.actionBand} aria-labelledby="publication-title">
              <div><h2 id="publication-title">Propose for approval</h2><p>Routes this draft to Change approvals. The active prize table stays in force until a different operator approves.</p></div>
              <Button leadingIcon={<Icon name="activity" />} onClick={proposePublication} disabled={draftResult.gate_errors.length > 0}>Propose publication</Button>
            </section>
          </>
        )}
      </section>

      <Dialog open={suspensionOpen} title={`Update ${selectedGame?.game_code ?? "game"} status?`} confirmLabel="Apply status change" onClose={() => setSuspensionOpen(false)} onConfirm={routeSuspension}>
        <div className={styles.dialogBody}>
          <p>This applies immediately — there is no approval step on this endpoint.</p>
          <label className={styles.formField} htmlFor="suspension-scope">Scope<select id="suspension-scope" value={scope} onChange={(event) => setScope(event.target.value as SuspensionScope)}><option>Platform-wide</option></select></label>
          <label className={styles.formField} htmlFor="suspension-reason">Reason <span aria-hidden="true">*</span><textarea id="suspension-reason" value={suspensionReason} aria-invalid={Boolean(suspensionError)} onChange={(event) => { setSuspensionReason(event.target.value); if (suspensionError) setSuspensionError(""); }} /></label>
          {suspensionError && <p className={styles.fieldError} role="alert"><Icon name="error" />{suspensionError}</p>}
        </div>
      </Dialog>

      <Dialog open={Boolean(deleteTarget)} title={`Delete draft ${deleteTarget?.version ?? ""}?`} confirmLabel="Delete draft" onClose={() => setDeleteTarget(undefined)} onConfirm={runDelete}>
        <div className={styles.dialogBody}>
          <p>This permanently removes the draft. Published prize tables can never be deleted — only a draft that has not yet been proposed and approved.</p>
          {deleteError && <p className={styles.fieldError} role="alert"><Icon name="error" />{deleteError}</p>}
        </div>
      </Dialog>
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>;
}
