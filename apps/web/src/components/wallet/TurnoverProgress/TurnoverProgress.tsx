import { formatKobo } from "@/lib/money";
import type { TurnoverPosition } from "@/mocks/payout";
import styles from "./TurnoverProgress.module.css";

export function TurnoverProgress({ turnover }: { turnover: TurnoverPosition }) {
  return (
    <section className={styles.progress} aria-labelledby="turnover-title">
      <div className={styles.heading}>
        <div>
          <p>Play Balance withdrawal</p>
          <h2 id="turnover-title">Turnover progress</h2>
        </div>
        <strong>{formatKobo(turnover.stakedKobo)} of {formatKobo(turnover.requiredStakeKobo)} staked</strong>
      </div>
      <progress
        aria-label={`${formatKobo(turnover.stakedKobo)} of ${formatKobo(turnover.requiredStakeKobo)} staked`}
        value={turnover.stakedKobo}
        max={turnover.requiredStakeKobo}
      />
      <p>Deposit money becomes eligible for Play Balance withdrawal after the required stake is completed. Game outcomes do not change this progress.</p>
      {turnover.releasedPlayBalanceKobo === 0 && (
        <small>No Play Balance is released for standard withdrawal yet. An un-staked deposit is never permanently retained and can be referred to Compliance for review.</small>
      )}
    </section>
  );
}
