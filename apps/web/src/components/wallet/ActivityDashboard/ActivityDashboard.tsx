"use client";

import { useEffect, useState, useMemo } from "react";
import { StakeHistory } from "@/components/wallet/StakeHistory/StakeHistory";
import { mockPayoutGateway, type PayoutGateway, type PayoutRecord } from "@/mocks/payout";
import { mockWalletGateway, type MoneyTransaction, type WalletGateway } from "@/mocks/wallet";
import styles from "./ActivityDashboard.module.css";

type TabType = "deposits" | "withdrawals" | "stakes";
type TimeFilter = "today" | "week" | "month" | "all";

const TIME_FILTER_WINDOW_MS: Record<Exclude<TimeFilter, "all">, number> = {
  today: 24 * 60 * 60 * 1000,
  week: 7 * 24 * 60 * 60 * 1000,
  month: 30 * 24 * 60 * 60 * 1000,
};

function withinTimeFilter(occurredAtIso: string, filter: TimeFilter, now: number) {
  if (filter === "all") return true;
  return now - new Date(occurredAtIso).getTime() <= TIME_FILTER_WINDOW_MS[filter];
}

export function ActivityDashboard({
  gateway = mockWalletGateway,
  payoutGateway = mockPayoutGateway,
}: {
  gateway?: WalletGateway;
  payoutGateway?: PayoutGateway;
}) {
  const [transactions, setTransactions] = useState<MoneyTransaction[]>([]);
  const [payouts, setPayouts] = useState<PayoutRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const [activeTab, setActiveTab] = useState<TabType>("deposits");
  const [timeFilter, setTimeFilter] = useState<TimeFilter>("today");
  const [filterNow, setFilterNow] = useState(() => Date.now());

  function selectPeriod(period: TimeFilter) {
    setFilterNow(Date.now());
    setTimeFilter(period);
  }

  useEffect(() => {
    let active = true;
    Promise.all([gateway.loadWallet(), payoutGateway.loadPayoutContext()])
      .then(([walletSnap, payoutContext]) => {
        if (active) {
          setFilterNow(Date.now());
          setTransactions(walletSnap.transactions);
          setPayouts(payoutContext.payouts);
          setLoading(false);
        }
      })
      .catch(() => {
        if (active) {
          setFailed(true);
          setLoading(false);
        }
      });
    return () => {
      active = false;
    };
  }, [gateway, payoutGateway]);

  // Derived counts and totals
  const depositsList = useMemo(() => {
    const now = filterNow;
    return transactions.filter((t) => t.amountKobo > 0 && withinTimeFilter(t.occurredAt, timeFilter, now));
  }, [transactions, timeFilter, filterNow]);

  const withdrawalsList = useMemo(() => {
    const now = filterNow;
    return payouts.filter((p) => withinTimeFilter(p.createdAt, timeFilter, now));
  }, [payouts, timeFilter, filterNow]);

  const totalDepositsKobo = useMemo(() => {
    return depositsList.reduce((acc, t) => acc + (t.amountKobo || 0), 0);
  }, [depositsList]);

  const totalWithdrawalsKobo = useMemo(() => {
    return withdrawalsList.reduce((acc, p) => acc + (p.amountKobo || 0), 0);
  }, [withdrawalsList]);

  return (
    <div className={styles.activityContainer}>
      {/* 1. EYEBROW & MAIN TITLE */}
      <header className={styles.activityHeader}>
        <h1 className={styles.pageTitle}>Transactions</h1>
      </header>

      {/* 2. TOP METRICS TILES */}
      <div className={styles.metricsGrid}>
        <button
          type="button"
          className={`${styles.metricCard} ${activeTab === "deposits" ? styles.metricCardActive : ""}`}
          onClick={() => setActiveTab("deposits")}
          aria-label="Filter deposits"
          aria-pressed={activeTab === "deposits"}
        >
          <div className={styles.metricHeaderRow}>
            <span className={styles.metricLabel}>Deposits</span>
          </div>
          <div className={styles.metricValueRow}>
            <span className={styles.currencyCode}>NGN</span>
            <strong className={styles.metricAmount}>
              {loading || failed ? "—" : (totalDepositsKobo / 100).toLocaleString("en-NG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </strong>
          </div>
          <span className={styles.metricSub}>{depositsList.length} recorded</span>
        </button>

        <button
          type="button"
          className={`${styles.metricCard} ${activeTab === "withdrawals" ? styles.metricCardActive : ""}`}
          onClick={() => setActiveTab("withdrawals")}
          aria-label="Filter withdrawals"
          aria-pressed={activeTab === "withdrawals"}
        >
          <div className={styles.metricHeaderRow}>
            <span className={styles.metricLabel}>Withdrawals</span>
          </div>
          <div className={styles.metricValueRow}>
            <span className={styles.currencyCode}>NGN</span>
            <strong className={styles.metricAmount}>
              {loading || failed ? "—" : (totalWithdrawalsKobo / 100).toLocaleString("en-NG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </strong>
          </div>
          <span className={styles.metricSub}>{withdrawalsList.length} recorded</span>
        </button>
      </div>

      {/* 3. TABS: DEPOSITS & WITHDRAWALS TOGGLE BAR */}
      <div className={styles.tabToggleBar} role="group" aria-label="Transaction type">
        <button
          type="button"
          className={`${styles.tabBtn} ${activeTab === "deposits" ? styles.tabBtnActive : ""}`}
          onClick={() => setActiveTab("deposits")}
          aria-pressed={activeTab === "deposits"}
        >
          <span>Deposits</span>
          <span className={styles.countBadge}>{depositsList.length}</span>
        </button>

        <button
          type="button"
          className={`${styles.tabBtn} ${activeTab === "withdrawals" ? styles.tabBtnActive : ""}`}
          onClick={() => setActiveTab("withdrawals")}
          aria-pressed={activeTab === "withdrawals"}
        >
          <span>Withdrawals</span>
          <span className={styles.countBadge}>{withdrawalsList.length}</span>
        </button>

        <button
          type="button"
          className={`${styles.tabBtn} ${activeTab === "stakes" ? styles.tabBtnActive : ""}`}
          onClick={() => setActiveTab("stakes")}
          aria-pressed={activeTab === "stakes"}
        >
          <span>Stakes</span>
        </button>
      </div>

      {/* 4. FILTER BAR: TIME RANGES */}
      <div className={styles.filterRow}>
        <span className={styles.filterLabel}>Period</span>
        <div className={styles.filterPills} role="group" aria-label="Date range">
          <button
            type="button"
            className={`${styles.pillBtn} ${timeFilter === "today" ? styles.pillBtnActive : ""}`}
            onClick={() => selectPeriod("today")}
            aria-pressed={timeFilter === "today"}
          >
            Last 24 hours
          </button>
          <button
            type="button"
            className={`${styles.pillBtn} ${timeFilter === "week" ? styles.pillBtnActive : ""}`}
            onClick={() => selectPeriod("week")}
            aria-pressed={timeFilter === "week"}
          >
            Last 7 days
          </button>
          <button
            type="button"
            className={`${styles.pillBtn} ${timeFilter === "month" ? styles.pillBtnActive : ""}`}
            onClick={() => selectPeriod("month")}
            aria-pressed={timeFilter === "month"}
          >
            Last 30 days
          </button>
          <button
            type="button"
            className={`${styles.pillBtn} ${timeFilter === "all" ? styles.pillBtnActive : ""}`}
            onClick={() => selectPeriod("all")}
            aria-pressed={timeFilter === "all"}
          >
            All time
          </button>
        </div>
      </div>

      {/* 5. FEED CONTENT AREA */}
      {activeTab === "stakes" ? (
        <StakeHistory hideHeader={true} />
      ) : (
        <div className={styles.feedCard} aria-live="polite">
          {loading ? (
            <div className={styles.emptyState}>
              <h3 className={styles.emptyTitle}>Loading activity</h3>
              <p className={styles.emptySub}>Fetching your transactions.</p>
            </div>
          ) : failed ? (
            <div className={styles.emptyState}>
              <h3 className={styles.emptyTitle}>Couldn&apos;t load</h3>
              <p className={styles.emptySub}>Refresh the page to try again.</p>
            </div>
          ) : activeTab === "deposits" && depositsList.length > 0 ? (
            <div className={styles.transactionList}>
              {depositsList.map((tx) => (
                <div key={tx.reference} className={styles.transactionRow}>
                  <div className={styles.txMainInfo}>
                    <strong className={styles.txTitle}>{tx.type}</strong>
                    <span className={styles.txMeta}>
                      Ref: {tx.reference} • {new Date(tx.occurredAt).toLocaleDateString("en-NG", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "Africa/Lagos" })}
                    </span>
                  </div>
                  <div className={styles.txAmountLockup}>
                    <strong className={styles.txAmount}>
                      +₦{(tx.amountKobo / 100).toLocaleString("en-NG", { minimumFractionDigits: 2 })}
                    </strong>
                    <span className={styles.txStatusBadge} data-status={tx.status}>{tx.status}</span>
                  </div>
                </div>
              ))}
            </div>
          ) : activeTab === "withdrawals" && withdrawalsList.length > 0 ? (
            <div className={styles.transactionList}>
              {withdrawalsList.map((po) => (
                <div key={po.reference} className={styles.transactionRow}>
                  <div className={styles.txMainInfo}>
                    <strong className={styles.txTitle}>Payout ({po.destinationLabel})</strong>
                    <span className={styles.txMeta}>
                      Ref: {po.reference} • {new Date(po.createdAt).toLocaleDateString("en-NG", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "Africa/Lagos" })}
                    </span>
                  </div>
                  <div className={styles.txAmountLockup}>
                    <strong className={styles.txAmount}>
                      -₦{(po.amountKobo / 100).toLocaleString("en-NG", { minimumFractionDigits: 2 })}
                    </strong>
                    <span className={styles.txStatusBadge} data-status={po.displayStatus}>{po.displayStatus.replaceAll("-", " ")}</span>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className={styles.emptyState}>
              <h3 className={styles.emptyTitle}>No records found</h3>
              <p className={styles.emptySub}>
                {activeTab === "deposits"
                  ? "No deposits recorded for the selected time filter."
                  : "No withdrawals recorded for the selected time filter."}
              </p>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
