"use client";

import { useState, type FormEvent } from "react";
import { formatKobo } from "@/lib/money";
import { walletGateway } from "@betplus/api-client";
import styles from "./OpayDirectCheckoutModal.module.css";

export interface OpayDirectCheckoutModalProps {
  isOpen: boolean;
  onClose: () => void;
  stakeKobo: number;
  potentialWinKobo: number;
  gameName: string;
  onPaymentSuccess: () => Promise<void> | void;
}

type CheckoutStep = "INITIAL" | "PIN" | "OTP";

export function OpayDirectCheckoutModal({
  isOpen,
  onClose,
  stakeKobo,
  potentialWinKobo,
  gameName,
  onPaymentSuccess,
}: OpayDirectCheckoutModalProps) {
  const [step, setStep] = useState<CheckoutStep>("INITIAL");
  const [orderNo, setOrderNo] = useState<string>("");
  const [pin, setPin] = useState("");
  const [otp, setOtp] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string>();

  if (!isOpen) return null;

  const resetState = () => {
    setStep("INITIAL");
    setOrderNo("");
    setPin("");
    setOtp("");
    setError(undefined);
    setIsLoading(false);
  };

  const handleClose = () => {
    resetState();
    onClose();
  };

  const handleInitialPay = async (e: FormEvent) => {
    e.preventDefault();
    setError(undefined);
    setIsLoading(true);

    try {
      const initResult = await walletGateway.initDeposit(stakeKobo);

      if (initResult.status === "paid") {
        await onPaymentSuccess();
        handleClose();
        return;
      }

      if (initResult.actionType === "INPUT_PIN" && initResult.orderNo) {
        setOrderNo(initResult.orderNo);
        setStep("PIN");
        return;
      }

      if (initResult.actionType === "INPUT_OTP" && initResult.orderNo) {
        setOrderNo(initResult.orderNo);
        setStep("OTP");
        return;
      }

      if (initResult.actionType === "REDIRECT" && initResult.cashierUrl) {
        window.location.href = initResult.cashierUrl;
        return;
      }

      throw new Error(initResult.status || "FAILED");
    } catch (err) {
      setError(
        err instanceof Error && err.message === "PENDING_REVIEW"
          ? "This stake needs manual review before it can be funded — please try a smaller amount or use your Play Balance instead."
          : "Could not initiate OPay payment. Please check your network and try again."
      );
    } finally {
      setIsLoading(false);
    }
  };

  const handlePinSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (pin.length !== 4) {
      setError("Please enter your 4-digit OPay PIN.");
      return;
    }
    setError(undefined);
    setIsLoading(true);

    try {
      const result = await walletGateway.submitDepositPin(orderNo, pin);

      if (result.status === "paid") {
        await onPaymentSuccess();
        handleClose();
        return;
      }

      if (result.status === "pending" || result.status === "INPUT_OTP") {
        setStep("OTP");
        return;
      }

      throw new Error(result.status || "PIN_FAILED");
    } catch (err) {
      setError("Incorrect PIN or payment declined. Please try again.");
    } finally {
      setIsLoading(false);
    }
  };

  const handleOtpSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!otp.trim()) {
      setError("Please enter the verification OTP sent to your phone.");
      return;
    }
    setError(undefined);
    setIsLoading(true);

    try {
      const result = await walletGateway.submitDepositOtp(orderNo, otp);

      if (result.status === "paid") {
        await onPaymentSuccess();
        handleClose();
        return;
      }

      throw new Error(result.status || "OTP_FAILED");
    } catch (err) {
      setError("Invalid OTP or verification expired. Please try again.");
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className={styles.overlay} role="dialog" aria-modal="true" aria-labelledby="opay-checkout-title">
      <div className={styles.modal}>
        <div className={styles.header}>
          <div className={styles.brandLockup}>
            <span className={styles.opayBadge}>⚡ OPay Direct</span>
            <h3 id="opay-checkout-title" className={styles.title}>Fast Checkout</h3>
          </div>
          <button type="button" className={styles.closeBtn} onClick={handleClose} aria-label="Close modal">
            ×
          </button>
        </div>

        <div className={styles.body}>
          <div className={styles.summaryCard}>
            <div className={styles.summaryRow}>
              <span>Game</span>
              <strong>{gameName}</strong>
            </div>
            <div className={styles.summaryRow}>
              <span>Stake Amount</span>
              <strong>{formatKobo(stakeKobo)}</strong>
            </div>
            <div className={`${styles.summaryRow} ${styles.potentialWin}`}>
              <span>Potential Return</span>
              <strong>{formatKobo(potentialWinKobo)}</strong>
            </div>
            <div className={styles.payoutNotice}>
              <span>⚡</span>
              <span>Winnings will be sent automatically directly to your OPay wallet!</span>
            </div>
          </div>

          {error && <div className={styles.errorText} role="alert">{error}</div>}

          {step === "INITIAL" && (
            <form onSubmit={handleInitialPay}>
              <div className={styles.hint}>Funds will be charged directly from your registered OPay wallet.</div>

              <div className={styles.actions}>
                <button type="button" className={styles.btnSecondary} onClick={handleClose} disabled={isLoading}>
                  Cancel
                </button>
                <button type="submit" className={styles.btnPrimary} disabled={isLoading}>
                  {isLoading ? "Verifying OPay..." : "Pay via OPay ▶"}
                </button>
              </div>
            </form>
          )}

          {step === "PIN" && (
            <form onSubmit={handlePinSubmit}>
              <div className={styles.field}>
                <label htmlFor="opay-pin" className={styles.label}>
                  Enter 4-Digit OPay PIN
                </label>
                <input
                  id="opay-pin"
                  type="password"
                  inputMode="numeric"
                  pattern="[0-9]*"
                  maxLength={4}
                  autoFocus
                  className={`${styles.input} ${styles.otpInput}`}
                  placeholder="••••"
                  value={pin}
                  onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))}
                  disabled={isLoading}
                />
                <div className={styles.hint}>Enter your personal OPay wallet PIN to authorize payment.</div>
              </div>

              <div className={styles.actions}>
                <button type="button" className={styles.btnSecondary} onClick={() => setStep("INITIAL")} disabled={isLoading}>
                  Back
                </button>
                <button type="submit" className={styles.btnPrimary} disabled={isLoading || pin.length !== 4}>
                  {isLoading ? "Authorizing..." : "Authorize PIN ▶"}
                </button>
              </div>
            </form>
          )}

          {step === "OTP" && (
            <form onSubmit={handleOtpSubmit}>
              <div className={styles.field}>
                <label htmlFor="opay-otp" className={styles.label}>
                  Enter SMS Verification OTP
                </label>
                <input
                  id="opay-otp"
                  type="text"
                  inputMode="numeric"
                  maxLength={8}
                  autoFocus
                  className={`${styles.input} ${styles.otpInput}`}
                  placeholder="123456"
                  value={otp}
                  onChange={(e) => setOtp(e.target.value.trim())}
                  disabled={isLoading}
                />
                <div className={styles.hint}>Enter the one-time code sent to your registered mobile number.</div>
              </div>

              <div className={styles.actions}>
                <button type="button" className={styles.btnSecondary} onClick={() => setStep("INITIAL")} disabled={isLoading}>
                  Back
                </button>
                <button type="submit" className={styles.btnPrimary} disabled={isLoading || !otp}>
                  {isLoading ? "Confirming..." : "Confirm OTP ▶"}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
