"use client";

import { useState, type FormEvent } from "react";
import { formatKobo } from "@/lib/money";
import type { MoneyTransaction, WalletGateway } from "@/mocks/wallet";
import styles from "./FundingFlow.module.css";

type FundingStep = "amount" | "confirmed";

const QUICK_AMOUNTS_KOBO = [50_000, 100_000, 200_000, 500_000, 1_000_000];

interface FundingFlowProps {
  gateway: WalletGateway;
  sourceLabel: string;
  onComplete: (transaction: MoneyTransaction) => void;
  onCancel: () => void;
}

export function FundingFlow({ gateway, sourceLabel, onComplete, onCancel }: FundingFlowProps) {
  const [step, setStep] = useState<FundingStep>("amount");
  const [amountKobo, setAmountKobo] = useState<number | null>(null);
  const [amountInput, setAmountInput] = useState("");
  const [amountError, setAmountError] = useState<string>();
  const [providerError, setProviderError] = useState<string>();
  const [pending, setPending] = useState(false);
  const [confirmedTransaction, setConfirmedTransaction] = useState<MoneyTransaction>();

  const MIN_KOBO = 10_000;   // ₦100
  const MAX_KOBO = 500_000_00; // ₦500,000

  const handleAmountInput = (val: string) => {
    setAmountInput(val);
    const parsed = parseFloat(val.replace(/,/g, ""));
    if (!isNaN(parsed) && parsed > 0) {
      setAmountKobo(Math.round(parsed * 100));
    } else {
      setAmountKobo(null);
    }
    setAmountError(undefined);
  };

  const handleQuickAmount = (kobo: number) => {
    setAmountKobo(kobo);
    setAmountInput((kobo / 100).toLocaleString("en-NG"));
    setAmountError(undefined);
  };

  const reviewAmount = async (event: FormEvent) => {
    event.preventDefault();
    if (amountKobo === null || amountKobo < MIN_KOBO) {
      setAmountError(`Minimum deposit is ${formatKobo(MIN_KOBO)}.`);
      return;
    }
    if (amountKobo > MAX_KOBO) {
      setAmountError(`Maximum deposit is ${formatKobo(MAX_KOBO)}.`);
      return;
    }
    setPending(true);
    setAmountError(undefined);
    setProviderError(undefined);
    try {
      const quote = await gateway.quoteFunding(amountKobo);
      const transaction = await gateway.collectDeposit(quote.quoteId);
      setConfirmedTransaction(transaction);
      onComplete(transaction);
      setStep("confirmed");
    } catch {
      setProviderError("We couldn't verify your OPay wallet and balance for this deposit. Please try again.");
    } finally {
      setPending(false);
    }
  };

  // ─── STEP 3: Confirmed ───────────────────────────────────────────────
  if (step === "confirmed" && confirmedTransaction) {
    return (
      <div className={styles.flowContainer}>
        <div className={styles.successPanel}>
          <div className={styles.successCircle}>
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M5 12l5 5L20 7" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
          </div>
          <div className={styles.successLabel}>Deposit confirmed</div>
          <h2 className={styles.successTitle}>
            <em>{formatKobo(confirmedTransaction.amountKobo)}</em>
          </h2>
          <p className={styles.successSub}>
            Added to your Play Balance. Reference: <strong>{confirmedTransaction.reference}</strong>
          </p>
        </div>
        <button type="button" className={styles.btnPrimary} onClick={onCancel}>
          Back to Wallet
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M1 8h13m0 0L8 2m6 6l-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/></svg>
        </button>
      </div>
    );
  }

  // ─── Enter Amount & Pay ────────────────────────────────────────────
  return (
    <div className={styles.flowContainer}>
      <h2 className={styles.stepHeading}>
        How much are you <em>depositing?</em>
      </h2>
      <p className={styles.stepSub}>
        Pick a quick amount or enter your own. Minimum ₦100, maximum ₦500,000 per deposit.
      </p>

      {/* Source card */}
      <div className={styles.sourceCard}>
        <div className={styles.sourceLogoBox}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/assets/opay-icon.png" alt="OPay" width={28} height={28} style={{ objectFit: 'contain', borderRadius: '6px' }} />
        </div>
        <div className={styles.sourceText}>
          <div className={styles.sourceLabel}>From your OPay wallet</div>
          <div className={styles.sourceValue}>{sourceLabel}</div>
        </div>
        <div className={styles.sourceBadge}>Linked</div>
      </div>

      <form onSubmit={reviewAmount} noValidate>
        {/* Big amount display */}
        <div className={styles.amountDisplay}>
          <label className={styles.amountLabel} htmlFor="deposit-amount-input">Amount to add</label>
          <div className={styles.amountInputWrap}>
            <span className={styles.amountCur}>NGN</span>
            <input
              type="tel"
              inputMode="decimal"
              id="deposit-amount-input"
              className={styles.amountInput}
              placeholder="0"
              value={amountInput}
              onChange={(e) => handleAmountInput(e.target.value)}
              autoComplete="off"
            />
          </div>
          {amountError
            ? <div className={`${styles.amountHint} ${styles.error}`}>{amountError}</div>
            : <div className={styles.amountHint}>Enter between ₦100 and ₦500,000</div>
          }
        </div>

        {/* Quick-amount chips */}
        <p className={styles.chipsLabel}>Quick amounts</p>
        <div className={styles.chips}>
          {QUICK_AMOUNTS_KOBO.map((kobo) => (
            <button
              key={kobo}
              type="button"
              className={`${styles.chip} ${amountKobo === kobo ? styles.chipActive : ""}`}
              onClick={() => handleQuickAmount(kobo)}
            >
              <span className={styles.chipCur}>₦</span>
              {(kobo / 100).toLocaleString("en-NG")}
            </button>
          ))}
        </div>

        {/* Summary */}
        <div className={styles.summary}>
          <div className={styles.summaryRow}>
            <span className={styles.summaryLabel}>Deposit</span>
            <span className={styles.summaryVal}>
              <span className={styles.cur}>NGN</span>
              {amountKobo ? (amountKobo / 100).toFixed(2) : "0.00"}
            </span>
          </div>
          <div className={styles.summaryRow}>
            <span className={styles.summaryLabel}>Transaction fee</span>
            <span className={`${styles.summaryVal} ${styles.free}`}>FREE</span>
          </div>
          <div className={styles.summaryRow}>
            <span className={styles.summaryLabel}>Total charge</span>
            <span className={`${styles.summaryVal} ${styles.total}`}>
              <span className={styles.cur}>NGN</span>
              {amountKobo ? (amountKobo / 100).toFixed(2) : "0.00"}
            </span>
          </div>
        </div>

        {providerError && (
          <div className={styles.errorAlert}>
            <span>⚠</span>
            <p>{providerError}</p>
          </div>
        )}

        <button type="submit" className={styles.btnPrimary} disabled={!amountKobo || amountKobo < MIN_KOBO || pending}>
          {pending ? <span className={styles.spinner}></span> : (
            <>
              <span>Pay via OPay</span>
              <svg className={styles.arrow} width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M1 8h13m0 0L8 2m6 6l-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </>
          )}
        </button>
        <button type="button" className={styles.btnSecondary} onClick={onCancel}>
          Cancel
        </button>
      </form>
    </div>
  );
}
