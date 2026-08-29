"use client";

import { useState, type FormEvent } from "react";
import { formatKobo } from "@/lib/money";
import type { FundingQuote, MoneyTransaction, WalletGateway } from "@/mocks/wallet";
import styles from "./FundingFlow.module.css";

type FundingStep = "amount" | "authorize" | "confirmed";

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
  const [quote, setQuote] = useState<FundingQuote>();
  const [collectionId, setCollectionId] = useState("");
  const [otp, setOtp] = useState("");
  const [otpError, setOtpError] = useState<string>();
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
      const response = await gateway.quoteFunding(amountKobo);
      setQuote(response);
      const col = await gateway.createCollection(response.quoteId);
      setCollectionId(col.collectionId);
      setStep("authorize");
    } catch {
      setProviderError("We couldn't start this deposit. No collection was created — please try again.");
    } finally {
      setPending(false);
    }
  };

  const submitOtp = async (event: FormEvent) => {
    event.preventDefault();
    if (otp.trim().length < 4) {
      setOtpError("Enter the OTP sent to your registered phone number.");
      return;
    }
    setPending(true);
    setOtpError(undefined);
    setProviderError(undefined);
    try {
      const transaction = await gateway.submitCollectionOtp(collectionId, otp);
      setConfirmedTransaction(transaction);
      onComplete(transaction);
      setStep("confirmed");
    } catch {
      setOtpError("Incorrect or expired OTP. Your balance has not changed. Try again.");
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

  // ─── STEP 2: Authorize (OTP) ─────────────────────────────────────────
  if (step === "authorize") {
    return (
      <div className={styles.flowContainer}>
        {/* Progress */}
        <div className={styles.progressWrap}>
          <span className={styles.progressLabel}>Step <strong>2</strong> of 2</span>
          <div className={styles.progressTrack}>
            <div className={`${styles.progressStep} ${styles.done}`}></div>
            <div className={`${styles.progressStep} ${styles.active}`}></div>
          </div>
        </div>

        <h2 className={styles.stepHeading}>
          Authorize your <em>deposit</em>
        </h2>
        <p className={styles.stepSub}>
          An OTP has been sent to your registered phone. Enter it below to confirm your{" "}
          <strong>{amountKobo ? formatKobo(amountKobo) : ""}</strong> deposit from OPay.
        </p>

        {/* Auth card */}
        <div className={styles.authPanel}>
          <div className={styles.authPhoneWrap}>
            <div className={styles.authPhoneRing}></div>
            <div className={`${styles.authPhoneRing} ${styles.r2}`}></div>
            <div className={styles.authPhoneIcon}>
              <svg viewBox="0 0 24 24" fill="none">
                <rect x="6" y="2.5" width="12" height="19" rx="2.5" stroke="currentColor" strokeWidth="1.7"/>
                <path d="M10 18h4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"/>
                <circle cx="12" cy="6" r="0.8" fill="currentColor"/>
              </svg>
            </div>
          </div>
          <div className={styles.authBadge}>
            <span className={styles.dot}></span>
            <span>OTP Sent</span>
          </div>
          <h3 className={styles.authTitle}>Check your phone &amp; <em>enter OTP</em></h3>
          <p className={styles.authSub}>
            A one-time password has been sent to your registered number. Enter it to approve this deposit.
          </p>
        </div>

        <form onSubmit={submitOtp} noValidate>
          <div className={styles.otpFieldWrap}>
            <label className={styles.fieldLabel} htmlFor="otp-input">Verification code</label>
            <input
              id="otp-input"
              type="tel"
              inputMode="numeric"
              className={`${styles.otpInput} ${otpError ? styles.inputError : ""}`}
              placeholder="• • • • • •"
              maxLength={6}
              value={otp}
              onChange={(e) => { setOtp(e.target.value.replace(/\D/g, "")); setOtpError(undefined); }}
              autoComplete="one-time-code"
            />
            {otpError && <p className={styles.fieldError}>{otpError}</p>}
          </div>

          {providerError && (
            <div className={styles.errorAlert}>
              <span>⚠</span>
              <p>{providerError}</p>
            </div>
          )}

          <button type="submit" className={styles.btnPrimary} disabled={pending || otp.length < 4}>
            {pending ? <span className={styles.spinner}></span> : (
              <>
                <span>Confirm Deposit</span>
                <svg className={styles.arrow} width="16" height="16" viewBox="0 0 16 16" fill="none">
                  <path d="M3 8l4 4 7-8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
                </svg>
              </>
            )}
          </button>
          <button type="button" className={styles.btnSecondary} onClick={() => { setStep("amount"); setOtp(""); }}>
            ← Change amount
          </button>
        </form>
      </div>
    );
  }

  // ─── STEP 1: Enter Amount ────────────────────────────────────────────
  return (
    <div className={styles.flowContainer}>
      {/* Progress */}
      <div className={styles.progressWrap}>
        <span className={styles.progressLabel}>Step <strong>1</strong> of 2</span>
        <div className={styles.progressTrack}>
          <div className={`${styles.progressStep} ${styles.active}`}></div>
          <div className={styles.progressStep}></div>
        </div>
      </div>

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
              <span>Continue</span>
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
