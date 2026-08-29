"use client";

import { type FormEvent, useState } from "react";
import { backOfficeGateway, BackOfficeApiError } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Icon } from "@/components/ui/Icon/Icon";
import { TextField } from "@/components/ui/TextField/TextField";
import styles from "./TicketReplayAudit.module.css";

type ReplayResult = Awaited<ReturnType<typeof backOfficeGateway.ticketReplay>>;

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
    setError("");
    setIsLoading(true);
    try {
      const data = await backOfficeGateway.ticketReplay(reference);
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
          <p>Reconstruct a settled BlackRed outcome from its sealed seed and compare it against what was recorded at settlement.</p>
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
          <Button type="submit" leadingIcon={<Icon name="activity" />} disabled={isLoading}>{isLoading ? "Replaying…" : "Run deterministic replay"}</Button>
        </form>
      </header>

      <p className={styles.contractNote}>
        <Icon name="info" />
        Replay covers BlackRed tickets only today — Heritage tickets have no replay endpoint yet. Viewing seed material is attributable to the signed-in operator session.
      </p>

      {error && <p className={styles.fieldError} role="alert"><Icon name="error" />{error}</p>}

      {notFound && (
        <section className={styles.notFound} aria-labelledby="ticket-not-found-title">
          <Icon name="ticket" size="empty" />
          <h2 id="ticket-not-found-title">No replay record for "{lastSearch}"</h2>
          <p>Check the complete Betplus ticket reference. This is a BlackRed-only replay surface today.</p>
        </section>
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
                <p>BlackRed</p>
                <h2>{result.reference}</h2>
                <span><Icon name={result.stored.won ? "check" : "info"} />Recorded outcome: {result.stored.won ? "Won" : "Lost"}</span>
              </div>

              <dl className={styles.factList}>
                <Fact label="Prediction" value={result.prediction.join("")} />
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
                        <td>{result.stored.result?.join("") ?? "—"}</td>
                        <td>{result.stored.won === null ? "—" : result.stored.won ? "Yes" : "No"}</td>
                        <td><code>{result.stored.digest ?? "—"}</code></td>
                      </tr>
                      <tr>
                        <th scope="row">Fresh replay</th>
                        <td>{result.replayed.result.join("")}</td>
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
