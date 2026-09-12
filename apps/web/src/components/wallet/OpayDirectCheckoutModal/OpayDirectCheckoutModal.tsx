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

export function OpayDirectCheckoutModal({
  isOpen,
  onClose,
  stakeKobo,
  potentialWinKobo,
  gameName,
  onPaymentSuccess,
}: OpayDirectCheckoutModalProps) {
  const [step, setStep] = useState<"phone" | "otp">("phone");
  const [phone, setPhone] = useState("0803 123 4567");
  const [otp, setOtp] = useState("");
  const [collectionId, setCollectionId] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string>();

  if (!isOpen) return null;

  const handleSendCode = async (e: FormEvent) => {
    e.preventDefault();
    if (!phone || phone.length < 10) {
      setError("Please enter a valid Nigerian phone number.");
      return;
    }
    setError(undefined);
    setIsLoading(true);
    try {
      const quote = await walletGateway.quoteFunding(stakeKobo);
      const collection = await walletGateway.createCollection(quote.quoteId);
      setCollectionId(collection.collectionId);
      setStep("otp");
    } catch {
      setError("Could not initiate OPay payment. Please check your network and try again.");
    } finally {
      setIsLoading(false);
    }
  };

  const handleVerifyOtp = async (e: FormEvent) => {
    e.preventDefault();
    if (!otp || otp.length < 4) {
      setError("Please enter the 6-digit OTP code.");
      return;
    }
    setError(undefined);
    setIsLoading(true);
    try {
      await walletGateway.submitCollectionOtp(collectionId, otp);
      await onPaymentSuccess();
      onClose();
    } catch {
      setError("Invalid or expired OTP. Please try again.");
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
          <button type="button" className={styles.closeBtn} onClick={onClose} aria-label="Close modal">
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

          {step === "phone" ? (
            <form onSubmit={handleSendCode}>
              <div className={styles.field}>
                <label className={styles.label} htmlFor="opay-phone">OPay Account / Phone Number</label>
                <input
                  id="opay-phone"
                  className={styles.input}
                  type="tel"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  placeholder="080... or +234..."
                  required
                />
                <div className={styles.hint}>Funds will be charged directly from this OPay account.</div>
              </div>

              <div className={styles.actions}>
                <button type="button" className={styles.btnSecondary} onClick={onClose} disabled={isLoading}>
                  Cancel
                </button>
                <button type="submit" className={styles.btnPrimary} disabled={isLoading}>
                  {isLoading ? "Connecting OPay..." : "Pay via OPay ▶"}
                </button>
              </div>
            </form>
          ) : (
            <form onSubmit={handleVerifyOtp}>
              <div className={styles.field}>
                <label className={styles.label} htmlFor="opay-otp">Enter 6-Digit OPay OTP</label>
                <input
                  id="opay-otp"
                  className={`${styles.input} ${styles.otpInput}`}
                  type="text"
                  inputMode="numeric"
                  maxLength={6}
                  value={otp}
                  onChange={(e) => setOtp(e.target.value.replace(/\D/g, ""))}
                  placeholder="••••••"
                  autoFocus
                  required
                />
                <div className={styles.hint}>Enter the confirmation code sent to {phone}.</div>
              </div>

              <div className={styles.actions}>
                <button type="button" className={styles.btnSecondary} onClick={() => setStep("phone")} disabled={isLoading}>
                  Back
                </button>
                <button type="submit" className={styles.btnPrimary} disabled={isLoading}>
                  {isLoading ? "Authorizing..." : "Confirm & Launch ▶"}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}
