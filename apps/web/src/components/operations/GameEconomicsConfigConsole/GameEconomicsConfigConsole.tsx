"use client";

import { useEffect, useState } from "react";
import {
  backOfficeGateway,
  type BackOfficeGame,
  type BackOfficeGameEconomicsConfig,
  type EconomicsModel,
} from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "@/components/operations/OperationsConsole.module.css";

type DraftResult = BackOfficeGameEconomicsConfig & { gate_errors: string[] };

const MODELS: EconomicsModel[] = ["FIXED_RTP", "BALANCED_HYBRID", "DAILY_LOSS_STOP", "PARI_MUTUEL_POOL"];

function toDatetimeLocalInput(iso: string): string {
  return iso.slice(0, 16);
}

export function GameEconomicsConfigConsole() {
  const [games, setGames] = useState<BackOfficeGame[]>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [selectedGameCode, setSelectedGameCode] = useState<string>();

  const [configs, setConfigs] = useState<BackOfficeGameEconomicsConfig[]>();
  const [configsError, setConfigsError] = useState("");

  // Create/Update — one form serves both; draftId set means "editing that row".
  const [draftId, setDraftId] = useState<number | null>(null);
  const [draftVersion, setDraftVersion] = useState("");
  const [draftModel, setDraftModel] = useState<EconomicsModel>("FIXED_RTP");
  const [draftKellyFactor, setDraftKellyFactor] = useState("300");
  const [draftEffectiveAt, setDraftEffectiveAt] = useState("");
  const [draftResult, setDraftResult] = useState<DraftResult>();
  const [draftError, setDraftError] = useState("");

  const [deleteTarget, setDeleteTarget] = useState<BackOfficeGameEconomicsConfig>();
  const [deleteError, setDeleteError] = useState("");
  const [receipt, setReceipt] = useState("");

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
    backOfficeGateway.gameEconomicsConfigs(selectedGameCode)
      .then((result) => { setConfigs(result.game_economics_configs); setConfigsError(""); })
      .catch(() => setConfigsError("Could not load economics configs for this game."));
  }, [selectedGameCode]);

  function refreshConfigs() {
    if (!selectedGameCode) return;
    backOfficeGateway.gameEconomicsConfigs(selectedGameCode).then((result) => setConfigs(result.game_economics_configs)).catch(() => {});
  }

  function startCreateDraft() {
    setDraftId(null);
    setDraftVersion("");
    setDraftModel("FIXED_RTP");
    setDraftKellyFactor("300");
    setDraftEffectiveAt("");
    setDraftResult(undefined);
    setDraftError("");
  }

  function startEditDraft(config: BackOfficeGameEconomicsConfig) {
    setDraftId(config.id);
    setDraftVersion(config.version);
    setDraftModel(config.active_model);
    setDraftKellyFactor(String(config.params.kelly_factor_basis_points ?? 300));
    setDraftEffectiveAt(toDatetimeLocalInput(config.effective_at));
    setDraftResult(undefined);
    setDraftError("");
  }

  async function submitDraft() {
    if (!selectedGameCode || !draftVersion.trim() || !draftEffectiveAt) {
      setDraftError("Choose a game, version and effective date.");
      return;
    }
    const params = draftModel === "BALANCED_HYBRID" ? { kelly_factor_basis_points: Number.parseInt(draftKellyFactor, 10) || 0 } : {};
    setDraftError("");
    try {
      const result = draftId === null
        ? await backOfficeGateway.createGameEconomicsConfig({
            game_code: selectedGameCode,
            version: draftVersion.trim(),
            active_model: draftModel,
            params,
            effective_at: draftEffectiveAt,
          })
        : await backOfficeGateway.updateGameEconomicsConfig(draftId, {
            version: draftVersion.trim(),
            active_model: draftModel,
            params,
            effective_at: draftEffectiveAt,
          });
      setDraftResult(result);
      setReceipt(`Draft ${result.version} ${draftId === null ? "created" : "updated"} (id #${result.id}). ${result.gate_errors.length === 0 ? "It passes the gate and can be proposed for approval." : "It does not yet pass the gate — see below."}`);
      setDraftId(result.id);
      refreshConfigs();
    } catch {
      setDraftError(`Could not ${draftId === null ? "create" : "update"} that draft. Please try again.`);
    }
  }

  async function proposePublication() {
    if (!draftResult) return;
    try {
      await backOfficeGateway.proposeChange({
        change_type: "game_economics_config_publish",
        payload: { game_economics_config_id: draftResult.id },
        justification: `Switch ${draftResult.game_code} to ${draftResult.active_model} (${draftResult.version}).`,
      });
      setReceipt(`Draft ${draftResult.version} proposed for approval. See Change approvals to review or approve it. The active model is unchanged until a different operator approves.`);
    } catch {
      setDraftError("Could not propose this config for publication. Please try again.");
    }
  }

  async function runDelete() {
    if (!deleteTarget) return;
    try {
      await backOfficeGateway.deleteGameEconomicsConfig(deleteTarget.id);
      setDeleteTarget(undefined);
      setDeleteError("");
      if (draftId === deleteTarget.id) startCreateDraft();
      refreshConfigs();
      setReceipt(`Draft ${deleteTarget.version} deleted.`);
    } catch {
      setDeleteError("Could not delete this draft. Only a draft (not yet published) can be deleted.");
    }
  }

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live game data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Game operations task</p>
          <h1>Economics models</h1>
          <p>Switch which economics model governs bet acceptance for a game. A switch takes effect at the next round or ticket after approval — nothing changes mid-flight.</p>
        </div>
      </header>

      {receipt && <div className={styles.receipt} role="status"><Icon name="check" />{receipt}</div>}

      <section className={styles.section} aria-labelledby="game-select-title">
        <header className={styles.sectionHeader}><div><h2 id="game-select-title">Game</h2></div></header>
        {games === undefined ? <p className={styles.muted}>Loading…</p> : (
          <div className={styles.controls} role="group" aria-label="Select a game">
            {games.map((game) => (
              <Button
                key={game.game_code}
                variant={selectedGameCode === game.game_code ? "primary" : "secondary"}
                onClick={() => setSelectedGameCode(game.game_code)}
              >
                {game.game_code}
              </Button>
            ))}
          </div>
        )}
      </section>

      <section className={styles.section} aria-labelledby="configs-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="configs-title">Economics configs for {selectedGameCode ?? "this game"}</h2><p>Drafts can be edited or deleted here. Published configs are permanent history.</p></div>
          <Button leadingIcon={<Icon name="activity" />} onClick={startCreateDraft}>New draft</Button>
        </header>
        {configsError && <p className={styles.fieldError} role="alert"><Icon name="error" />{configsError}</p>}
        {configs === undefined ? <p className={styles.muted}>Loading…</p> : configs.length === 0 ? (
          <div className={styles.emptyState}><p>No economics configs yet for this game.</p></div>
        ) : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Economics configs for {selectedGameCode}</caption>
              <thead><tr><th scope="col">Version</th><th scope="col">Model</th><th scope="col">Status</th><th scope="col">Effective</th><th scope="col">Actions</th></tr></thead>
              <tbody>{configs.map((config) => (
                <tr key={config.id}>
                  <th scope="row" data-label="Version"><strong>{config.version}</strong></th>
                  <td data-label="Model">{config.active_model}</td>
                  <td data-label="Status"><span className={styles.statusLabel}><Icon name={config.status === "published" ? "check" : "warning"} />{config.status}</span></td>
                  <td data-label="Effective">{new Date(config.effective_at).toLocaleString()}</td>
                  <td data-label="Actions">
                    {config.status === "draft" ? (
                      <>
                        <button className={styles.tableAction} type="button" onClick={() => startEditDraft(config)}>Edit</button>
                        {" · "}
                        <button className={styles.tableAction} type="button" onClick={() => { setDeleteTarget(config); setDeleteError(""); }}>Delete</button>
                      </>
                    ) : <span className={styles.muted}>—</span>}
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
      </section>

      <section className={styles.section} aria-labelledby="drafting-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="drafting-title">{draftId === null ? "Draft an economics config" : `Editing draft #${draftId}`}</h2>
            <p>{draftId === null ? "Creating a draft is direct (not maker-checker gated); publishing it is a separate, gated action below." : "Saving replaces this draft entirely. It has not been published, so this is safe."}</p>
          </div>
        </header>

        <div className={styles.controls}>
          <label className={styles.formField} htmlFor="draft-version">Version <span aria-hidden="true">*</span><input id="draft-version" value={draftVersion} onChange={(event) => setDraftVersion(event.target.value)} placeholder="GEC-1" /></label>
          <label className={styles.formField} htmlFor="draft-model">Model<select id="draft-model" value={draftModel} onChange={(event) => setDraftModel(event.target.value as EconomicsModel)}>{MODELS.map((model) => <option key={model} value={model}>{model}</option>)}</select></label>
          {draftModel === "BALANCED_HYBRID" && (
            <label className={styles.formField} htmlFor="draft-kelly">Kelly factor (basis points)<input id="draft-kelly" type="number" min="1" max="2000" value={draftKellyFactor} onChange={(event) => setDraftKellyFactor(event.target.value)} /></label>
          )}
          <label className={styles.formField} htmlFor="draft-effective">Effective at <span aria-hidden="true">*</span><input id="draft-effective" type="datetime-local" value={draftEffectiveAt} onChange={(event) => setDraftEffectiveAt(event.target.value)} /></label>
        </div>

        <div className={styles.controls}>
          <Button leadingIcon={<Icon name="activity" />} onClick={submitDraft}>{draftId === null ? "Create draft" : "Save changes"}</Button>
          {draftId !== null && <Button variant="secondary" onClick={startCreateDraft}>Start a new draft instead</Button>}
        </div>
        {draftError && <p className={styles.fieldError} role="alert"><Icon name="error" />{draftError}</p>}

        {draftResult && (
          <>
            <ul className={styles.validationList} aria-label="Economics model gate results">
              {draftResult.gate_errors.length === 0 ? (
                <li><Icon name="check" /><div><strong>Passes the gate</strong><span>Model and parameters check out.</span></div></li>
              ) : draftResult.gate_errors.map((error) => (
                <li key={error}><Icon name="error" /><div><strong>Blocked</strong><span>{error}</span></div></li>
              ))}
            </ul>
            <section className={styles.actionBand} aria-labelledby="publication-title">
              <div><h2 id="publication-title">Propose for approval</h2><p>Routes this draft to Change approvals. The active model stays in force until a different operator approves.</p></div>
              <Button leadingIcon={<Icon name="activity" />} onClick={proposePublication} disabled={draftResult.gate_errors.length > 0}>Propose publication</Button>
            </section>
          </>
        )}
      </section>

      <Dialog open={Boolean(deleteTarget)} title={`Delete draft ${deleteTarget?.version ?? ""}?`} confirmLabel="Delete draft" onClose={() => setDeleteTarget(undefined)} onConfirm={runDelete}>
        <div className={styles.dialogBody}>
          <p>This permanently removes the draft. Published configs can never be deleted — only a draft that has not yet been proposed and approved.</p>
          {deleteError && <p className={styles.fieldError} role="alert"><Icon name="error" />{deleteError}</p>}
        </div>
      </Dialog>
    </div>
  );
}
