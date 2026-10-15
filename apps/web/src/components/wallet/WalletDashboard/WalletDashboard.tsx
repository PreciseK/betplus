"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { BalanceCard } from "@/components/ui/BalanceCard/BalanceCard";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { LoadingState } from "@/components/ui/states/LoadingState/LoadingState";
import { FundingFlow } from "@/components/wallet/FundingFlow/FundingFlow";
import { TurnoverProgress } from "@/components/wallet/TurnoverProgress/TurnoverProgress";
import { WithdrawalFlow } from "@/components/wallet/WithdrawalFlow/WithdrawalFlow";
import { mockPayoutGateway, type PayoutContext, type PayoutGateway, type PayoutRecord } from "@/mocks/payout";
import { mockWalletGateway, type MoneyTransaction, type WalletGateway, type WalletSnapshot } from "@/mocks/wallet";
import styles from "./WalletDashboard.module.css";

type WalletMode = "idle" | "funding" | "withdrawal";

export function WalletDashboard({ gateway = mockWalletGateway, payoutGateway = mockPayoutGateway, initialMode = "idle" }: {
  gateway?: WalletGateway;
  payoutGateway?: PayoutGateway;
  initialMode?: WalletMode;
}) {
  const [snapshot, setSnapshot] = useState<WalletSnapshot | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [payoutContext, setPayoutContext] = useState<PayoutContext | null>(null);
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
    setSnapshot((current) => current ? {
      ...current,
      playBalanceKobo: current.playBalanceKobo + transaction.amountKobo,
      transactions: [transaction, ...current.transactions],
    } : current);
  };

  const recordPayout = (payout: PayoutRecord) => {
    setPayoutContext((current) => current ? {
      ...current,
      payouts: [payout, ...current.payouts.filter((item) => item.reference !== payout.reference)],
    } : current);
  };

  if (!snapshot) {
    return loadError
      ? <InlineMessage tone="error" title="Wallet unavailable">Your balance could not be loaded. Refresh the page to try again.</InlineMessage>
      : <LoadingState label="Loading wallet" description="Checking your balances." />;
  }

  return (
    <div className={styles.dashboard}>
      <div className={styles.balances}>
        <BalanceCard kind="play" amountKobo={snapshot.playBalanceKobo} explanation="Deposits available for play." />
        <BalanceCard kind="winnings" amountKobo={snapshot.winningsBalanceKobo} explanation="Winnings available for withdrawal, subject to verification." />
        {!!snapshot.bonusBalanceKobo && snapshot.bonusBalanceKobo > 0 && (
          <BalanceCard kind="bonus" amountKobo={snapshot.bonusBalanceKobo} explanation="Promotional credit for eligible games. After 1× playthrough, net profit moves to winnings." />
        )}
      </div>
      {payoutLoadError && (
        <InlineMessage tone="warning" title="Withdrawal status unavailable">
          You can deposit, but withdrawal eligibility could not be verified. Refresh the page to try again.
        </InlineMessage>
      )}
      {mode === "idle" && (
        <div className={styles.actions}>
          <button type="button" className={styles.actionBtn} onClick={() => setMode("funding")}>Deposit</button>
          <button type="button" className={styles.actionBtnSecondary} onClick={() => setMode("withdrawal")} disabled={!payoutContext}>Withdraw</button>
        </div>
      )}
      {mode === "funding" && (
        <FundingFlow gateway={gateway} sourceLabel={snapshot.registeredSourceLabel} onComplete={recordFunding} onCancel={() => setMode("idle")} />
      )}
      {mode === "withdrawal" && payoutContext && (
        <WithdrawalFlow context={payoutContext} gateway={payoutGateway} onComplete={recordPayout} onCancel={() => setMode("idle")} />
      )}
      {payoutContext && <TurnoverProgress turnover={payoutContext.turnover} />}
      <Link href="/activity" className={styles.historyLink}>View transactions <span aria-hidden="true">↗</span></Link>
    </div>
  );
}
