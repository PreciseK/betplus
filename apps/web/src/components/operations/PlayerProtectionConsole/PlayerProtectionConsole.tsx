"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { backOfficeGateway, type BackOfficeLimitUsage, type BackOfficeProtectionEvent, type BackOfficeVelocityReview } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Icon } from "@/components/ui/Icon/Icon";
import { EmptyState } from "@/components/operations/EmptyState/EmptyState";
import { formatKobo } from "@/lib/money";
import styles from "@/components/operations/OperationsConsole.module.css";

export type PlayerProtectionView = "reviews" | "limits" | "exclusions";

function playerLink(playerId: number, reference: string) {
  return (
    <Link className={styles.tableAction} href={`/back-office/player-responsible-play?player=${encodeURIComponent(reference)}`}>
      View player
    </Link>
  );
}

const VIEW_META: Record<PlayerProtectionView, { title: string; description: string }> = {
  reviews: { title: "Safer play reviews", description: "Real velocity flags awaiting a human decision." },
  limits: { title: "Player limits", description: "Players who have reached or are near a real deposit or stake limit." },
  exclusions: { title: "Exclusions", description: "Active self-exclusions and cool-offs, read live from playerProtectionEvent." },
};

export function PlayerProtectionConsole({ view }: { view: PlayerProtectionView }) {
  const [reviews, setReviews] = useState<BackOfficeVelocityReview[]>();
  const [limits, setLimits] = useState<BackOfficeLimitUsage[]>();
  const [exclusions, setExclusions] = useState<BackOfficeProtectionEvent[]>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [receipt, setReceipt] = useState("");
  const [resolvingId, setResolvingId] = useState<number>();

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    let active = true;
    setReceipt("");
    const load = view === "reviews" ? backOfficeGateway.playerProtectionReviews()
      : view === "limits" ? backOfficeGateway.playerProtectionLimits()
      : backOfficeGateway.playerProtectionExclusions();

    load.then((result) => {
      if (!active) return;
      if ("reviews" in result) setReviews(result.reviews);
      if ("limits" in result) setLimits(result.limits);
      if ("exclusions" in result) setExclusions(result.exclusions);
    }).catch(() => {
      if (active) setLoadFailed(true);
    });
    return () => { active = false; };
  }, [view]);

  async function resolveReview(id: number) {
    setResolvingId(id);
    try {
      await backOfficeGateway.resolveVelocityFlag(id);
      setReviews((current) => current?.filter((review) => review.id !== id));
      setReceipt(`Review #${id} marked resolved.`);
    } catch {
      setReceipt("Could not resolve that review. Please try again.");
    } finally {
      setResolvingId(undefined);
    }
  }

  const meta = VIEW_META[view];
  const rowCount = useMemo(() => {
    if (view === "reviews") return reviews?.length ?? 0;
    if (view === "limits") return limits?.length ?? 0;
    return exclusions?.length ?? 0;
  }, [view, reviews, limits, exclusions]);

  if (loadFailed) {
    return (
      <div className={styles.page}>
        <EmptyState
          icon="error"
          title="Could not load player protection records"
          description="Live safer play data could not be loaded from the back-office gateway. Try refreshing or signing in again."
        />
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Player protection</p>
          <h1>{meta.title}</h1>
          <p>{meta.description}</p>
        </div>
      </header>

      {receipt && <div className={styles.receipt} role="status"><Icon name="check" />{receipt}</div>}

      <section className={styles.section} aria-labelledby={`${view}-queue-title`}>
        <header className={styles.sectionHeader}>
          <div>
            <h2 id={`${view}-queue-title`}>{meta.title}</h2>
            <p>{rowCount} records in this view.</p>
          </div>
        </header>

        {view === "reviews" && (
          reviews === undefined ? <p className={styles.muted}>Loading…</p> : reviews.length > 0 ? (
            <div className={styles.tableWrap}>
              <table className={styles.table}>
                <caption className="sr-only">Safer play reviews needing a decision</caption>
                <thead><tr><th scope="col">Player</th><th scope="col">Flag</th><th scope="col">Detail</th><th scope="col">Opened</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
                <tbody>{reviews.map((review) => (
                  <tr key={review.id}>
                    <th scope="row" data-label="Player"><strong>{review.registered_name ?? review.player_reference}</strong><span>{review.player_reference}</span></th>
                    <td data-label="Flag">{review.flag_type.replaceAll("_", " ")}</td>
                    <td data-label="Detail">{review.detail}</td>
                    <td data-label="Opened">{new Date(review.created_at).toLocaleString()}</td>
                    <td data-label="Actions" style={{ display: "flex", gap: "8px" }}>
                      {playerLink(review.player_id, review.player_reference)}
                      <Button variant="secondary" onClick={() => resolveReview(review.id)} status={resolvingId === review.id ? "loading" : "idle"}>
                        Mark resolved
                      </Button>
                    </td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          ) : (
            <EmptyState
              icon="check"
              title="No open reviews"
              description="Every flagged velocity and stake escalation event has been actioned."
            />
          )
        )}

        {view === "limits" && (
          limits === undefined ? <p className={styles.muted}>Loading…</p> : limits.length > 0 ? (
            <div className={styles.tableWrap}>
              <table className={styles.table}>
                <caption className="sr-only">Players near or at a real deposit or stake limit</caption>
                <thead><tr><th scope="col">Player</th><th scope="col">Limit</th><th scope="col">Usage</th><th scope="col">Status</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
                <tbody>{limits.map((row) => (
                  <tr key={`${row.player_id}-${row.limit_key}`}>
                    <th scope="row" data-label="Player"><strong>{row.registered_name ?? row.player_reference}</strong><span>{row.player_reference}</span></th>
                    <td data-label="Limit">{row.limit_key}</td>
                    <td data-label="Usage">{row.unit === "kobo" ? `${formatKobo(row.spent_kobo)} of ${formatKobo(row.limit_value)}` : `${row.spent_kobo} of ${row.limit_value} ${row.unit}`}</td>
                    <td data-label="Status"><span className={styles.statusLabel}>{row.status === "reached" ? "Reached" : "Near threshold"}</span></td>
                    <td data-label="Actions">{playerLink(row.player_id, row.player_reference)}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          ) : (
            <EmptyState
              icon="limits"
              title="No players near a limit"
              description="Nobody with a tracked deposit or stake limit is currently near or at their threshold."
            />
          )
        )}

        {view === "exclusions" && (
          exclusions === undefined ? <p className={styles.muted}>Loading…</p> : exclusions.length > 0 ? (
            <div className={styles.tableWrap}>
              <table className={styles.table}>
                <caption className="sr-only">Active self-exclusions and cool-offs</caption>
                <thead><tr><th scope="col">Player</th><th scope="col">Type</th><th scope="col">Started</th><th scope="col">Ends</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
                <tbody>{exclusions.map((event) => (
                  <tr key={event.id}>
                    <th scope="row" data-label="Player"><strong>{event.registered_name ?? event.player_reference}</strong><span>{event.player_reference}</span></th>
                    <td data-label="Type">{event.type === "self-exclusion" ? "Self-exclusion" : "Cool-off"}</td>
                    <td data-label="Started">{event.started_at ? new Date(event.started_at).toLocaleDateString("en-NG") : "—"}</td>
                    <td data-label="Ends">{event.ends_at ? new Date(event.ends_at).toLocaleDateString("en-NG") : "Indefinite"}</td>
                    <td data-label="Actions">{playerLink(event.player_id, event.player_reference)}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          ) : (
            <EmptyState
              icon="lock"
              title="No active exclusions"
              description="No player currently has an active cool-off or self-exclusion registered."
            />
          )
        )}
      </section>
    </div>
  );
}
