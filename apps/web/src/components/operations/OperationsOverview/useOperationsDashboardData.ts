"use client";

import { useEffect, useMemo, useState } from "react";
import {
  backOfficeGateway,
  type BackOfficeChange,
  type BackOfficeGame,
  type BackOfficeJurisdiction,
  type BackOfficeReconciliationException,
  type BackOfficeRollupRow,
} from "@betplus/api-client";
import { OPERATOR_DASHBOARDS, type DashboardAnalytics, type DashboardMetric, type DashboardQueueItem } from "@/mocks/operator-dashboards";
import type { OperatorRole } from "@/mocks/operator-session";
import type { OperationsGameScope } from "@/components/operations/operations-games";

export interface OperationsDashboardData {
  metrics: readonly DashboardMetric[];
  analytics: DashboardAnalytics;
  queue: readonly DashboardQueueItem[];
  rail: readonly { label: string; value: string }[];
  source: "live" | "preview";
  partial: boolean;
}

function shiftDate(value: string, days: number) {
  const date = new Date(`${value}T12:00:00`);
  date.setDate(date.getDate() + days);
  return date.toISOString().slice(0, 10);
}

function titleCase(value: string) {
  return value.replaceAll("_", " ").replace(/\b\w/g, (character) => character.toUpperCase());
}

function relativeAge(value: string | null) {
  if (!value) return "Pending";
  const minutes = Math.max(1, Math.round((Date.now() - new Date(value).getTime()) / 60_000));
  if (minutes < 60) return `${minutes} min`;
  if (minutes < 1_440) return `${Math.round(minutes / 60)} hr`;
  return `${Math.round(minutes / 1_440)} days`;
}

function eventTotal(rows: readonly BackOfficeRollupRow[], eventName: string) {
  return rows.filter((row) => row.event_name === eventName).reduce((sum, row) => sum + row.event_count, 0);
}

function buildTrend(rows: readonly BackOfficeRollupRow[], from: string, to: string): DashboardAnalytics {
  const points: Array<{ label: string; primary: number; comparison: number }> = [];
  let cursor = from;
  while (cursor <= to) {
    const daily = rows.filter((row) => row.day === cursor);
    points.push({
      label: new Intl.DateTimeFormat("en-NG", { weekday: "short" }).format(new Date(`${cursor}T12:00:00`)),
      primary: eventTotal(daily, "ticket_purchased"),
      comparison: eventTotal(daily, "deposit_completed"),
    });
    cursor = shiftDate(cursor, 1);
  }
  return {
    title: "Paid plays and deposits",
    summary: "Completed events from the pre-aggregated operations rollup. No raw play-path events are queried.",
    primaryLabel: "Paid plays",
    comparisonLabel: "Deposits",
    points,
  };
}

function queueFromLiveData(
  changes: readonly BackOfficeChange[],
  exceptions: readonly BackOfficeReconciliationException[],
  jurisdictions: readonly BackOfficeJurisdiction[],
  games: readonly BackOfficeGame[],
) {
  const queue: DashboardQueueItem[] = [
    ...exceptions.map((item) => ({
      id: `REC-${item.id}`,
      task: titleCase(item.check_type),
      detail: `${item.subject_id} · ${item.severity} reconciliation exception`,
      status: "Review",
      age: relativeAge(item.detected_at),
      href: "/back-office/money",
    })),
    ...jurisdictions.filter((item) => item.is_expired || item.expiry_alert).map((item) => ({
      id: `LIC-${item.state_code}`,
      task: `${item.state_code} licence`,
      detail: item.is_expired ? "Licence has expired" : `Renewal due ${item.expires_at}`,
      status: item.is_expired ? "Expired" : "Due soon",
      age: item.expires_at,
      href: "/back-office/jurisdictions",
    })),
    ...games.filter((item) => item.status !== "active").map((item) => ({
      id: `GAME-${item.game_code}`,
      task: `${titleCase(item.game_code)} availability`,
      detail: `Runtime status is ${item.status}`,
      status: titleCase(item.status),
      age: "Current",
      href: "/back-office/games",
    })),
    ...changes.map((item) => ({
      id: `CHG-${item.id}`,
      task: titleCase(item.change_type),
      detail: item.maker_justification || "Independent approval required",
      status: "Approval",
      age: relativeAge(item.submitted_at),
      href: "/back-office/overview/approvals",
    })),
  ];
  return queue.slice(0, 5);
}

function liveMetrics(
  role: OperatorRole,
  rows: readonly BackOfficeRollupRow[],
  changes: readonly BackOfficeChange[],
  exceptions: readonly BackOfficeReconciliationException[],
  jurisdictions: readonly BackOfficeJurisdiction[],
  games: readonly BackOfficeGame[],
): DashboardMetric[] {
  const paidPlays = eventTotal(rows, "ticket_purchased");
  const registrations = eventTotal(rows, "player_registered");
  const deposits = eventTotal(rows, "deposit_completed");
  const payouts = eventTotal(rows, "payout_completed");
  const base: Record<string, DashboardMetric> = {
    plays: { label: "Paid plays", value: paidPlays.toLocaleString("en-NG"), change: "Settled event volume", context: "Selected day" },
    players: { label: "New players", value: registrations.toLocaleString("en-NG"), change: "Completed registrations", context: "Selected day" },
    deposits: { label: "Deposits", value: deposits.toLocaleString("en-NG"), change: "Completed funding events", context: "Selected day" },
    payouts: { label: "Payouts", value: payouts.toLocaleString("en-NG"), change: "Completed payout events", context: "Selected day" },
    approvals: { label: "Needs approval", value: changes.length.toLocaleString("en-NG"), change: "Independent checker required", context: "Open maker-checker changes" },
    exceptions: { label: "Open exceptions", value: exceptions.length.toLocaleString("en-NG"), change: "Reconciliation review", context: "Unresolved entries" },
    licences: { label: "Licence alerts", value: jurisdictions.filter((item) => item.is_expired || item.expiry_alert).length.toLocaleString("en-NG"), change: "Expired or due within 60 days", context: "Licensed states" },
    games: { label: "Live games", value: games.filter((item) => item.status === "active").length.toLocaleString("en-NG"), change: `${games.length} registered`, context: "Runtime registry" },
  };

  if (role === "finance") return [base.deposits, base.payouts, base.exceptions];
  if (role === "compliance") return [base.plays, base.licences, base.approvals];
  if (role === "game-ops" || role === "content-editor" || role === "cultural-reviewer") return [base.plays, base.games, base.approvals];
  if (role === "support-agent" || role === "support-lead") return [base.players, base.plays, base.approvals];
  return [base.plays, base.players, base.approvals];
}

function previewData(role: OperatorRole): OperationsDashboardData {
  const dashboard = OPERATOR_DASHBOARDS[role];
  return {
    metrics: dashboard.metrics.slice(0, 3),
    analytics: dashboard.analytics,
    queue: dashboard.queue,
    rail: [
      { label: "Items requiring action", value: String(dashboard.queue.length) },
      { label: "Data scope", value: "Role preview" },
      { label: "Service status", value: "API sign-in required" },
    ],
    source: "preview",
    partial: false,
  };
}

export function useOperationsDashboardData(role: OperatorRole, game: OperationsGameScope, date: string) {
  const fallback = useMemo(() => previewData(role), [role]);
  const [refreshKey, setRefreshKey] = useState(0);
  const requestKey = `${role}:${game}:${date}:${refreshKey}`;
  const [liveState, setLiveState] = useState<{
    key: string;
    data?: OperationsDashboardData;
    loading: boolean;
    error: string | null;
  } | null>(null);

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) return;

    const from = shiftDate(date, -6);
    const gameCode = game === "all" ? undefined : game.toUpperCase();
    const canReadReconciliation = ["finance", "compliance", "system-admin", "super-admin"].includes(role);
    let active = true;

    Promise.resolve().then(() => {
      if (!active) return null;
      setLiveState({ key: requestKey, loading: true, error: null });
      return Promise.allSettled([
        backOfficeGateway.rollups({ from, to: date, game_code: gameCode }),
        backOfficeGateway.changes(),
        backOfficeGateway.games(),
        backOfficeGateway.jurisdictions(),
        canReadReconciliation ? backOfficeGateway.reconciliation() : Promise.resolve({ exceptions: [] }),
      ]);
    }).then((results) => {
      if (!results || !active) return;
      if (!active) return;
      const [rollupsResult, changesResult, gamesResult, jurisdictionsResult, reconciliationResult] = results;
      const successful = results.filter((result) => result.status === "fulfilled").length;
      if (rollupsResult.status === "rejected" || successful === 0) {
        setLiveState({ key: requestKey, loading: false, error: "Live operations data could not be loaded. Preview data remains visible." });
        return;
      }

      const rows = rollupsResult.value.rows;
      const changes = changesResult.status === "fulfilled" ? changesResult.value.changes : [];
      const games = gamesResult.status === "fulfilled" ? gamesResult.value.games : [];
      const jurisdictions = jurisdictionsResult.status === "fulfilled" ? jurisdictionsResult.value.states : [];
      const exceptions = reconciliationResult.status === "fulfilled" ? reconciliationResult.value.exceptions : [];
      const queue = queueFromLiveData(changes, exceptions, jurisdictions, games);
      setLiveState({
        key: requestKey,
        loading: false,
        error: null,
        data: {
          metrics: liveMetrics(role, rows.filter((row) => row.day === date), changes, exceptions, jurisdictions, games),
          analytics: buildTrend(rows, from, date),
          queue,
          rail: [
            { label: "Open approvals", value: String(changes.length) },
            { label: "Reconciliation exceptions", value: String(exceptions.length) },
            { label: "Licence alerts", value: String(jurisdictions.filter((item) => item.is_expired || item.expiry_alert).length) },
          ],
          source: "live",
          partial: successful < results.length,
        },
      });
    }).catch(() => {
      if (!active) return;
      setLiveState({ key: requestKey, loading: false, error: "Live operations data could not be loaded. Preview data remains visible." });
    });

    return () => { active = false; };
  }, [date, game, refreshKey, requestKey, role]);

  const current = liveState?.key === requestKey ? liveState : null;
  return {
    data: current?.data ?? fallback,
    loading: current?.loading ?? false,
    error: current?.error ?? null,
    refresh: () => setRefreshKey((value) => value + 1),
  };
}
