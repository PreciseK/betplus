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
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string>();

  if (!isOpen) return null;

  const handlePay = async (e: FormEvent) => {
    e.preventDefault();
    setError(undefined);
    setIsLoading(true);
    try {
      const quote = await walletGateway.quoteFunding(stakeKobo);
      await walletGateway.collectDeposit(quote.quoteId);
      await onPaymentSuccess();
      onClose();
    } catch (error) {
      setError(
        error instanceof Error && error.message === "PENDING_REVIEW"
          ? "This stake needs manual review before it can be funded — please try a smaller amount or use your Play Balance instead."
          : "Could not verify your OPay wallet and balance for this payment. Please try again.",
      );
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

          <form onSubmit={handlePay}>
            <div className={styles.hint}>Funds will be verified and charged directly from your registered OPay wallet.</div>

            <div className={styles.actions}>
              <button type="button" className={styles.btnSecondary} onClick={onClose} disabled={isLoading}>
                Cancel
              </button>
              <button type="submit" className={styles.btnPrimary} disabled={isLoading}>
                {isLoading ? "Verifying OPay..." : "Pay via OPay ▶"}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}
