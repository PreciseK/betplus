"use client";

import { useState, type FormEvent } from "react";
import { formatKobo } from "@/lib/money";
import type {
  PayoutContext,
  PayoutGateway,
  PayoutRecord,
  WithdrawalSource,
} from "@/mocks/payout";
import styles from "./WithdrawalFlow.module.css";

type WithdrawalStep = "destination" | "amount" | "review" | "confirmed";
type DestType = "play" | "bank";

interface WithdrawalFlowProps {
  context: PayoutContext;
  gateway: PayoutGateway;
  onComplete: (payout: PayoutRecord) => void;
  onCancel: () => void;
}

export function WithdrawalFlow({ context, gateway, onComplete, onCancel }: WithdrawalFlowProps) {
  const [step, setStep] = useState<WithdrawalStep>("destination");
  const [dest, setDest] = useState<DestType>("bank");
  const [source, setSource] = useState<WithdrawalSource>("winnings");
  const [amountKobo, setAmountKobo] = useState<number | null>(null);
  const [amountInput, setAmountInput] = useState("");
  const [amountError, setAmountError] = useState<string>();
  const [providerError, setProviderError] = useState<string>();
  const [payout, setPayout] = useState<PayoutRecord>();
  const [pending, setPending] = useState(false);

  const availableKobo =
    source === "winnings"
      ? context.winningsBalanceKobo
      : context.turnover.releasedPlayBalanceKobo;

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

  const handleContinueFromDest = () => {
    setAmountInput("");
    setAmountKobo(null);
    setAmountError(undefined);
    setStep("amount");
  };

  const handleContinueFromAmount = async (event: FormEvent) => {
    event.preventDefault();
    if (!amountKobo || amountKobo <= 0) {
      setAmountError("Enter an amount to withdraw.");
      return;
    }
    if (amountKobo > availableKobo) {
      setAmountError(`Maximum available is ${formatKobo(availableKobo)}.`);
      return;
    }
    setStep("review");
  };

  const submitWithdrawal = async () => {
    setPending(true);
    setProviderError(undefined);
    try {
      const quote = await gateway.quoteWithdrawal(source, amountKobo!);
      const result = await gateway.requestWithdrawal(quote.quoteId);
      setPayout(result);
      onComplete(result);
      setStep("confirmed");
    } catch {
      setProviderError("The withdrawal was not submitted. Check your activity before trying again.");
    } finally {
      setPending(false);
    }
  };

  // ─── STEP 4: Confirmed ──────────────────────────────────────────────
  if (step === "confirmed" && payout) {
    return (
      <div className={styles.flowContainer}>
        <div className={styles.successPanel}>
          <div className={styles.successCircle}>
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M5 12l5 5L20 7" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
          </div>
          <div className={styles.successLabel}>{dest === "play" ? "Transfer complete" : "Withdrawal submitted"}</div>
          <h2 className={styles.successTitle}>
            <em>{formatKobo(payout.amountKobo)}</em>
          </h2>
          <p className={styles.successSub}>
            {dest === "play"
              ? "Moved to your Play Balance instantly. Reference: "
              : "Sent to your registered OPay wallet. You'll receive an SMS once it lands. Reference: "}
            <strong>{payout.reference}</strong>
          </p>
        </div>
        <button type="button" className={styles.btnPrimary} onClick={onCancel}>
          Back to Wallet
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
            <path d="M1 8h13m0 0L8 2m6 6l-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
          </svg>
        </button>
      </div>
    );
  }

  // ─── STEP 3: Review + Confirm ────────────────────────────────────────
  if (step === "review") {
    const totalSteps = dest === "bank" ? 3 : 2;
    const currentStep = dest === "bank" ? 3 : 2;
    return (
      <div className={styles.flowContainer}>
        <div className={styles.progressWrap}>
          <span className={styles.progressLabel}>Step <strong>{currentStep}</strong> of {totalSteps}</span>
          <div className={styles.progressTrack}>
            {Array.from({ length: totalSteps }).map((_, i) => (
              <div key={i} className={`${styles.progressStep} ${i < currentStep ? styles.done : ""}`}></div>
            ))}
          </div>
        </div>

        <div className={styles.confirmCard}>
          <div className={styles.confirmHeadline}>You&apos;re about to</div>
          <div className={styles.confirmAction}>{dest === "play" ? "move" : "withdraw"}</div>
          <div className={styles.confirmAmount}>
            <span className={styles.cur}>NGN</span>
            <span>{amountKobo ? (amountKobo / 100).toFixed(2) : "0.00"}</span>
          </div>
          <div className={styles.confirmDetail}>
            {dest === "play" ? "to your Play Balance" : `to your registered OPay (${context.destinationLabel})`}
          </div>
        </div>

        <div className={styles.summary}>
          <div className={styles.summaryRow}>
            <span className={styles.summaryLabel}>From</span>
            <span className={styles.summaryVal}>{source === "winnings" ? "Winnings Balance" : "Play Balance (Released)"}</span>
          </div>
          <div className={styles.summaryRow}>
            <span className={styles.summaryLabel}>To</span>
            <span className={styles.summaryVal}>{dest === "play" ? "Play Balance" : context.destinationLabel}</span>
          </div>
          <div className={styles.summaryRow}>
            <span className={styles.summaryLabel}>Amount</span>
            <span className={`${styles.summaryVal} ${styles.total}`}>
              <span className={styles.cur}>NGN</span>
              {amountKobo ? (amountKobo / 100).toFixed(2) : "0.00"}
            </span>
          </div>
          <div className={styles.summaryRow}>
            <span className={styles.summaryLabel}>Fee</span>
            <span className={`${styles.summaryVal} ${styles.free}`}>FREE</span>
          </div>
        </div>

        <p className={styles.confirmWarning}>This action cannot be undone once submitted.</p>

        {providerError && (
          <div className={styles.errorAlert}>
            <span>⚠</span>
            <p>{providerError}</p>
          </div>
        )}

        <button type="button" className={styles.btnPrimary} onClick={submitWithdrawal} disabled={pending}>
          {pending ? <span className={styles.spinner}></span> : (
            <>
              <span>Confirm {dest === "play" ? "transfer" : "withdrawal"}</span>
              <svg className={styles.arrow} width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M3 8l4 4 7-8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </>
          )}
        </button>
        <button type="button" className={styles.btnSecondary} onClick={() => setStep("amount")}>
          ← Change amount
        </button>
      </div>
    );
  }

  // ─── STEP 2: Enter Amount ────────────────────────────────────────────
  if (step === "amount") {
    const totalSteps = dest === "bank" ? 3 : 2;
    return (
      <div className={styles.flowContainer}>
        <div className={styles.progressWrap}>
          <span className={styles.progressLabel}>Step <strong>2</strong> of {totalSteps}</span>
          <div className={styles.progressTrack}>
            {Array.from({ length: totalSteps }).map((_, i) => (
              <div key={i} className={`${styles.progressStep} ${i === 0 ? styles.done : i === 1 ? styles.active : ""}`}></div>
            ))}
          </div>
        </div>

        <h2 className={styles.stepHeading}>
          How much to <em>withdraw?</em>
        </h2>
        <p className={styles.stepSub}>
          Enter the amount from your {source === "winnings" ? "Winnings" : "Play"} Balance.
        </p>

        {/* Available balance pill */}
        <div className={styles.balancePill}>
          <span className={styles.balancePillLabel}>Available</span>
          <span className={styles.balancePillVal}>{formatKobo(availableKobo)}</span>
        </div>

        <form onSubmit={handleContinueFromAmount} noValidate>
          <div className={styles.amountDisplay}>
            <div className={styles.amountLabel}>Amount to withdraw</div>
            <div className={styles.amountInputWrap}>
              <span className={styles.amountCur}>NGN</span>
              <input
                type="tel"
                inputMode="decimal"
                className={styles.amountInput}
                placeholder="0"
                value={amountInput}
                onChange={(e) => handleAmountInput(e.target.value)}
                autoComplete="off"
              />
            </div>
            {amountError
              ? <div className={`${styles.amountHint} ${styles.error}`}>{amountError}</div>
              : <div className={styles.amountHint}>
                  Max: {formatKobo(availableKobo)}
                </div>
            }
          </div>

          <button type="submit" className={styles.btnPrimary} disabled={!amountKobo || amountKobo <= 0}>
            <span>Continue</span>
            <svg className={styles.arrow} width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M1 8h13m0 0L8 2m6 6l-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
          </button>
          <button type="button" className={styles.btnSecondary} onClick={() => setStep("destination")}>
            ← Back
          </button>
        </form>
      </div>
    );
  }

  // ─── STEP 1: Choose Destination ──────────────────────────────────────
  return (
    <div className={styles.flowContainer}>
      <div className={styles.progressWrap}>
        <span className={styles.progressLabel}>Step <strong>1</strong> of 3</span>
        <div className={styles.progressTrack}>
          <div className={`${styles.progressStep} ${styles.active}`}></div>
          <div className={styles.progressStep}></div>
          <div className={styles.progressStep}></div>
        </div>
      </div>

      <h2 className={styles.stepHeading}>
        How would you like to <em>withdraw?</em>
      </h2>
      <p className={styles.stepSub}>
        Move winnings to your Play Balance to keep playing, or send them directly to your registered OPay wallet.
      </p>

      {/* Available payout balance */}
      <div className={styles.balancePill}>
        <span className={styles.balancePillLabel}>Available in Winnings</span>
        <span className={styles.balancePillVal}>{formatKobo(context.winningsBalanceKobo)}</span>
      </div>

      {/* Destination option cards */}
      <div className={styles.destCards}>
        <button
          type="button"
          className={`${styles.destCard} ${dest === "play" ? styles.destCardActive : ""}`}
          onClick={() => { setDest("play"); setSource("winnings"); }}
        >
          <div className={styles.destCardIcon}>
            <svg viewBox="0 0 24 24" fill="none">
              <rect x="5" y="3" width="14" height="18" rx="2" stroke="currentColor" strokeWidth="1.7"/>
              <path d="M12 3v18" stroke="currentColor" strokeWidth="1.7"/>
              <circle cx="8.5" cy="7" r="1" fill="currentColor"/>
              <circle cx="15.5" cy="17" r="1" fill="currentColor"/>
            </svg>
          </div>
          <div className={styles.destCardText}>
            <div className={styles.destCardTitle}>Move to Play Balance</div>
            <div className={styles.destCardSub}>Recycle winnings to keep playing. Instant — no fees.</div>
          </div>
          <div className={styles.destCardArrow}>
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M5 3l5 5-5 5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
          </div>
        </button>

        <button
          type="button"
          className={`${styles.destCard} ${dest === "bank" ? styles.destCardActive : ""}`}
          onClick={() => { setDest("bank"); setSource("winnings"); }}
        >
          <div className={`${styles.destCardIcon} ${styles.bankIcon}`}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/assets/opay-icon.png" alt="OPay" width={26} height={26} style={{ objectFit: 'contain' }} />
          </div>
          <div className={styles.destCardText}>
            <div className={styles.destCardTitle}>Withdraw to OPay</div>
            <div className={styles.destCardSub}>
              Send to: <strong>{context.destinationLabel}</strong>. Arrives in seconds.
            </div>
          </div>
          <div className={styles.destCardArrow}>
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M5 3l5 5-5 5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
          </div>
        </button>
      </div>

      <button type="button" className={styles.btnPrimary} onClick={handleContinueFromDest}>
        <span>Continue</span>
        <svg className={styles.arrow} width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M1 8h13m0 0L8 2m6 6l-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
        </svg>
      </button>
      <button type="button" className={styles.btnSecondary} onClick={onCancel}>
        Cancel
      </button>
    </div>
  );
}
