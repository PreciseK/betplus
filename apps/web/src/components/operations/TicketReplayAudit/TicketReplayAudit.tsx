"use client";

import { type FormEvent, useState } from "react";
import { backOfficeGateway, BackOfficeApiError } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Icon } from "@/components/ui/Icon/Icon";
import { TextField } from "@/components/ui/TextField/TextField";
import { EmptyState } from "@/components/operations/EmptyState/EmptyState";
import styles from "./TicketReplayAudit.module.css";

type ReplayResult = Awaited<ReturnType<typeof backOfficeGateway.ticketReplay>>;
type Outcome = ReplayResult["replayed"]["result"];

function isOutcome(value: unknown): value is Outcome {
  if (Array.isArray(value)) return value.every(item => typeof item === "string");
  if (!value || typeof value !== "object") return false;
  const result = value as { opened?: unknown; multiplier?: unknown };
  return Array.isArray(result.opened) && result.opened.every(index => Number.isInteger(index) && index >= 0)
    && typeof result.multiplier === "number" && Number.isFinite(result.multiplier);
}

function isReplayResult(value: unknown): value is ReplayResult {
  if (!value || typeof value !== "object") return false;
  const result = value as Partial<ReplayResult>;
  return typeof result.reference === "string" && typeof result.seed_hex === "string"
    && typeof result.seed_algorithm === "string" && typeof result.engine_version === "string"
    && Array.isArray(result.prediction) && result.prediction.every(item => typeof item === "string")
    && !!result.stored && (result.stored.result === null || isOutcome(result.stored.result))
    && (result.stored.won === null || typeof result.stored.won === "boolean")
    && (result.stored.digest === null || typeof result.stored.digest === "string")
    && !!result.replayed && isOutcome(result.replayed.result) && typeof result.replayed.won === "boolean"
    && typeof result.replayed.digest === "string" && typeof result.matches === "boolean";
}

function outcomeLabel(outcome: Outcome | null) {
  if (outcome === null) return "—";
  if (Array.isArray(outcome)) return outcome.join("");
  return `Doors: ${outcome.opened.map(index => index + 1).join(", ") || "none"} · ${outcome.multiplier}×`;
}

export function TicketReplayAudit() {
  const [query, setQuery] = useState("");
  const [lastSearch, setLastSearch] = useState("");
  const [result, setResult] = useState<ReplayResult>();
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState("");
  const [isLoading, setIsLoading] = useState(false);

  async function runReplay(rawReference: string) {
    const reference = rawReference.trim().toLocaleUpperCase();
    if (!reference) return;
    setLastSearch(reference);
    setNotFound(false);
    setResult(undefined);
    setError("");
    setIsLoading(true);
    try {
      const data = await backOfficeGateway.ticketReplay(reference);
      if (!isReplayResult(data)) throw new Error("Incomplete replay response");
      setResult(data);
    } catch (err) {
      setResult(undefined);
      if (err instanceof BackOfficeApiError && err.status === 404) {
        setNotFound(true);
      } else {
        setError("Could not run that replay. Please try again.");
      }
    } finally {
      setIsLoading(false);
    }
  }

  function submitReplay(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    runReplay(query);
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Compliance task</p>
          <h1>Ticket replay audit</h1>
          <p>Check a settled BlackRed or Doors ticket against its original result.</p>
        </div>

        <form className={styles.search} role="search" onSubmit={submitReplay}>
          <TextField
            id="ticket-reference"
            type="search"
            label="Ticket reference"
            placeholder="Example: 01J..."
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
          <Button type="submit" leadingIcon={<Icon name="activity" />} disabled={isLoading}>{isLoading ? "Replaying…" : "Check ticket"}</Button>
        </form>
      </header>

      <p className={styles.contractNote}>
        <Icon name="info" />
        Check settled BlackRed and Doors tickets against the original result. Each check is recorded against your operator session.
      </p>

      {error && <p className={styles.fieldError} role="alert"><Icon name="error" />{error}</p>}

      {!result && !notFound && !error && (
        <EmptyState
          icon="ticket"
          title="Enter a ticket reference to audit"
          description="Enter a settled BlackRed or Doors ticket reference to compare the recorded result with a fresh replay."
        />
      )}

      {notFound && (
        <EmptyState
          icon="ticket"
          title={`No replay record for "${lastSearch}"`}
          description="Check the complete Betplus ticket reference. Replay is available for settled BlackRed and Doors tickets."
        />
      )}

      {result && (
        <>
          <section className={styles.integrityStrip} aria-labelledby="integrity-strip-title">
            <Icon name={result.matches ? "lock" : "warning"} size="control" />
            <div>
              <h2 id="integrity-strip-title">{result.matches ? "Settled outcome · verified" : "Digest mismatch"}</h2>
              <p>{result.reference} replayed on engine {result.engine_version}.</p>
            </div>
            <span><Icon name={result.matches ? "check" : "error"} />{result.matches ? "Verified replay" : "Does not match"}</span>
          </section>

          <div className={styles.workspace}>
            <aside className={styles.ticketRail} aria-label="Ticket audit facts">
              <div className={styles.ticketIdentity}>
                <p>{Array.isArray(result.replayed.result) ? "BlackRed" : "Doors"}</p>
                <h2>{result.reference}</h2>
                <span><Icon name={result.stored.won ? "check" : "info"} />Recorded outcome: {result.stored.won === null ? "Not recorded" : result.stored.won ? "Won" : "Lost"}</span>
              </div>

              <dl className={styles.factList}>
                {Array.isArray(result.replayed.result) ? <Fact label="Prediction" value={result.prediction.join("")} /> : <Fact label="Opened doors" value={result.replayed.result.opened.map(index => index + 1).join(", ") || "None"} />}
                <Fact label="Seed algorithm" value={result.seed_algorithm} />
                <Fact label="Engine version" value={result.engine_version} />
              </dl>
            </aside>

            <div className={styles.evidenceStream}>
              <section className={styles.evidenceSection} aria-labelledby="seed-section-title">
                <header><h2 id="seed-section-title">Sealed seed</h2><p>The pre-committed randomness source the engine's replay method takes as input — nothing else determines the outcome.</p></header>
                <div className={styles.hashPair}>
                  <div><span>{result.seed_algorithm} seed</span><code>{result.seed_hex}</code></div>
                </div>
              </section>

              <section className={styles.evidenceSection} aria-labelledby="comparison-title">
                <header><h2 id="comparison-title">Recorded vs. replayed</h2><p>The same seed, prediction and engine version, evaluated twice.</p></header>
                <div className={styles.tableWrap}>
                  <table className={styles.table}>
                    <caption className="sr-only">Recorded outcome compared with a fresh replay</caption>
                    <thead><tr><th scope="col"></th><th scope="col">Result</th><th scope="col">Won</th><th scope="col">Digest</th></tr></thead>
                    <tbody>
                      <tr>
                        <th scope="row">Recorded at settlement</th>
                        <td>{outcomeLabel(result.stored.result)}</td>
                        <td>{result.stored.won === null ? "—" : result.stored.won ? "Yes" : "No"}</td>
                        <td><code>{result.stored.digest ?? "—"}</code></td>
                      </tr>
                      <tr>
                        <th scope="row">Fresh replay</th>
                        <td>{outcomeLabel(result.replayed.result)}</td>
                        <td>{result.replayed.won ? "Yes" : "No"}</td>
                        <td><code>{result.replayed.digest}</code></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p className={result.matches ? styles.verified : styles.fieldError}>
                  <Icon name={result.matches ? "check" : "error"} />
                  {result.matches ? "Exact digest match · no divergence" : "Digests do not match — this ticket needs investigation."}
                </p>
              </section>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>;
}
