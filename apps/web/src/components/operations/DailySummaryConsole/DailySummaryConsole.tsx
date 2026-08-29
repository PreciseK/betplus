"use client";

import { useEffect, useMemo, useState } from "react";
import { backOfficeGateway, type BackOfficeDailySummary } from "@betplus/api-client";
import { isOperationsGameScope, operationsGameLabel } from "@/components/operations/operations-games";
import {
  OperationalDataTable,
  type OperationalColumn,
} from "@/components/operations/OperationalDataTable/OperationalDataTable";
import { useOperationsUrlFilters } from "@/components/operations/useOperationsUrlFilters";
import { Icon } from "@/components/ui/Icon/Icon";
import { formatKobo } from "@/lib/money";
import styles from "@/components/operations/OperationsConsole.module.css";

interface DailyMetric {
  id: string;
  metric: string;
  current: number;
  previous: number;
  format: "money" | "integer";
  group: MetricGroup;
  note?: string;
}

type MetricGroup = "money" | "players" | "controls";

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

const URL_DEFAULTS = { game: "all", date: todayIso() };

const METRIC_GROUPS: readonly { id: MetricGroup; label: string }[] = [
  { id: "money", label: "Money" },
  { id: "players", label: "Players" },
  { id: "controls", label: "Controls" },
];

function shiftDate(value: string, amount: number) {
  const date = new Date(`${value}T12:00:00`);
  date.setDate(date.getDate() + amount);
  return date.toISOString().slice(0, 10);
}

function dateLabel(value: string) {
  return new Intl.DateTimeFormat("en-NG", { day: "numeric", month: "short", year: "numeric" }).format(new Date(`${value}T12:00:00`));
}

function formatMetric(value: number, format: DailyMetric["format"]) {
  return format === "money" ? formatKobo(value) : value.toLocaleString("en-NG");
}

function buildMetrics(current: BackOfficeDailySummary, previous?: BackOfficeDailySummary): DailyMetric[] {
  return [
    { id: "stakes", metric: "Gross stakes", current: current.gross_stakes_kobo, previous: previous?.gross_stakes_kobo ?? 0, format: "money", group: "money" },
    { id: "wins", metric: "Gross wins", current: current.gross_wins_kobo, previous: previous?.gross_wins_kobo ?? 0, format: "money", group: "money" },
    { id: "revenue", metric: "Net gaming revenue", current: current.net_gaming_revenue_kobo, previous: previous?.net_gaming_revenue_kobo ?? 0, format: "money", group: "money" },
    { id: "deposits", metric: "Deposits received", current: current.deposits_kobo, previous: previous?.deposits_kobo ?? 0, format: "money", group: "money", note: "Platform-wide, not scoped to the selected game" },
    { id: "payouts", metric: "Payouts completed", current: current.payouts_kobo, previous: previous?.payouts_kobo ?? 0, format: "money", group: "money", note: "Platform-wide, not scoped to the selected game" },
    { id: "players", metric: "Active players", current: current.active_players, previous: previous?.active_players ?? 0, format: "integer", group: "players", note: "Distinct ticket purchasers that day" },
    { id: "new-players", metric: "New players", current: current.new_players, previous: previous?.new_players ?? 0, format: "integer", group: "players" },
    { id: "verified-players", metric: "KYC verified (platform total)", current: current.verified_players_total, previous: previous?.verified_players_total ?? 0, format: "integer", group: "players", note: "Point-in-time total, not new verifications that day" },
    { id: "reviews", metric: "Safer play reviews opened", current: current.safer_play_reviews_opened, previous: previous?.safer_play_reviews_opened ?? 0, format: "integer", group: "controls" },
    { id: "exceptions", metric: "Reconciliation exceptions", current: current.reconciliation_exceptions, previous: previous?.reconciliation_exceptions ?? 0, format: "integer", group: "controls" },
    { id: "payout-holds", metric: "Payouts held for review", current: current.payout_holds, previous: previous?.payout_holds ?? 0, format: "integer", group: "controls" },
  ];
}

export function DailySummaryConsole() {
  const today = todayIso();
  const { filters, updateFilter } = useOperationsUrlFilters(URL_DEFAULTS);
  const selectedGame = isOperationsGameScope(filters.game) ? filters.game : "all";
  const selectedDate = /^\d{4}-\d{2}-\d{2}$/.test(filters.date) ? filters.date : today;
  const previousDate = shiftDate(selectedDate, -1);
  const [metricGroup, setMetricGroup] = useState<MetricGroup>("money");
  const activeGroup = METRIC_GROUPS.find((group) => group.id === metricGroup) ?? METRIC_GROUPS[0];

  const [current, setCurrent] = useState<BackOfficeDailySummary>();
  const [previous, setPrevious] = useState<BackOfficeDailySummary>();
  const [loadFailed, setLoadFailed] = useState(false);

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    let active = true;
    const gameCode = selectedGame === "all" ? undefined : selectedGame.toUpperCase();
    Promise.all([
      backOfficeGateway.dailySummary(selectedDate, gameCode),
      backOfficeGateway.dailySummary(previousDate, gameCode),
    ])
      .then(([currentResult, previousResult]) => {
        if (!active) return;
        setCurrent(currentResult);
        setPrevious(previousResult);
      })
      .catch(() => {
        if (active) setLoadFailed(true);
      });
    return () => { active = false; };
  }, [selectedDate, previousDate, selectedGame]);

  const rows = useMemo(
    () => (current ? buildMetrics(current, previous) : []).filter((metric) => metric.group === metricGroup),
    [current, previous, metricGroup],
  );

  const columns = useMemo<readonly OperationalColumn<DailyMetric>[]>(() => [
    {
      id: "metric",
      label: "Metric",
      cell: (row) => (<>{row.metric}{row.note && <span className={styles.muted}> · {row.note}</span>}</>),
      sortValue: (row) => row.metric,
    },
    { id: "current", label: dateLabel(selectedDate), cell: (row) => formatMetric(row.current, row.format), sortValue: (row) => row.current, align: "end" },
    { id: "previous", label: dateLabel(previousDate), cell: (row) => formatMetric(row.previous, row.format), sortValue: (row) => row.previous, align: "end" },
    {
      id: "change",
      label: "Change",
      cell: (row) => {
        const change = row.previous === 0 ? 0 : ((row.current - row.previous) / row.previous) * 100;
        return `${change >= 0 ? "+" : ""}${change.toFixed(1)}%`;
      },
      sortValue: (row) => row.current - row.previous,
      align: "end",
    },
  ], [previousDate, selectedDate]);

  if (loadFailed) {
    return (
      <div className={styles.page}>
        <p className={styles.muted}>Live daily summary data could not be loaded. Sign in again if this persists.</p>
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Insights</p>
          <h1>Daily summary</h1>
          <p>{operationsGameLabel(selectedGame)} performance for one selected operating day.</p>
        </div>
        <div className={styles.controls} aria-label="Daily summary date controls">
          <button className={styles.tableJump} type="button" onClick={() => updateFilter("date", previousDate)}>
            <Icon name="arrow-left" /> Previous day
          </button>
          <label className={styles.formField} htmlFor="daily-summary-date">
            Data date
            <input id="daily-summary-date" type="date" max={today} value={selectedDate} onChange={(event) => updateFilter("date", event.target.value)} />
          </label>
          {selectedDate !== today && <button className={styles.tableJump} type="button" onClick={() => updateFilter("date", today)}>Today</button>}
        </div>
      </header>

      <nav className={styles.subNavigation} aria-label="Daily summary sections">
        {METRIC_GROUPS.map((group) => (
          <button
            key={group.id}
            type="button"
            aria-current={metricGroup === group.id ? "page" : undefined}
            onClick={() => setMetricGroup(group.id)}
          >
            {group.label}
          </button>
        ))}
      </nav>

      <section className={styles.section} aria-labelledby="daily-metrics-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="daily-metrics-title">{activeGroup.label} metrics</h2>
            <p>Selected day compared with the immediately previous day. {rows.length} metrics shown.</p>
          </div>
        </header>
        <OperationalDataTable
          key={`${selectedGame}-${selectedDate}-${metricGroup}`}
          caption={`Daily operating metrics for ${dateLabel(selectedDate)}`}
          columns={columns}
          rows={rows}
          getRowId={(row) => row.id}
          defaultSort={{ id: "metric", direction: "ascending" }}
        />
      </section>
    </div>
  );
}
