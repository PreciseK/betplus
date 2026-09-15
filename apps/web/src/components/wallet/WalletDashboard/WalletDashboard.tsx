"use client";

import { useEffect, useState } from "react";
import { BalanceCard } from "@/components/ui/BalanceCard/BalanceCard";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { FundingFlow } from "@/components/wallet/FundingFlow/FundingFlow";
import { TurnoverProgress } from "@/components/wallet/TurnoverProgress/TurnoverProgress";
import { WithdrawalFlow } from "@/components/wallet/WithdrawalFlow/WithdrawalFlow";
import { mockPayoutGateway, type PayoutContext, type PayoutGateway, type PayoutRecord } from "@/mocks/payout";
import { mockWalletGateway, type MoneyTransaction, type WalletGateway, type WalletSnapshot } from "@/mocks/wallet";
import styles from "./WalletDashboard.module.css";

type WalletMode = "idle" | "funding" | "withdrawal";

const DEFAULT_SNAPSHOT: WalletSnapshot = {
  playBalanceKobo: 1_250_000,
  winningsBalanceKobo: 4_500_000,
  currency: "NGN",
  registeredSourceLabel: "OPay •••• 4921",
  transactions: [
    {
      reference: "BP-DEP-240814-01",
      type: "OPay deposit",
      provider: "OPay",
      occurredAt: new Date().toISOString(),
      amountKobo: 500_000,
      feeKobo: 0,
      status: "paid",
      statusHistory: [],
    },
    {
      reference: "BP-DEP-240814-02",
      type: "OPay deposit",
      provider: "OPay",
      occurredAt: new Date(Date.now() - 3600000).toISOString(),
      amountKobo: 750_000,
      feeKobo: 0,
      status: "paid",
      statusHistory: [],
    },
  ],
};

const DEFAULT_PAYOUT_CONTEXT: PayoutContext = {
  playBalanceKobo: 1_250_000,
  winningsBalanceKobo: 4_500_000,
  destinationLabel: "OPay (803***4921)",
  destinationName: "Kennedy Jones",
  manualReviewThresholdKobo: 10_000_000,
  turnover: {
    depositAmountKobo: 1_250_000,
    stakedKobo: 1_250_000,
    requiredStakeKobo: 1_250_000,
    releasedPlayBalanceKobo: 1_250_000,
  },
  payouts: [
    {
      reference: "BP-PO-240814-01",
      kind: "withdrawal",
      createdAt: new Date().toISOString(),
      amountKobo: 200_000,
      sourceLabel: "Winnings Balance",
      destinationLabel: "OPay (803***4921)",
      providerStatus: "SUCCESS",
      displayStatus: "paid",
      statusExpectation: "Paid instantly to your OPay wallet.",
      fundsRemainInWinnings: false,
      statusHistory: [],
    },
  ],
};

export function WalletDashboard({
  gateway = mockWalletGateway,
  payoutGateway = mockPayoutGateway,
  initialMode = "idle",
}: {
  gateway?: WalletGateway;
  payoutGateway?: PayoutGateway;
  initialMode?: WalletMode;
}) {
  const [snapshot, setSnapshot] = useState<WalletSnapshot>(DEFAULT_SNAPSHOT);
  const [loadError, setLoadError] = useState(false);
  const [payoutContext, setPayoutContext] = useState<PayoutContext>(DEFAULT_PAYOUT_CONTEXT);
  const [payoutLoadError, setPayoutLoadError] = useState(false);
  const [mode, setMode] = useState<WalletMode>(initialMode);

  useEffect(() => {
    let active = true;
    gateway.loadWallet()
      .then((response) => { if (active) setSnapshot(response); })
      .catch(() => { if (active) setLoadError(true); });
    return () => { active = false; };
  }, [gateway]);

  useEffect(() => {
    let active = true;
    payoutGateway.loadPayoutContext()
      .then((response) => { if (active) setPayoutContext(response); })
      .catch(() => { if (active) setPayoutLoadError(true); });
    return () => { active = false; };
  }, [payoutGateway]);

  const recordFunding = (transaction: MoneyTransaction) => {
    setSnapshot((current) => ({
      ...current,
      playBalanceKobo: current.playBalanceKobo + transaction.amountKobo,
      transactions: [transaction, ...current.transactions],
    }));
  };

  const recordPayout = (payout: PayoutRecord) => {
    setPayoutContext((current) => ({
      ...current,
      payouts: [payout, ...current.payouts.filter((item) => item.reference !== payout.reference)],
    }));
  };

  return (
    <div className={styles.dashboard}>
      {/* 1. Balances */}
      <div className={styles.balances}>
        <BalanceCard
          kind="play"
          amountKobo={snapshot.playBalanceKobo}
          explanation="Deposited money available to stake after account and play checks."
        />
        <BalanceCard
          kind="winnings"
          amountKobo={snapshot.winningsBalanceKobo}
          explanation="Settled winnings available under withdrawal and verification rules."
        />
        {snapshot.bonusBalanceKobo !== undefined && snapshot.bonusBalanceKobo > 0 && (
          <BalanceCard
            kind="bonus"
            amountKobo={snapshot.bonusBalanceKobo}
            explanation="Non-withdrawable promo credit. Staked first on eligible games (1x playthrough converts net profit to winnings)."
          />
        )}
      </div>

      {/* 2. Turnover Progress */}
      {payoutContext && <TurnoverProgress turnover={payoutContext.turnover} />}

      {loadError && (
        <InlineMessage tone="warning" title="Live sync delay">
          Showing cached balances while live sync reconnects.
        </InlineMessage>
      )}

      {payoutLoadError && (
        <InlineMessage tone="warning" title="Withdrawal status unavailable">
          Add money remains available, but withdrawal eligibility could not be verified.
        </InlineMessage>
      )}

      {/* 3. Quick Action Buttons */}
      {mode === "idle" && (
        <div className={styles.actions}>
          {/* Deposit button */}
          <button
            type="button"
            className={styles.actionBtn}
            onClick={() => setMode("funding")}
          >
            <span className={styles.actionBtnIcon}>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src="/assets/opay-icon.png" alt="" width={22} height={22} style={{ objectFit: 'contain' }} />
            </span>
            <span className={styles.actionBtnText}>
              <span className={styles.actionBtnLabel}>Deposit via</span>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src="/assets/opay-logo.png" alt="OPay" height={16} style={{ objectFit: 'contain', filter: 'brightness(0) invert(1)', width: 'auto' }} />
            </span>
            <svg className={styles.actionBtnArrow} width="14" height="14" viewBox="0 0 16 16" fill="none">
              <path d="M1 8h13m0 0L8 2m6 6l-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
          </button>

          {/* Withdraw button */}
          <button
            type="button"
            className={`${styles.actionBtn} ${styles.actionBtnWithdraw}`}
            onClick={() => setMode("withdrawal")}
            disabled={!payoutContext}
          >
            <span className={styles.actionBtnIcon} style={{ background: 'rgba(16,185,129,0.15)', color: '#10b981' }}>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <path d="M12 5v14M5 12l7 7 7-7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </span>
            <span className={styles.actionBtnText}>
              <span className={styles.actionBtnLabel}>Withdraw to</span>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src="/assets/opay-logo.png" alt="OPay" height={16} style={{ objectFit: 'contain', filter: 'brightness(0) invert(1)', width: 'auto' }} />
            </span>
            <svg className={styles.actionBtnArrow} width="14" height="14" viewBox="0 0 16 16" fill="none">
              <path d="M1 8h13m0 0L8 2m6 6l-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
          </button>
        </div>
      )}

      {mode === "funding" && (
        <FundingFlow
          gateway={gateway}
          sourceLabel={snapshot.registeredSourceLabel}
          onComplete={recordFunding}
          onCancel={() => setMode("idle")}
        />
      )}

      {mode === "withdrawal" && payoutContext && (
        <WithdrawalFlow
          context={payoutContext}
          gateway={payoutGateway}
          onComplete={recordPayout}
          onCancel={() => setMode("idle")}
        />
      )}

    </div>
  );
}
