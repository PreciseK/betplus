"use client";

import Link from "next/link";
import { Icon } from "@/components/ui/Icon/Icon";
import { OPERATOR_ROLE_LABELS } from "@/components/operations/operations-navigation";
import { useOperationsUrlFilters } from "@/components/operations/useOperationsUrlFilters";
import { OPERATOR_DASHBOARDS, type DashboardAnalytics } from "@/mocks/operator-dashboards";
import { OPERATOR_ROLES, type OperatorRole } from "@/mocks/operator-session";
import { isOperationsGameScope, operationsGameLabel, type OperationsGameScope } from "@/components/operations/operations-games";
import { useOperationsDashboardData } from "./useOperationsDashboardData";
import styles from "./OperationsOverview.module.css";

interface OperationsOverviewProps { role: OperatorRole; }

const DASHBOARD_TODAY = "2026-08-20";
const PREVIEW_DEFAULTS = { viewRole: "super-admin", game: "all", date: DASHBOARD_TODAY };
const chartWidth = 720;
const chartHeight = 210;
const chartPadding = 28;

const recentActivity = [
  { action: "BlackRed prize table approved", detail: "Tobi Akinwale · 12 minutes ago" },
  { action: "OPay reconciliation export completed", detail: "Chiamaka Obi · 28 minutes ago" },
  { action: "Player welfare case assigned", detail: "Nkiru Eze · 41 minutes ago" },
] as const;

function isOperatorRole(value: string): value is OperatorRole {
  return (OPERATOR_ROLES as readonly string[]).includes(value);
}

function displayValue(analytics: DashboardAnalytics, value: number) {
  return `${analytics.valuePrefix ?? ""}${Number.isInteger(value) ? value.toLocaleString("en-NG") : value.toFixed(1)}${analytics.valueSuffix ?? ""}`;
}

function dateLabel(value: string, format: "long" | "short" = "long") {
  const date = new Date(`${value}T12:00:00`);
  if (Number.isNaN(date.getTime())) return "Selected day";
  return new Intl.DateTimeFormat("en-NG", format === "long"
    ? { weekday: "long", day: "numeric", month: "long" }
    : { day: "numeric", month: "short" }).format(date);
}

function shiftDate(value: string, amount: number) {
  const date = new Date(`${value}T12:00:00`);
  date.setDate(date.getDate() + amount);
  return date.toISOString().slice(0, 10);
}

function belongsToGame(text: string, game: OperationsGameScope) {
  if (game === "all") return true;
  const otherGame = game === "blackred" ? "heritage" : "blackred";
  return !text.toLowerCase().includes(otherGame);
}

function scopedHref(href: string, game: OperationsGameScope) {
  return game === "all" ? href : `${href}?game=${game}`;
}

function PerformanceChart({ analytics, periodLabel }: { analytics: DashboardAnalytics; periodLabel: string }) {
  const allValues = analytics.points.flatMap((point) => [point.primary, point.comparison]);
  const maximum = Math.max(...allValues, 1);
  const usableWidth = chartWidth - chartPadding * 2;
  const usableHeight = chartHeight - chartPadding * 2;
  const x = (index: number) => chartPadding + (index * usableWidth) / Math.max(analytics.points.length - 1, 1);
  const y = (value: number) => chartHeight - chartPadding - (value / maximum) * usableHeight;
  const primaryPoints = analytics.points.map((point, index) => `${x(index)},${y(point.primary)}`).join(" ");
  const comparisonPoints = analytics.points.map((point, index) => `${x(index)},${y(point.comparison)}`).join(" ");

  return (
    <figure className={styles.performance} aria-labelledby="performance-title" aria-describedby="performance-summary">
      <div className={styles.sectionHeader}>
        <div><h2 id="performance-title">Performance</h2><p id="performance-summary">{analytics.summary}</p></div>
        <span className={styles.period}>{periodLabel}</span>
      </div>
      <div className={styles.legend} aria-label="Chart legend">
        <span><i data-series="primary" aria-hidden="true" />{analytics.primaryLabel}</span>
        <span><i data-series="comparison" aria-hidden="true" />{analytics.comparisonLabel}</span>
      </div>
      <svg viewBox={`0 0 ${chartWidth} ${chartHeight}`} role="img" aria-label={`${analytics.title}. ${analytics.summary}`}>
        <title>{analytics.title}</title>
        {[0, .5, 1].map((ratio) => <line key={ratio} className={styles.gridLine} x1={chartPadding} x2={chartWidth - chartPadding} y1={chartPadding + usableHeight * ratio} y2={chartPadding + usableHeight * ratio} />)}
        <polyline className={styles.comparisonLine} points={comparisonPoints} />
        <polyline className={styles.primaryLine} points={primaryPoints} />
        {analytics.points.map((point, index) => (
          <g key={`${point.label}-${index}`}>
            <circle className={styles.comparisonPoint} cx={x(index)} cy={y(point.comparison)} r="5"><title>{`${point.label}: ${analytics.comparisonLabel} ${displayValue(analytics, point.comparison)}`}</title></circle>
            <circle className={styles.primaryPoint} cx={x(index)} cy={y(point.primary)} r="5"><title>{`${point.label}: ${analytics.primaryLabel} ${displayValue(analytics, point.primary)}`}</title></circle>
            <text className={styles.axisLabel} x={x(index)} y={chartHeight - 5} textAnchor="middle">{point.label}</text>
          </g>
        ))}
      </svg>
      <details className={styles.chartData}>
        <summary>View exact figures</summary>
        <div><table><caption>Exact values for {analytics.title.toLowerCase()}</caption><thead><tr><th scope="col">Day</th><th scope="col">{analytics.primaryLabel}</th><th scope="col">{analytics.comparisonLabel}</th></tr></thead><tbody>{analytics.points.map((point, index) => <tr key={`${point.label}-${index}`}><th scope="row">{point.label}</th><td>{displayValue(analytics, point.primary)}</td><td>{displayValue(analytics, point.comparison)}</td></tr>)}</tbody></table></div>
      </details>
    </figure>
  );
}

function contextualTitle(role: OperatorRole) {
  if (role === "compliance") return "Player safety";
  if (role === "finance") return "Finance controls";
  if (role === "game-ops") return "Game health";
  if (role === "support-agent" || role === "support-lead") return "Service health";
  return "Operational status";
}

export function OperationsOverview({ role }: OperationsOverviewProps) {
  const { filters, updateFilter } = useOperationsUrlFilters(PREVIEW_DEFAULTS);
  const previewRole = isOperatorRole(filters.viewRole) ? filters.viewRole : "super-admin";
  const selectedGame = isOperationsGameScope(filters.game) ? filters.game : "all";
  const selectedDate = /^\d{4}-\d{2}-\d{2}$/.test(filters.date) ? filters.date : DASHBOARD_TODAY;
  const dashboardRole = role === "super-admin" ? previewRole : role;
  const dashboard = OPERATOR_DASHBOARDS[dashboardRole];
  const selectedGameLabel = operationsGameLabel(selectedGame);
  const isToday = selectedDate === DASHBOARD_TODAY;
  const { data, loading, error, refresh } = useOperationsDashboardData(dashboardRole, selectedGame, selectedDate);
  const visibleQueue = data.queue.filter((item) => belongsToGame(`${item.task} ${item.detail}`, selectedGame));

  return (
    <div className={styles.page} aria-busy={loading}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.date}>{dateLabel(selectedDate)}</p>
          <h1>{isToday ? "Today at a Glance" : `${dateLabel(selectedDate, "short")} at a Glance`}</h1>
          <p>{selectedGameLabel} · {dashboard.title}</p>
        </div>
        <div className={styles.dashboardControls} aria-label="Dashboard view controls">
          <div className={styles.dateControl}>
            <label htmlFor="dashboard-date">Data date</label>
            <div>
              <button type="button" onClick={() => updateFilter("date", shiftDate(selectedDate, -1))}><Icon name="arrow-left" /> Previous</button>
              <input id="dashboard-date" type="date" max={DASHBOARD_TODAY} value={selectedDate} onChange={(event) => updateFilter("date", event.target.value)} />
              {!isToday && <button type="button" onClick={() => updateFilter("date", DASHBOARD_TODAY)}>Today</button>}
            </div>
          </div>
          {role === "super-admin" && (
            <div className={styles.roleView}>
              <label htmlFor="dashboard-role-preview">Role view</label>
              <select id="dashboard-role-preview" value={dashboardRole} onChange={(event) => updateFilter("viewRole", event.target.value)}>
                {OPERATOR_ROLES.map((option) => <option key={option} value={option}>{OPERATOR_ROLE_LABELS[option]}</option>)}
              </select>
            </div>
          )}
        </div>
      </header>

      <div className={styles.dataStatus} data-source={data.source} role={error ? "alert" : "status"}>
        <span aria-hidden="true" />
        <p>{error ?? (data.source === "live" ? `${data.partial ? "Partial live" : "Live"} endpoint data · refreshed for the selected scope` : "Preview data · sign in through institutional access to load live endpoint data")}</p>
        {(error || data.source === "live") && <button type="button" onClick={refresh}>{loading ? "Refreshing…" : "Refresh"}</button>}
      </div>

      <div className={styles.dashboardGrid}>
        <div className={styles.primaryColumn}>
          <section className={styles.metrics} aria-label="Key metrics">
            {data.metrics.map((metric) => (
              <article key={metric.label}>
                <div className={styles.metricTop}><p>{metric.label}</p><Icon name="activity" /></div>
                <strong>{metric.value}</strong>
                <span className={styles.change}>{metric.change}</span>
                <small>{selectedGame === "all" ? metric.context : `${selectedGameLabel} only · ${metric.context}`}</small>
              </article>
            ))}
          </section>

          <PerformanceChart analytics={data.analytics} periodLabel={`${dateLabel(shiftDate(selectedDate, -6), "short")}–${dateLabel(selectedDate, "short")}`} />

          <section className={styles.attention} aria-labelledby="attention-title">
            <div className={styles.sectionHeader}>
              <div><h2 id="attention-title">Needs attention</h2><p>{dashboard.queueSummary}</p></div>
              <span>{visibleQueue.length}</span>
            </div>
            {visibleQueue.length > 0 ? (
              <ul>{visibleQueue.map((item) => (
                <li key={item.id}>
                  <div><strong>{item.task}</strong><p>{item.detail}</p></div>
                  <div className={styles.itemMeta}><span>{item.status}</span><small>{item.age}</small></div>
                  <Link href={scopedHref(item.href, selectedGame)} aria-label={`Open ${item.task}`}><Icon name="arrow-right" /></Link>
                </li>
              ))}</ul>
            ) : <p className={styles.emptyQueue}>No items require action in this scope.</p>}
          </section>
        </div>

        <aside className={styles.rightRail} aria-label="Dashboard supporting information">
          <section className={styles.statusPanel} aria-labelledby="status-panel-title">
            <div className={styles.sectionHeader}><div><h2 id="status-panel-title">{contextualTitle(dashboardRole)}</h2><p>Only the checks needed for this role.</p></div></div>
            <dl>{data.rail.map((item) => <div key={item.label}><dt>{item.label}</dt><dd>{item.value}</dd></div>)}</dl>
            <Link href={scopedHref(visibleQueue[0]?.href ?? "/back-office/daily-summary", selectedGame)}>Open priority work <Icon name="arrow-right" /></Link>
          </section>

          <section className={styles.activity} aria-labelledby="activity-title">
            <div className={styles.sectionHeader}><div><h2 id="activity-title">Recent activity</h2><p>Latest recorded changes</p></div></div>
            <ul>{recentActivity.filter((item) => belongsToGame(`${item.action} ${item.detail}`, selectedGame)).map((item) => <li key={item.action}><span aria-hidden="true" /><div><strong>{item.action}</strong><p>{item.detail}</p></div></li>)}</ul>
            <Link className={styles.viewAll} href={scopedHref("/back-office/audit-log", selectedGame)}>View audit log</Link>
          </section>
        </aside>
      </div>
    </div>
  );
}
