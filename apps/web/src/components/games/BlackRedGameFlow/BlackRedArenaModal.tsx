"use client";

import { useState, useEffect, useRef, useId } from "react";
import { sounds } from "../BlackRedPlayModal/soundEffects";
import { generate12CardDeck, drawCardsMatchingServerResult, type DeckCard } from "../blackRedDeck";
import styles from "./BlackRedArenaModal.module.css";

export interface BlackRedArenaModalProps {
  isOpen: boolean;
  onClose: () => void;
  cardsCount: number;
  multiplier: number;
  picks: Array<"R" | "B">;
  stakeNaira: number;
  currency?: string;
  onPlayAgain: () => void;
  onWinSettlement?: (netCreditKobo: number) => void;
  /**
   * The actual outcome, already decided server-side by the certified engine at
   * purchase time (App\Domain\Games\Engine\BlackRed\BlackRedEngine — a pure,
   * replayable function of a pre-committed seed). This component's job is to
   * show that real result with suspense, never to decide it — the deck-inspect
   * and shuffle animation is theatre; the color at each position below is fixed
   * before this modal even opens.
   */
  serverResult: Array<"R" | "B">;
  serverWon: boolean;
  netCreditKobo: number;
  reference: string;
}

type Step4Phase = "inspect" | "shuffle";
type BannerPhase = "idle" | "flash" | "settled";

const INSPECT_POSITIONS = [
  { x: -126, y: -30, r: -14 },
  { x: -90, y: -34, r: -10 },
  { x: -54, y: -38, r: -6 },
  { x: -18, y: -40, r: -2 },
  { x: 18, y: -40, r: 2 },
  { x: 54, y: -38, r: 6 },
  { x: 90, y: -34, r: 10 },
  { x: 126, y: -30, r: 14 },
  { x: -66, y: 34, r: -8 },
  { x: -22, y: 38, r: -3 },
  { x: 22, y: 38, r: 3 },
  { x: 66, y: 34, r: 8 },
];

export function BlackRedArenaModal({
  isOpen,
  onClose,
  cardsCount,
  multiplier,
  picks,
  stakeNaira,
  currency = "₦",
  onPlayAgain,
  onWinSettlement,
  serverResult,
  serverWon,
  netCreditKobo,
  reference,
}: BlackRedArenaModalProps) {
  const [step, setStep] = useState<4 | 5>(4);
  const [step4Phase, setStep4Phase] = useState<Step4Phase>("inspect");
  const [inspectDeck, setInspectDeck] = useState<DeckCard[]>([]);
  const [isMuted, setIsMuted] = useState<boolean>(false);

  // Step 5 Draw & Reveal
  const [drawnCards, setDrawnCards] = useState<DeckCard[]>([]);
  const [revealedIndex, setRevealedIndex] = useState<number>(-1);
  const [isResolved, setIsResolved] = useState<boolean>(false);
  const [roundWon, setRoundWon] = useState<boolean>(false);
  const [roundRef, setRoundRef] = useState<string>("");
  const [bannerPhase, setBannerPhase] = useState<BannerPhase>("idle");
  const [confettiItems, setConfettiItems] = useState<Array<{ id: number; left: number; color: string; delay: number; duration: number }>>([]);

  const titleId = useId();
  const modalRef = useRef<HTMLDivElement>(null);

  // Initialize deck on open
  useEffect(() => {
    if (isOpen) {
      setStep(4);
      setStep4Phase("inspect");
      setInspectDeck(generate12CardDeck());
      setRevealedIndex(-1);
      setIsResolved(false);
      setRoundWon(false);
      setBannerPhase("idle");
      setConfettiItems([]);
    }
  }, [isOpen]);

  // Handle escape key
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape" && isOpen && isResolved) {
        onClose();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isOpen, isResolved, onClose]);

  if (!isOpen) return null;

  const handleToggleMute = () => {
    const muted = sounds.toggleMute();
    setIsMuted(muted);
  };

  const handleShowNewSet = () => {
    sounds.playShuffle();
    setInspectDeck(generate12CardDeck());
  };

  const handleBeginShuffle = () => {
    sounds.playShuffle();
    setStep4Phase("shuffle");
  };

  const handleStopAndReveal = () => {
    sounds.playShuffle();
    // serverResult/serverWon/netCreditKobo/reference are the real, already-
    // settled outcome from BlackRedEngine (a pure, replayable function of a
    // pre-committed seed). Do NOT fall back to Math.random() here, ever — that
    // would mean the browser deciding a real-money outcome instead of the
    // certified server engine. If these props are ever unavailable, the caller
    // must not open this modal at all (see BlackRedGameFlow's handleStartArena).
    const drawn = drawCardsMatchingServerResult(serverResult.slice(0, cardsCount));
    setDrawnCards(drawn);
    setRevealedIndex(-1);
    setIsResolved(false);
    setBannerPhase("idle");

    setRoundRef(reference);
    setStep(5);

    // Staggered 3D card flips
    drawn.forEach((card, idx) => {
      setTimeout(() => {
        setRevealedIndex(idx);
        const pick = picks[idx] === "R" ? "red" : "black";
        const isHit = card.color === pick;
        sounds.playFlip(isHit);
      }, (idx + 1) * 900);
    });

    // Settlement and Dramatic Scale-Out Text Flash
    setTimeout(() => {
      setRoundWon(serverWon);

      // Trigger Full Screen Dramatic Zoom-Out Phase
      setBannerPhase("flash");

      if (serverWon) {
        sounds.playWinFanfare();
      } else {
        sounds.playFlip(false);
      }

      // After zoom-out finishes (~1800ms), return the pop-up modal with results!
      setTimeout(() => {
        setBannerPhase("settled");
        setIsResolved(true);

        if (serverWon) {
          const colors = ["#FF0000", "#E0B84B", "#22c55e", "#FFFFFF", "#38bdf8"];
          const confetti = Array.from({ length: 42 }).map((_, i) => ({
            id: i,
            left: Math.random() * 96 + 2,
            color: colors[i % colors.length],
            delay: Math.random() * 0.4,
            duration: 1.2 + Math.random() * 1.2,
          }));
          setConfettiItems(confetti);
          onWinSettlement?.(netCreditKobo);
        }
      }, 1800);
    }, (drawn.length + 1) * 900 + 350);
  };

  const currentRevealed = drawnCards.slice(0, revealedIndex + 1);
  const hitsCount = currentRevealed.filter((c, idx) => c.color === (picks[idx] === "R" ? "red" : "black")).length;
  const missesCount = currentRevealed.length - hitsCount;

  return (
    <div className={styles.backdrop} role="dialog" aria-modal="true" aria-labelledby={titleId}>
      {/* Full-Screen Dramatic Red Text Zoom-Out Animation */}
      {bannerPhase === "flash" && (
        <div className={styles.flashOverlay} aria-live="assertive" role="alert">
          <h1 className={styles.flashBannerText}>
            {roundWon ? "YOU WIN!!!" : "MISSED!!!"}
          </h1>
        </div>
      )}

      {/* Modal Window (Hidden during flash, then reappears to show result summary) */}
      <div
        ref={modalRef}
        className={`${styles.modalWindow} ${bannerPhase === "flash" ? styles.hiddenDuringFlash : ""}`}
      >
        {/* Header */}
        <header className={styles.modalHeader}>
          <div className={styles.brandLockup}>
            <img src="/assets/blackred-logo.png" alt="BlackRed" className={styles.brandLogo} />
            <span className={styles.brandTitle}>BlackRed Arena</span>
          </div>

          <div className={styles.headerActions}>
            <button
              type="button"
              className={styles.soundBtn}
              onClick={handleToggleMute}
              title={isMuted ? "Unmute Sound" : "Mute Sound"}
              aria-label={isMuted ? "Unmute sound effects" : "Mute sound effects"}
            >
              {isMuted ? "🔇" : "🔊"}
            </button>
            {isResolved && (
              <button
                type="button"
                className={styles.closeBtn}
                onClick={onClose}
                aria-label="Close game modal"
              >
                ✕
              </button>
            )}
          </div>
        </header>

        {/* Modal Body */}
        <div className={styles.modalBody}>
          {/* Progress Bar */}
          <div className={styles.progressWrap}>
            <div className={styles.progressLabel}>
              <span>Arena Step <strong className={styles.stepNumber}>{step}</strong> of 5</span>
              <span>{step === 4 ? (step4Phase === "inspect" ? "Meet Your Deck" : "Live 3D Shuffle") : "The Draw & Reveal"}</span>
            </div>
            <div className={styles.progressBar}>
              {[1, 2, 3, 4, 5].map((s) => (
                <div
                  key={s}
                  className={`${styles.progressStep} ${s < step ? styles.done : ""} ${s === step ? styles.active : ""}`}
                />
              ))}
            </div>
          </div>

          {/* ========================================================
              STEP 4: DECK INSPECTION & 3D RIFFLE SHUFFLE
              ======================================================== */}
          {step === 4 && (
            <div>
              <div className={styles.deckContainer}>
                {step4Phase === "inspect" ? (
                  <div>
                    <div className={styles.deckPhaseBadge}>The Deck Inspection</div>
                    <h2 id={titleId} className={styles.stepTitle}>
                      Meet your <em>deck.</em>
                    </h2>
                    <p className={styles.stepSubtitle}>
                      Every round is drawn from 12 distinct cards (6 Red, 6 Black).
                    </p>

                    {/* 12 Cards Fan */}
                    <div className={styles.inspectDeckFan}>
                      {inspectDeck.map((card, idx) => {
                        const pos = INSPECT_POSITIONS[idx % INSPECT_POSITIONS.length];
                        return (
                          <div
                            key={idx}
                            className={`${styles.inspectCard} ${styles[card.color]}`}
                            style={{
                              transform: `translate(${pos.x}px, ${pos.y}px) rotate(${pos.r}deg)`,
                              zIndex: idx + 1,
                            }}
                          >
                            <span className={styles.inspectRank}>{card.rank}</span>
                            <span className={styles.inspectSuit}>{card.suit}</span>
                            <span className={styles.inspectRank} style={{ transform: "rotate(180deg)", alignSelf: "flex-end" }}>
                              {card.rank}
                            </span>
                          </div>
                        );
                      })}
                    </div>

                    <div className={styles.inspectSummaryPill}>
                      <span className={styles.inspectCountRed}>● 6 red</span>
                      <span>·</span>
                      <span className={styles.inspectCountBlack}>● 6 black</span>
                    </div>

                    <div className={styles.btnGroup}>
                      <button
                        type="button"
                        className={styles.btnSecondary}
                        onClick={handleShowNewSet}
                      >
                        🔀 Show new set
                      </button>
                      <button
                        type="button"
                        className={styles.btnPrimary}
                        onClick={handleBeginShuffle}
                      >
                        Begin shuffle ▶
                      </button>
                    </div>
                  </div>
                ) : (
                  <div>
                    <div className={styles.deckPhaseBadge}>Live 3D Card Shuffle</div>
                    <h2 id={titleId} className={styles.stepTitle}>
                      Place your <em>trust.</em>
                    </h2>
                    <p className={styles.stepSubtitle}>
                      Cards are shuffling. Click Stop &amp; Reveal when you feel ready.
                    </p>

                    {/* 3D Riffle Shuffle Stack */}
                    <div className={styles.shuffleStack}>
                      {Array.from({ length: 12 }).map((_, i) => (
                        <div
                          key={i}
                          className={styles.shuffleCard}
                          style={{
                            "--base-x": `${(i - 6) * 18}px`,
                            "--base-r": `${(i - 6) * 2.5}deg`,
                            animationDelay: `${-i * 0.3}s`,
                          } as React.CSSProperties}
                        >
                          BR
                        </div>
                      ))}
                    </div>

                    <div className={styles.picksMiniPreview}>
                      {picks.map((p, idx) => (
                        <div key={idx} className={`${styles.miniPickCard} ${p === "R" ? styles.red : styles.black}`}>
                          {p}
                        </div>
                      ))}
                    </div>

                    <button
                      type="button"
                      className={`${styles.btnPrimary} ${styles.btnGold}`}
                      onClick={handleStopAndReveal}
                    >
                      ⏹ Stop &amp; Reveal Cards
                    </button>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* ========================================================
              STEP 5: THE DRAW & 3D CARD FLIP REVEAL
              ======================================================== */}
          {step === 5 && (
            <div>
              <div className={styles.revealHeader}>
                <h2 id={titleId} className={styles.stepTitle}>
                  The <em>Draw.</em>
                </h2>
                <div className={styles.runningCounter}>
                  <span className={styles.hitsCount}>✓ {hitsCount} hits</span>
                  <span>·</span>
                  <span className={styles.missesCount}>✗ {missesCount} misses</span>
                </div>
              </div>

              {/* 3D Cards Flipping Row */}
              <div className={styles.revealRow}>
                {drawnCards.map((card, idx) => {
                  const isRevealed = idx <= revealedIndex;
                  const pick = picks[idx];
                  const pickColor = pick === "R" ? "red" : "black";
                  const isHit = card.color === pickColor;

                  return (
                    <div
                      key={idx}
                      className={styles.revealCardWrap}
                      style={{ "--deal-idx": idx } as React.CSSProperties}
                    >
                      <span className={styles.revealPickTag}>
                        Pick: {pick}
                      </span>

                      <div
                        className={`${styles.flipCard} ${isRevealed ? styles.flipped : ""} ${
                          isRevealed ? (isHit ? styles.hit : styles.miss) : ""
                        }`}
                      >
                        <div className={styles.flipInner}>
                          <div className={`${styles.flipFace} ${styles.flipBack}`}>
                            BR
                          </div>
                          <div className={`${styles.flipFace} ${styles.flipFront} ${styles[card.color]}`}>
                            <div style={{ display: "flex", alignItems: "center", gap: "2px", alignSelf: "flex-start", fontSize: "12px", fontWeight: 900 }}>
                              <span>{card.rank}</span>
                              <span style={{ fontSize: "10px" }}>{card.suit}</span>
                            </div>
                            <span style={{ fontSize: "32px", lineHeight: 1 }}>{card.suit}</span>
                            <div style={{ display: "flex", alignItems: "center", gap: "2px", alignSelf: "flex-end", transform: "rotate(180deg)", fontSize: "12px", fontWeight: 900 }}>
                              <span>{card.rank}</span>
                              <span style={{ fontSize: "10px" }}>{card.suit}</span>
                            </div>
                          </div>
                        </div>
                      </div>

                      {isRevealed && (
                        <span className={`${styles.matchIndicator} ${isHit ? styles.hit : styles.miss}`}>
                          {isHit ? "MATCH ✓" : "MISS ✗"}
                        </span>
                      )}
                    </div>
                  );
                })}
              </div>

              {/* Celebration Result Panel with You Win / Missed Summary */}
              {isResolved && bannerPhase === "settled" && (
                <div className={`${styles.resultPanel} ${roundWon ? styles.win : styles.lose}`}>
                  {/* Dynamic Confetti for Win */}
                  {roundWon && (
                    <div className={styles.confettiLayer} aria-hidden="true">
                      {confettiItems.map((c) => (
                        <div
                          key={c.id}
                          className={styles.confettiParticle}
                          style={{
                            left: `${c.left}%`,
                            backgroundColor: c.color,
                            animationDelay: `${c.delay}s`,
                            animationDuration: `${c.duration}s`,
                          }}
                        />
                      ))}
                    </div>
                  )}

                  <h3 className={`${styles.resultTitle} ${roundWon ? styles.win : styles.lose}`}>
                    {roundWon ? "🎉 You Won!" : "Round Complete — Missed"}
                  </h3>
                  <p style={{ margin: 0, color: "rgba(246,241,231,0.88)", fontSize: "14px" }}>
                    {roundWon
                      ? `All ${cardsCount} cards matched your prediction sequence!`
                      : `You matched ${hitsCount} of ${cardsCount} cards. Every position must match to win.`}
                  </p>

                  <div className={styles.resultAmount}>
                    {roundWon
                      ? `+ ${currency}${(((netCreditKobo ?? Math.round(stakeNaira * multiplier * 100))) / 100).toLocaleString()} net of tax`
                      : `− ${currency}${stakeNaira.toLocaleString()}`}
                  </div>

                  <div className={styles.resultRef}>
                    Ref: <strong>{roundRef}</strong> · {cardsCount} Cards (×{multiplier})
                  </div>

                  <div className={styles.btnGroup}>
                    <button
                      type="button"
                      className={styles.btnSecondary}
                      onClick={onClose}
                    >
                      Close to Dashboard
                    </button>
                    <button
                      type="button"
                      className={styles.btnPrimary}
                      onClick={onPlayAgain}
                    >
                      Play Again ↺
                    </button>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
