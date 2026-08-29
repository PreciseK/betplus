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

  useEffect(() => {
    let active = true;
    Promise.all([gateway.loadWallet(), payoutGateway.loadPayoutContext()])
      .then(([walletSnap, payoutContext]) => {
        if (active) {
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
    const now = Date.now();
    return transactions.filter((t) => t.amountKobo > 0 && withinTimeFilter(t.occurredAt, timeFilter, now));
  }, [transactions, timeFilter]);

  const withdrawalsList = useMemo(() => {
    const now = Date.now();
    return payouts.filter((p) => withinTimeFilter(p.createdAt, timeFilter, now));
  }, [payouts, timeFilter]);

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
        <div className={styles.eyebrow}>
          <span className={styles.eyebrowDash}>──</span>
          <span className={styles.eyebrowText}>YOUR ACTIVITY</span>
        </div>
        <h1 className={styles.pageTitle}>Transactions</h1>
      </header>

      {/* 2. TOP METRICS TILES */}
      <div className={styles.metricsGrid}>
        <div className={styles.metricCard}>
          <span className={styles.metricLabel}>TOTAL</span>
          <div className={styles.metricValueRow}>
            <span className={styles.currencyCode}>NGN</span>
            <strong className={styles.metricAmount}>
              {loading ? "0.00" : (activeTab === "deposits" ? (totalDepositsKobo / 100).toFixed(2) : (totalWithdrawalsKobo / 100).toFixed(2))}
            </strong>
          </div>
        </div>

        <div className={styles.metricCard}>
          <span className={styles.metricLabel}>SUCCESSFUL</span>
          <div className={styles.metricValueRow}>
            <span className={`${styles.currencyCode} ${styles.greenText}`}>NGN</span>
            <strong className={`${styles.metricAmount} ${styles.greenText}`}>
              {loading ? "0.00" : (activeTab === "deposits" ? (totalDepositsKobo / 100).toFixed(2) : (totalWithdrawalsKobo / 100).toFixed(2))}
            </strong>
          </div>
        </div>
      </div>

      {/* 3. TABS: DEPOSITS & WITHDRAWALS TOGGLE BAR */}
      <div className={styles.tabToggleBar}>
        <button
          type="button"
          className={`${styles.tabBtn} ${activeTab === "deposits" ? styles.tabBtnActive : ""}`}
          onClick={() => setActiveTab("deposits")}
        >
          <span className={styles.tabIcon}>📥</span>
          <span>Deposits</span>
          <span className={styles.countBadge}>{depositsList.length}</span>
        </button>

        <button
          type="button"
          className={`${styles.tabBtn} ${activeTab === "withdrawals" ? styles.tabBtnActive : ""}`}
          onClick={() => setActiveTab("withdrawals")}
        >
          <span className={styles.tabIcon}>📤</span>
          <span>Withdrawals</span>
          <span className={styles.countBadge}>{withdrawalsList.length}</span>
        </button>

        <button
          type="button"
          className={`${styles.tabBtn} ${activeTab === "stakes" ? styles.tabBtnActive : ""}`}
          onClick={() => setActiveTab("stakes")}
        >
          <span className={styles.tabIcon}>🎲</span>
          <span>Stakes</span>
        </button>
      </div>

      {/* 4. FILTER BAR: TIME RANGES */}
      <div className={styles.filterRow}>
        <span className={styles.filterLabel}>SHOW</span>
        <div className={styles.filterPills}>
          <button
            type="button"
            className={`${styles.pillBtn} ${timeFilter === "today" ? styles.pillBtnActive : ""}`}
            onClick={() => setTimeFilter("today")}
          >
            Today
          </button>
          <button
            type="button"
            className={`${styles.pillBtn} ${timeFilter === "week" ? styles.pillBtnActive : ""}`}
            onClick={() => setTimeFilter("week")}
          >
            This Week
          </button>
          <button
            type="button"
            className={`${styles.pillBtn} ${timeFilter === "month" ? styles.pillBtnActive : ""}`}
            onClick={() => setTimeFilter("month")}
          >
            This Month
          </button>
          <button
            type="button"
            className={`${styles.pillBtn} ${timeFilter === "all" ? styles.pillBtnActive : ""}`}
            onClick={() => setTimeFilter("all")}
          >
            All Time
          </button>
        </div>
      </div>

      {/* 5. FEED CONTENT AREA */}
      {activeTab === "stakes" ? (
        <StakeHistory />
      ) : (
        <div className={styles.feedCard}>
          {loading ? (
            <div className={styles.emptyState}>
              <div className={styles.emptyIconBox}>⏳</div>
              <h3 className={styles.emptyTitle}>Loading activity</h3>
              <p className={styles.emptySub}>Retrieving your latest durable transaction history...</p>
            </div>
          ) : failed ? (
            <div className={styles.emptyState}>
              <div className={styles.emptyIconBox}>⚠️</div>
              <h3 className={styles.emptyTitle}>Couldn&apos;t load</h3>
              <p className={styles.emptySub}>Could not reach the server.</p>
            </div>
          ) : activeTab === "deposits" && depositsList.length > 0 ? (
            <div className={styles.transactionList}>
              {depositsList.map((tx) => (
                <div key={tx.reference} className={styles.transactionRow}>
                  <div className={styles.txIconBox}>
                    <span>📥</span>
                  </div>
                  <div className={styles.txMainInfo}>
                    <strong className={styles.txTitle}>{tx.type} ({tx.provider})</strong>
                    <span className={styles.txMeta}>
                      Ref: {tx.reference} • {new Date(tx.occurredAt).toLocaleDateString("en-NG", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" })}
                    </span>
                  </div>
                  <div className={styles.txAmountLockup}>
                    <strong className={`${styles.txAmount} ${styles.greenText}`}>
                      +₦{(tx.amountKobo / 100).toLocaleString("en-NG", { minimumFractionDigits: 2 })}
                    </strong>
                    <span className={styles.txStatusBadge}>✓ {tx.status}</span>
                  </div>
                </div>
              ))}
            </div>
          ) : activeTab === "withdrawals" && withdrawalsList.length > 0 ? (
            <div className={styles.transactionList}>
              {withdrawalsList.map((po) => (
                <div key={po.reference} className={styles.transactionRow}>
                  <div className={`${styles.txIconBox} ${styles.withdrawalIconBox}`}>
                    <span>📤</span>
                  </div>
                  <div className={styles.txMainInfo}>
                    <strong className={styles.txTitle}>Payout ({po.destinationLabel})</strong>
                    <span className={styles.txMeta}>
                      Ref: {po.reference} • {new Date(po.createdAt).toLocaleDateString("en-NG", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" })}
                    </span>
                  </div>
                  <div className={styles.txAmountLockup}>
                    <strong className={styles.txAmount}>
                      -₦{(po.amountKobo / 100).toLocaleString("en-NG", { minimumFractionDigits: 2 })}
                    </strong>
                    <span className={styles.txStatusBadge}>✓ {po.displayStatus}</span>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className={styles.emptyState}>
              <div className={styles.emptyIconBox}>ⓘ</div>
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
