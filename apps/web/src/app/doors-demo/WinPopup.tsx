"use client";

import { useEffect, useRef } from "react";
import BigMan from "./BigMan";
import { naira, treasureName } from "./rules";
import s from "./doors.module.css";

export default function WinPopup({ amount, stake, multiplier, balance, netAmount, taxAmount, onClose, onReplay }: {
  amount: number; stake: number; multiplier: number; balance: number; netAmount?: number; taxAmount?: number; onClose: () => void; onReplay: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const element = dialog.current!;
    element.showModal();
    return () => { if (element.open) element.close(); };
  }, []);
  return <dialog ref={dialog} className={s.winDialog} aria-labelledby="doors-win-title" onCancel={event => { event.preventDefault(); onClose(); }}>
    <div className={s.winScene}>
      <BigMan />
      <section className={s.winCard}>
        <button className={s.winClose} aria-label="Close winnings" onClick={onClose}>×</button>
        <span className={s.winEyebrow}>BIG MAN SAYS</span>
        <h2 id="doors-win-title">“That treasure<br />is yours!”</h2>
        <span className={s.rewardBadge}>{treasureName(multiplier)} · {multiplier}×</span>
        <p className={s.youWon}>YOU WON</p><strong className={s.winAmount}>{naira(amount)}</strong>
        <p className={s.winMath}>{naira(stake)} stake × {multiplier}</p>
        {netAmount !== undefined && <p className={s.winMath}>Net winnings: {naira(netAmount)} · Tax withheld: {naira(taxAmount ?? 0)}{amount - netAmount - (taxAmount ?? 0) > 0 ? ` · Bonus principal reclaimed: ${naira(amount - netAmount - (taxAmount ?? 0))}` : ""}</p>}
        <p className={s.winBalance}>Current balance <b>{naira(balance)}</b></p>
        <button className={s.start} onClick={onReplay}><span>PLAY AGAIN</span><small>FIVE ATTEMPTS · FIRST TREASURE WINS</small></button>
        <button className={s.backToVault} onClick={onClose}>Back to the vault</button>
      </section>
    </div>
  </dialog>;
}
