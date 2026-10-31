"use client";

import { useEffect, useState } from "react";
import { backOfficeGateway, type BackOfficeFunnel, type BackOfficeRollupRow } from "@betplus/api-client";
import { OperationalDataTable, type OperationalColumn, type OperationalDataState } from "@/components/operations/OperationalDataTable/OperationalDataTable";
import { useOperationsUrlFilters } from "@/components/operations/useOperationsUrlFilters";
import { Icon } from "@/components/ui/Icon/Icon";
import { ANALYTICS_EVENT_FIELDS, ANALYTICS_EXCLUDED_FIELDS } from "@/mocks/operator-analytics";
import styles from "@/components/operations/OperationsConsole.module.css";

// Story 6.10's own six required funnels (App\Domain\Analytics\FunnelService) —
// not a frontend invention. Two are genuinely unmeasurable today (no USSD channel,
// only one game exists) and the service says so rather than faking a number.
const FUNNEL_LABELS: Record<string, string> = {
  acquisition_to_first_paid_play: "Acquisition to first paid play",
  ussd_play: "USSD play",
  funding: "Funding",
  payout: "Payout",
  cross_game: "Cross-game",
  geo_attribution: "Geo attribution",
};
const FUNNEL_ORDER = Object.keys(FUNNEL_LABELS);

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}
function daysAgoIso(days: number) {
  const date = new Date();
  date.setDate(date.getDate() - days);
  return date.toISOString().slice(0, 10);
}

const DEFAULT_FILTERS = {
  funnel: FUNNEL_ORDER[0],
  channel: "ALL",
  game: "ALL",
  state: "ALL",
  from: daysAgoIso(29),
  to: todayIso(),
};

const rollupColumns: readonly OperationalColumn<BackOfficeRollupRow>[] = [
  { id: "day", label: "Day", cell: (row) => row.day, sortValue: (row) => row.day },
  { id: "event", label: "Event", cell: (row) => row.event_name, sortValue: (row) => row.event_name },
  { id: "channel", label: "Channel", cell: (row) => row.channel, sortValue: (row) => row.channel },
  { id: "game", label: "Game", cell: (row) => row.game_code ?? "—", sortValue: (row) => row.game_code ?? "" },
  { id: "state", label: "State", cell: (row) => row.state_code ?? "—", sortValue: (row) => row.state_code ?? "" },
  { id: "count", label: "Events", cell: (row) => row.event_count.toLocaleString("en-NG"), sortValue: (row) => row.event_count, align: "end" },
  { id: "players", label: "Distinct players", cell: (row) => row.distinct_player_count.toLocaleString("en-NG"), sortValue: (row) => row.distinct_player_count, align: "end" },
];

export function AnalyticsConsole() {
  const { filters, updateFilter } = useOperationsUrlFilters(DEFAULT_FILTERS);
  const [funnels, setFunnels] = useState<Record<string, BackOfficeFunnel>>();
  const [rollups, setRollups] = useState<BackOfficeRollupRow[]>();
  const [state, setState] = useState<OperationalDataState>("loading");

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setState("error");
      return;
    }
    let active = true;
    setState("loading");
    Promise.all([
      backOfficeGateway.funnels({ from: filters.from, to: filters.to }),
      backOfficeGateway.rollups({
        from: filters.from, to: filters.to,
        channel: filters.channel === "ALL" ? undefined : filters.channel,
        game_code: filters.game === "ALL" ? undefined : filters.game,
        state_code: filters.state === "ALL" ? undefined : filters.state,
      }),
    ])
      .then(([funnelResult, rollupResult]) => {
        if (!active) return;
        setFunnels(funnelResult.funnels);
        setRollups(rollupResult.rows);
        setState("ready");
      })
      .catch(() => {
        if (active) setState("error");
      });
    return () => { active = false; };
  }, [filters.channel, filters.from, filters.game, filters.state, filters.to]);

  const selectedFunnel = funnels?.[filters.funnel];

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div><p className={styles.context}>Analytics</p><h1>Player analytics</h1><p>Review player activity and conversion by date, game, and channel.</p></div>
      </header>

      <section className={styles.runStrip} aria-labelledby="analytics-store-title">
        <Icon name="lock" size="control" />
        <div><h2 id="analytics-store-title">Data coverage</h2><p>Figures use aggregated activity. Raw events are retained for 25 months.</p></div>
        <span><Icon name="check" />First-party pipeline only</span>
      </section>

      <section className={styles.section} aria-labelledby="analytics-scope-title">
        <header className={styles.sectionHeader}><div><h2 id="analytics-scope-title">Analytics scope</h2><p>The default range is 30 days. App version and locale filters are not available.</p></div></header>
        <div className={styles.controls}>
          <SelectFilter id="analytics-channel" label="Channel" value={filters.channel} options={["ALL", "web"]} onChange={(value) => updateFilter("channel", value)} />
          <SelectFilter id="analytics-game" label="Game" value={filters.game} options={["ALL", "BLACKRED", "HERITAGE", "CAGED", "CAGED_CRASH", "DOORS"]} onChange={(value) => updateFilter("game", value)} />
          <SelectFilter id="analytics-state" label="State" value={filters.state} options={["ALL", "LAG"]} onChange={(value) => updateFilter("state", value)} />
          <label className={styles.formField} htmlFor="analytics-from">From<input id="analytics-from" type="date" value={filters.from} onChange={(event) => updateFilter("from", event.target.value)} /></label>
          <label className={styles.formField} htmlFor="analytics-to">To<input id="analytics-to" type="date" value={filters.to} onChange={(event) => updateFilter("to", event.target.value)} /></label>
        </div>
        <div className={styles.activeFilters} aria-label="Active analytics filters"><span>Channel: {filters.channel}</span><span>Game: {filters.game}</span><span>State: {filters.state}</span><span>{filters.from} to {filters.to}</span></div>
      </section>

      <section className={styles.workspace} aria-labelledby="funnel-workspace-title">
        <div>
          <header className={styles.sectionHeader}><div><h2 id="funnel-workspace-title">Conversion funnels</h2><p>Select a funnel to view its available measurements.</p></div></header>
          <ul className={styles.recordList}>{FUNNEL_ORDER.map((id, index) => {
            const funnel = funnels?.[id];
            return (
              <li key={id}>
                <button className={styles.recordButton} type="button" data-selected={id === filters.funnel} aria-pressed={id === filters.funnel} onClick={() => updateFilter("funnel", id)}>
                  <strong>{index + 1}. {FUNNEL_LABELS[id]}</strong>
                  <span>{funnel && !funnel.measurable ? "Not measurable" : "Measurable"}</span>
                </button>
              </li>
            );
          })}</ul>
        </div>

        <article className={styles.inspector} aria-labelledby="selected-funnel-title">
          <header className={styles.inspectorHeader}><div><h2 id="selected-funnel-title">{FUNNEL_LABELS[filters.funnel]}</h2></div><span className={styles.statusLabel}><Icon name="activity" />Daily totals</span></header>

          {state === "loading" && <p className={styles.muted}>Loading…</p>}
          {state === "error" && <p className={styles.muted}>Live funnel data could not be loaded. Sign in again if this persists.</p>}
          {state === "ready" && selectedFunnel && !selectedFunnel.measurable && (
            <p className={styles.muted}>Not measurable: {selectedFunnel.reason}</p>
          )}
          {state === "ready" && selectedFunnel && selectedFunnel.measurable && (
            <>
              <p className={styles.muted}>
                Conversion end-to-end: {selectedFunnel.conversionRate === null ? "no data in this window" : `${(selectedFunnel.conversionRate * 100).toFixed(1)}%`}
              </p>
              <ol className={styles.funnelBars}>
                {selectedFunnel.steps.map((step) => (
                  <li key={step.step}>
                    <span><strong>{step.step}</strong><b>{step.eventCount.toLocaleString("en-NG")}</b></span>
                  </li>
                ))}
              </ol>
            </>
          )}
        </article>
      </section>

      <section className={styles.section} aria-labelledby="rollup-table-title">
        <header className={styles.sectionHeader}><div><h2 id="rollup-table-title">Activity by day</h2><p>Aggregated activity for the selected filters and dates.</p></div></header>
        <OperationalDataTable
          caption="Daily activity"
          columns={rollupColumns}
          rows={rollups ?? []}
          getRowId={(row) => `${row.day}-${row.event_name}-${row.channel}-${row.game_code}-${row.state_code}`}
          dataState={state}
          stateMessage={state === "error" ? "Daily activity could not be loaded." : undefined}
        />
      </section>

      <details className={styles.advanced}>
      <summary>Data and privacy details</summary>
      <section className={styles.evidenceGrid} aria-label="Analytics event and privacy contract">
        <div className={styles.evidenceBlock}>
          <h2>Tracked event envelope</h2>
          <p className={styles.muted}>Financial figures come from confirmed transactions. Browsing activity is reported separately.</p>
          <ul className={styles.referenceList}>{ANALYTICS_EVENT_FIELDS.map((field) => <li key={field}><Icon name="check" /><div><strong>{field}</strong><span>Required event field</span></div></li>)}</ul>
        </div>
        <div className={styles.evidenceBlock}>
          <h2>Excluded from every payload</h2>
          <p className={styles.muted}>Pseudonymous identifiers and coarse state codes support analysis without reproducing identity records or precise location.</p>
          <ul className={styles.referenceList}>{ANALYTICS_EXCLUDED_FIELDS.map((field) => <li key={field}><Icon name="lock" /><div><strong>{field}</strong><span>Prohibited analytics payload field</span></div></li>)}</ul>
        </div>
      </section>
      </details>
    </div>
  );
}

function SelectFilter({ id, label, value, options, onChange }: { id: string; label: string; value: string; options: readonly string[]; onChange: (value: string) => void }) {
  return <label className={styles.selectField} htmlFor={id}>{label}<select id={id} value={value} onChange={(event) => onChange(event.target.value)}>{options.map((option) => <option key={option}>{option}</option>)}</select></label>;
}
