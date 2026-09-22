"use client";

import React from "react";
import styles from "./BirdEscapeGameFlow.module.css";

export interface BetSlotState {
  stake: number; // Naira
  autoCashout: boolean;
  autoCashoutMult: number;
  status: "idle" | "queued" | "placing" | "placed" | "active" | "cashing_out" | "cashed_out" | "lost";
  betId?: number;
  error?: string;
  cashedOutMultiplier?: number;
  netCreditKobo?: number;
}

interface BetSlotProps {
  label: string;
  state: BetSlotState;
  balanceKobo: number;
  roundStatus: "BETTING" | "FLYING" | "CRASHED";
  liveMultiplierHundredths: number;
  formatNaira: (kobo: number) => string;
  presetChips: number[];
  onChangeStake: (stake: number) => void;
  onHalfStake: () => void;
  onDoubleStake: () => void;
  onMaxStake: () => void;
  onSelectPreset: (stake: number) => void;
  onChangeAutoCashoutMult: (mult: number) => void;
  onToggleAutoCashout: (enabled: boolean) => void;
  onPlaceOrCancel: () => void;
  onCashout: () => void;
}

export function BetSlot({
  label,
  state,
  balanceKobo,
  roundStatus,
  liveMultiplierHundredths,
  formatNaira,
  presetChips,
  onChangeStake,
  onHalfStake,
  onDoubleStake,
  onMaxStake,
  onSelectPreset,
  onChangeAutoCashoutMult,
  onToggleAutoCashout,
  onPlaceOrCancel,
  onCashout,
}: BetSlotProps) {
  const locked = state.status !== "idle" && state.status !== "queued";
  const liveCashoutKobo = Math.round((state.stake * 100 * liveMultiplierHundredths) / 100);
  const isFlying = roundStatus === "FLYING";
  const isBetting = roundStatus === "BETTING";
  const isCrashed = roundStatus === "CRASHED";

  const isLoss = state.status === "lost" || ((state.status === "placed" || state.status === "active") && isCrashed);

  return (
    <div className={`${styles.betCard} ${isLoss ? styles.betCardLost : ""}`}>
      <div className={styles.betCardHeader}>
        <span className={styles.betSlotTitle}>{label}</span>
        <span className={styles.slotBalance}>
          Balance: <strong>{formatNaira(balanceKobo)}</strong>
        </span>
      </div>

      <div className={styles.inputGroup}>
        <label className={styles.inputLabel}>Stake</label>
        <div className={styles.amountInputRow}>
          <span className={styles.currencySymbol}>₦</span>
          <input
            type="number"
            className={styles.amountInput}
            value={state.stake}
            onChange={(e) => onChangeStake(Math.max(1, parseFloat(e.target.value) || 0))}
            disabled={locked}
            step="5"
          />
          <div className={styles.modifiersGroup}>
            <button className={styles.modButton} onClick={onHalfStake} disabled={locked}>
              ½
            </button>
            <button className={styles.modButton} onClick={onDoubleStake} disabled={locked}>
              2x
            </button>
            <button className={styles.modButton} onClick={onMaxStake} disabled={locked}>
              Max
            </button>
          </div>
        </div>
      </div>

      <div className={styles.presetChipsRow}>
        {presetChips.map((chip) => (
          <button key={`chip_${label}_${chip}`} className={styles.presetChip} onClick={() => onSelectPreset(chip)} disabled={locked}>
            ₦{chip.toLocaleString()}
          </button>
        ))}
      </div>

      <div className={styles.autoCashoutRow}>
        <div className={styles.autoCashoutLeft}>
          <span className={styles.autoCashoutLabel}>Auto Cash-out at (2.00× – 15.00×)</span>
          <input
            type="number"
            className={styles.autoCashoutInput}
            value={state.autoCashoutMult}
            min={2.0}
            max={15.0}
            step="0.1"
            onChange={(e) => {
              const val = parseFloat(e.target.value);
              onChangeAutoCashoutMult(isNaN(val) ? 2.0 : val);
            }}
            onBlur={(e) => {
              const val = parseFloat(e.target.value);
              const clamped = isNaN(val) || val < 2.0 ? 2.0 : Math.min(15.0, Math.round(val * 100) / 100);
              onChangeAutoCashoutMult(clamped);
            }}
            disabled={locked}
          />
        </div>
        <label className={styles.switchToggle}>
          <input type="checkbox" checked={state.autoCashout} onChange={(e) => onToggleAutoCashout(e.target.checked)} disabled={locked} />
          <span className={styles.slider} />
        </label>
      </div>

      {state.error && <div className={styles.betError}>{state.error}</div>}

      {/* When IDLE during BETTING phase */}
      {state.status === "idle" && isBetting && (
        <button className={styles.mainActionButton} onClick={onPlaceOrCancel}>
          Place Bet
        </button>
      )}

      {/* When IDLE during FLYING or CRASHED phase -> Allows queuing bet for next round anytime! */}
      {state.status === "idle" && !isBetting && (
        <button className={`${styles.mainActionButton} ${styles.betNextRoundBtn}`} onClick={onPlaceOrCancel}>
          Bet (Next Round)
        </button>
      )}

      {/* When QUEUED for next round */}
      {state.status === "queued" && (
        <button className={`${styles.mainActionButton} ${styles.betQueued}`} onClick={onPlaceOrCancel}>
          ✓ Queued for Next Round (Cancel)
        </button>
      )}

      {/* When PLACING */}
      {state.status === "placing" && (
        <button className={`${styles.mainActionButton} ${styles.betPlacing}`} disabled>
          Placing Bet…
        </button>
      )}

      {/* When PLACED and waiting for flight */}
      {state.status === "placed" && !isFlying && !isCrashed && (
        <button className={`${styles.mainActionButton} ${styles.betWaiting}`} disabled>
          ✓ Bet Placed (Waiting for flight)
        </button>
      )}

      {/* When ACTIVE in flight or CASHOUT in progress */}
      {(state.status === "active" || (state.status === "placed" && isFlying) || state.status === "cashing_out") && !isCrashed && (
        <button
          className={`${styles.mainActionButton} ${styles.cashoutActive}`}
          onClick={onCashout}
          disabled={state.status === "cashing_out"}
        >
          {state.status === "cashing_out" ? "Cashing out…" : `Cash Out ${formatNaira(liveCashoutKobo)}`}
        </button>
      )}

      {/* When CASHED OUT */}
      {state.status === "cashed_out" && (
        <button className={`${styles.mainActionButton} ${styles.cashedOutState}`} disabled>
          ✓ Cashed Out @ {((state.cashedOutMultiplier ?? 0) / 100).toFixed(2)}× (+{formatNaira(state.netCreditKobo ?? 0)})
        </button>
      )}

      {/* When LOST / MISSED */}
      {isLoss && (
        <button className={`${styles.mainActionButton} ${styles.betLost}`} disabled>
          💥 BIRD ESCAPED — MISSED
        </button>
      )}
    </div>
  );
}
