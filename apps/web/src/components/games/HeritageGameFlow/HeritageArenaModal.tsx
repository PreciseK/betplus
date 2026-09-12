"use client";

import { useEffect, useState, useRef } from "react";
import type { HeritageSettlement, HeritagePrizeTier, HeritageBoardPosition, HeritageCatalogueItem } from "@/mocks/heritage";
import { formatKobo } from "@/lib/money";
import { sounds } from "@/components/games/BlackRedPlayModal/soundEffects";
import styles from "./HeritageArenaModal.module.css";

interface HeritageArenaModalProps {
  isOpen: boolean;
  onClose: () => void;
  settlement: HeritageSettlement | null;
  traditionName: string;
  leaderTitle: string;
  isMuted: boolean;
  toggleMute: () => void;
  onPlayAgain: () => void;
  prizeTiers: readonly HeritagePrizeTier[];
}

const FIVE_REGALIA_SLOTS = [
  { id: "head", label: "Head", cssClass: styles.slotHead },
  { id: "neck", label: "Neck", cssClass: styles.slotNeck },
  { id: "torso", label: "Torso", cssClass: styles.slotTorso },
  { id: "hand", label: "Hand", cssClass: styles.slotHand },
  { id: "feet", label: "Feet", cssClass: styles.slotFeet },
] as const;

export function HeritageArenaModal({
  isOpen,
  onClose,
  settlement,
  traditionName,
  leaderTitle,
  isMuted,
  toggleMute,
  onPlayAgain,
  prizeTiers,
}: HeritageArenaModalProps) {
  const [revealedCount, setRevealedCount] = useState<number>(0);
  const [stage, setStage] = useState<"revealing" | "flash" | "settled">("revealing");
  const [confetti, setConfetti] = useState<Array<{ id: number; left: number; color: string; delay: number; duration: number }>>([]);
  const hasTriggeredSettlement = useRef<boolean>(false);

  // Reset state when modal opens with new settlement
  useEffect(() => {
    if (isOpen && settlement) {
      setRevealedCount(0);
      setStage("revealing");
      hasTriggeredSettlement.current = false;

      // Play start shuffle
      sounds.playShuffle();

      // Sequential tile reveals
      let count = 0;
      const revealInterval = setInterval(() => {
        count += 1;
        setRevealedCount(count);
        const pos = settlement.board[count - 1];
        const isHit = Boolean(pos?.selected && pos?.winning);
        sounds.playFlip(isHit);

        if (count >= 9) {
          clearInterval(revealInterval);
          // Wait 300ms after last tile before showing flash banner
          setTimeout(() => {
            const isJackpot = settlement.matchCount === 5 || settlement.tier === "jackpot";
            const isTried = settlement.matchCount === 4 || settlement.tier === "high";

            if (isJackpot) {
              sounds.playWinFanfare();
              // Generate celebratory victory green & gold confetti
              const pieces = Array.from({ length: 48 }, (_, i) => ({
                id: i,
                left: Math.random() * 100,
                color: ["#22c55e", "#86efac", "#ffd88a", "#ffffff", "#15803d"][i % 5],
                delay: Math.random() * 0.4,
                duration: 1.2 + Math.random() * 0.8,
              }));
              setConfetti(pieces);
            } else if (isTried) {
              sounds.playWinFanfare();
              // Generate warm golden-yellow celebratory sparks for You Tried (half stake refund)
              const pieces = Array.from({ length: 32 }, (_, i) => ({
                id: i,
                left: Math.random() * 100,
                color: ["#fef08a", "#facc15", "#ffffff", "#eab308", "#fde047"][i % 5],
                delay: Math.random() * 0.3,
                duration: 1.1 + Math.random() * 0.7,
              }));
              setConfetti(pieces);
            } else {
              sounds.playToggle();
            }

            setStage("flash");

            // Transition from flash banner to settled stage after 1.8s
            setTimeout(() => {
              setStage("settled");
            }, 1800);
          }, 350);
        }
      }, 200);

      return () => {
        clearInterval(revealInterval);
      };
    }
  }, [isOpen, settlement, isMuted]);

  if (!isOpen || !settlement) return null;

  const isJackpot = settlement.matchCount === 5 || settlement.tier === "jackpot";
  const isTried = settlement.matchCount === 4 || settlement.tier === "high";
  const isLoss = !isJackpot && !isTried;

  const revealedPositions = settlement.board.slice(0, revealedCount);
  const liveMatches = revealedPositions.filter((p) => p.selected && p.winning).length;
  const latestDressedItem: HeritageCatalogueItem | undefined = revealedPositions
    .filter((p) => p.selected && p.winning)
    .at(-1)?.item;

  const currentTierOdds = prizeTiers.find((t) => t.id === settlement.tier);

  return (
    <div className={styles.backdrop} role="dialog" aria-modal="true" aria-label="Heritage Interactive Reveal Arena">
      {/* Interactive Arena Window */}
      <div className={`${styles.modalWindow} ${stage === "flash" ? styles.hiddenDuringFlash : ""}`}>
        {/* Header */}
        <header className={styles.modalHeader}>
          <div className={styles.brandLockup}>
            <span className={styles.royalCrest} aria-hidden="true">👑</span>
            <span className={styles.brandTitle}>Heritage Arena</span>
            <span className={styles.traditionTag}>{traditionName} · {leaderTitle}</span>
          </div>
          <div className={styles.headerActions}>
            <button
              className={styles.soundBtn}
              type="button"
              onClick={toggleMute}
              title={isMuted ? "Unmute sound" : "Mute sound"}
              aria-label={isMuted ? "Unmute sound" : "Mute sound"}
            >
              {isMuted ? "🔇" : "🔊"}
            </button>
            <button
              className={styles.closeBtn}
              type="button"
              onClick={onClose}
              title="Close Arena"
              aria-label="Close Arena"
            >
              ✕
            </button>
          </div>
        </header>

        {/* Modal Body */}
        <div className={styles.modalBody}>
          {/* Status Row */}
          <div className={styles.arenaStatusRow}>
            <div className={styles.statusLead}>
              <span>{stage === "revealing" ? "Revealing Regalia Tiles..." : "Round Settled"}</span>
              <strong>{stage === "revealing" ? `${revealedCount} of 9 Tiles Turned` : `${settlement.matchCount} Positions Matched`}</strong>
            </div>
            <div className={styles.matchesBadge}>
              <strong>{liveMatches}</strong>
              <span>of 5 matched</span>
            </div>
          </div>

          {/* Main 2-Column Grid */}
          <div className={styles.arenaGrid}>
            {/* Left: 3x3 Tile Board */}
            <ol className={styles.arenaBoard} aria-label="Heritage 3x3 Arena Board">
              {settlement.board.map((pos, index) => {
                const isRevealed = index < revealedCount;
                let outcomeResult = "unpicked-not";
                if (pos.selected && pos.winning) outcomeResult = "picked-winning";
                else if (pos.selected && !pos.winning) outcomeResult = "picked-not";
                else if (!pos.selected && pos.winning) outcomeResult = "unpicked-winning";

                return (
                  <li
                    key={pos.position}
                    className={styles.arenaTile}
                    data-selected={pos.selected}
                    data-revealed={isRevealed}
                    data-result={isRevealed ? outcomeResult : undefined}
                  >
                    <span className={styles.tilePositionNumber}>#{pos.position}</span>
                    {pos.selected && <span className={styles.tilePickedBadge}>Pick</span>}

                    {isRevealed ? (
                      <div>
                        <p className={styles.tileItemName}>{pos.item.localName ?? pos.item.canonicalName}</p>
                        <span className={styles.tileItemOrigin}>{pos.item.origin}</span>
                      </div>
                    ) : (
                      <span className={styles.tilePositionNumber}>Royal Tile</span>
                    )}

                    {isRevealed && (
                      <span className={`${styles.tileResultTag} ${pos.winning ? styles.tagWinning : styles.tagMiss}`}>
                        {pos.selected && pos.winning ? "MATCH 👑" : pos.winning ? "WINNER" : "UNMATCHED"}
                      </span>
                    )}
                  </li>
                );
              })}
            </ol>

            {/* Right: Royal Dressing Chamber */}
            <div className={styles.royalChamber}>
              <div className={styles.chamberHeading}>
                <h3>Royal Figure</h3>
                <span>{liveMatches} of 5 Regalia Dressed</span>
              </div>

              {/* Silhouette with 5 Regalia Slots */}
              <div className={styles.figureStage} aria-label="Abstract royal silhouette with five regalia slots">
                <span className={styles.figureCrown} aria-hidden="true">👑</span>
                <div className={styles.figureSilhouette} />

                {FIVE_REGALIA_SLOTS.map((slot) => {
                  const isEquipped = revealedPositions.some(
                    (p) =>
                      p.selected &&
                      p.winning &&
                      (p.item.slot === slot.id ||
                        (slot.id === "hand" && p.item.slot === "wrist") ||
                        (slot.id === "torso" && p.item.slot === "waist"))
                  );

                  return (
                    <span
                      key={slot.id}
                      className={`${styles.regaliaSlot} ${slot.cssClass}`}
                      data-equipped={isEquipped}
                    >
                      {slot.label}
                    </span>
                  );
                })}
              </div>

              {/* Item Spotlight */}
              <div className={styles.itemSpotlightCard}>
                <span className={styles.spotlightNumber}>
                  {latestDressedItem ? `No. ${latestDressedItem.number} · ${latestDressedItem.origin}` : "Regalia Reveal"}
                </span>
                <strong className={styles.spotlightName}>
                  {latestDressedItem?.localName ?? latestDressedItem?.canonicalName ?? "Match pieces to dress your royal"}
                </strong>
                <p className={styles.spotlightContext}>
                  {latestDressedItem?.context ?? "Each matched Nigerian regalia piece visibly equips your figure."}
                </p>
              </div>
            </div>
          </div>

          {/* Settled Result Panel */}
          {stage === "settled" && (
            <div className={`${styles.settlementPanel} ${isJackpot ? styles.panelWin : isTried ? styles.panelTried : styles.panelLoss}`} role="alert">
              <div className={styles.settlementHeadingRow}>
                <div className={styles.rpgBadgeLockup}>
                  <span className={styles.settlementBadge}>
                    {isJackpot ? "👑 Royal Victory" : isTried ? "🛡️ You Tried" : "🛡️ Quest Settled"}
                  </span>
                  <div className={styles.starRatingRow} aria-label={`${isJackpot ? 3 : isTried ? 2 : settlement.matchCount >= 1 ? 1 : 0} of 3 Stars`}>
                    {[1, 2, 3].map((starIndex) => {
                      const earned = isJackpot
                        ? true
                        : isTried
                        ? starIndex <= 2
                        : starIndex <= (settlement.matchCount >= 1 ? 1 : 0);
                      return (
                        <span key={starIndex} className={styles.starItem} data-earned={earned}>
                          {earned ? "⭐" : "☆"}
                        </span>
                      );
                    })}
                  </div>
                </div>
                <span className={styles.settlementRef}>Ticket #{settlement.reference}</span>
              </div>

              <div className={styles.settlementMain}>
                {isJackpot ? (
                  <div>
                    <span style={{ fontSize: 11, color: "#86efac", textTransform: "uppercase", fontWeight: 800, letterSpacing: "0.06em" }}>Jackpot Victory Claimed</span>
                    <div className={styles.payoutBig} style={{ color: "#4ade80" }}>+{formatKobo(settlement.netKobo)}</div>
                    <div style={{ fontSize: 12, color: "#34d399", fontWeight: 700, marginTop: 4 }}>
                      ⚡ Payout sent directly to your OPay wallet
                    </div>
                  </div>
                ) : isTried ? (
                  <div>
                    <span style={{ fontSize: 11, color: "#fde047", textTransform: "uppercase", fontWeight: 800, letterSpacing: "0.06em" }}>Half Stake Returned (You Tried)</span>
                    <div className={styles.payoutBig} style={{ color: "#facc15" }}>+{formatKobo(settlement.netKobo)}</div>
                    <p style={{ margin: "2px 0 0", fontSize: 12, color: "#fef08a" }}>Matched 4 of 5 positions · 50% stake back</p>
                    <div style={{ fontSize: 12, color: "#34d399", fontWeight: 700, marginTop: 4 }}>
                      ⚡ Payout sent directly to your OPay wallet
                    </div>
                  </div>
                ) : (
                  <div>
                    <strong style={{ fontSize: 16, color: "#f87171" }}>{settlement.matchCount} of 5 positions matched</strong>
                    <p style={{ margin: 0, fontSize: 12, color: "#9ca1b2" }}>No prize was returned for this round.</p>
                  </div>
                )}
              </div>

              <div className={styles.actionRow}>
                <button className={styles.btnPlayAgain} type="button" onClick={onPlayAgain}>
                  {isLoss ? "⚔️ Re-Enter Quest" : isTried ? "🪙 Play Again" : "🪙 Claim & Play Again"}
                </button>
                <button className={styles.btnCloseModal} type="button" onClick={onClose}>
                  Exit game
                </button>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Full-Screen Zoom-Out Flash Banner */}
      {stage === "flash" && (
        <div className={`${styles.flashOverlay} ${isJackpot ? styles.flashWin : isTried ? styles.flashTried : styles.flashLoss}`} aria-live="assertive" role="alert">
          <div className={styles.flashInner}>
            <div className={styles.flashEmblem} aria-hidden="true">
              {isJackpot ? "👑" : isTried ? "🛡️" : "🛡️"}
            </div>
            <div className={styles.flashStarsRow}>
              {[1, 2, 3].map((starIndex) => {
                const earned = isJackpot
                  ? true
                  : isTried
                  ? starIndex <= 2
                  : starIndex <= (settlement.matchCount >= 1 ? 1 : 0);
                return (
                  <span key={starIndex} className={styles.flashStar} data-earned={earned}>
                    {earned ? "⭐" : "☆"}
                  </span>
                );
              })}
            </div>
            <h1 className={`${styles.flashBannerText} ${isJackpot ? styles.flashBannerTextWin : isTried ? styles.flashBannerTextTried : styles.flashBannerTextLoss}`}>
              {isJackpot ? "YOU WIN!!!" : isTried ? "YOU TRIED" : "MISSED"}
            </h1>
            <p className={styles.flashSubtitle}>
              {isJackpot
                ? `${formatKobo(settlement.netKobo)} Net Prize · 5 of 5 Regalia Matched`
                : isTried
                ? `Half stake returned (${formatKobo(settlement.netKobo)}) · 4 of 5 Regalia Matched`
                : `${settlement.matchCount} of 5 matched · No prize`}
            </p>
          </div>

          {!isLoss && confetti.length > 0 && (
            <div className={styles.confettiContainer} aria-hidden="true">
              {confetti.map((item) => (
                <div
                  key={item.id}
                  className={styles.confettiPiece}
                  style={{
                    left: `${item.left}%`,
                    backgroundColor: item.color,
                    animationDelay: `${item.delay}s`,
                    animationDuration: `${item.duration}s`,
                  }}
                />
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
