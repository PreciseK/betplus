"use client";

import { useState, useEffect, useRef, useId } from "react";
import { blackRedGateway, BlackRedGatewayError } from "@betplus/api-client";
import type { BlackRedSettlement, BlackRedTier } from "@/mocks/blackred";
import { sounds } from "./soundEffects";
import { generate12CardDeck, drawCardsMatchingServerResult, type DeckCard, type DeckCardColor } from "../blackRedDeck";
import { blackRedPlayErrorMessage } from "../blackRedErrors";
import styles from "./BlackRedPlayModal.module.css";

export interface BlackRedPlayModalProps {
  isOpen: boolean;
  onClose: () => void;
  playBalanceKobo?: number;
  currency?: string;
  onBalanceChange?: (newPlayBalanceKobo: number, newWinningsKobo: number) => void;
}

type CardColor = DeckCardColor;
type Step = 1 | 2 | 3 | 4 | 5;
type Step4Phase = "inspect" | "shuffle";

// Labels/subtitles are cosmetic copy; the multiplier itself always comes from
// the live prize table (tiers state below) — never hardcoded here. A published
// prize-table change must show up in this picker without a client deploy.
const GAME_TYPE_COPY = [
  { count: 1, label: "One Card", sub: "Easiest" },
  { count: 2, label: "Two Cards", sub: "Classic" },
  { count: 3, label: "Three Cards", sub: "Popular" },
  { count: 4, label: "Four Cards", sub: "Risky" },
  { count: 5, label: "Five Cards", sub: "Big Win" },
];

const STAKE_PRESETS_NAIRA = [100, 200, 500, 1000, 2000, 5000];

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

export function BlackRedPlayModal({
  isOpen,
  onClose,
  playBalanceKobo = 1_250_000,
  currency = "₦",
  onBalanceChange,
}: BlackRedPlayModalProps) {
  const [step, setStep] = useState<Step>(1);
  const [selectedCardsCount, setSelectedCardsCount] = useState<number>(3);
  const [picks, setPicks] = useState<CardColor[]>(["red", "black", "red"]);
  const [stakeNaira, setStakeNaira] = useState<number>(500);
  const [balanceKobo, setBalanceKobo] = useState<number>(playBalanceKobo);
  const [isMuted, setIsMuted] = useState<boolean>(false);

  // Step 4 Deck State
  const [step4Phase, setStep4Phase] = useState<Step4Phase>("inspect");
  const [inspectDeck, setInspectDeck] = useState<DeckCard[]>([]);

  // Step 5 Reveal State
  const [drawnCards, setDrawnCards] = useState<DeckCard[]>([]);
  const [revealedIndex, setRevealedIndex] = useState<number>(-1);
  const [isResolved, setIsResolved] = useState<boolean>(false);
  const [roundWon, setRoundWon] = useState<boolean>(false);
  const [roundRef, setRoundRef] = useState<string>("");
  const [settlement, setSettlement] = useState<BlackRedSettlement>();
  const [isSubmittingTicket, setIsSubmittingTicket] = useState(false);
  const [playError, setPlayError] = useState<string>();

  // Live prize-table tiers (positions -> multiplier), fetched from the same
  // engine that settles the ticket. Never hardcode a multiplier here — it must
  // match whatever prize table is actually published, or the "Potential Win"
  // preview lies to the player.
  const [tiers, setTiers] = useState<BlackRedTier[]>();

  const modalRef = useRef<HTMLDivElement>(null);
  const titleId = useId();

  // Initialize inspect deck and load the live prize table
  useEffect(() => {
    if (isOpen) {
      setInspectDeck(generate12CardDeck());
      setBalanceKobo(playBalanceKobo);
      blackRedGateway.loadGame().then((game) => setTiers(game.tiers)).catch(() => setTiers(undefined));
    }
  }, [isOpen, playBalanceKobo]);

  // Handle escape key
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape" && isOpen && step !== 5) {
        onClose();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isOpen, step, onClose]);

  if (!isOpen) return null;

  const currentTier = tiers?.find((tier) => tier.positions === selectedCardsCount);
  const multiplier = currentTier ? currentTier.multiplierHundredths / 100 : undefined;
  const potentialWinNaira = multiplier !== undefined ? stakeNaira * multiplier : undefined;
  const balanceNaira = balanceKobo / 100;

  function multiplierFor(count: number): number | undefined {
    const tier = tiers?.find((t) => t.positions === count);
    return tier ? tier.multiplierHundredths / 100 : undefined;
  }

  // Sound toggle
  const handleToggleMute = () => {
    const muted = sounds.toggleMute();
    setIsMuted(muted);
  };

  // Step 1: Select Cards Count
  const handleSelectGameType = (count: number) => {
    sounds.playToggle();
    setSelectedCardsCount(count);
  };

  const handleProceedToColours = () => {
    sounds.playDeal();
    setPicks(Array.from({ length: selectedCardsCount }, (_, i) => (i % 2 === 0 ? "red" : "black")));
    setStep(2);
  };

  // Step 2: Toggle Color
  const handleToggleColor = (index: number) => {
    sounds.playToggle();
    setPicks((prev) => {
      const next = [...prev];
      next[index] = next[index] === "red" ? "black" : "red";
      return next;
    });
  };

  // Step 2: Stake Change
  const handleStakeInput = (value: string) => {
    const parsed = parseInt(value.replace(/\D/g, ""), 10);
    setStakeNaira(isNaN(parsed) ? 0 : Math.min(parsed, 100_000));
  };

  const handleApplyPreset = (val: number) => {
    sounds.playToggle();
    setStakeNaira(val);
  };

  const handleProceedToConfirm = () => {
    sounds.playDeal();
    setStep(3);
  };

  // Step 3: Start Round -> place the real ticket, then go to Step 4 (Meet the
  // deck). The outcome is decided here, server-side, by the certified engine —
  // steps 4/5 only show it with suspense, never determine it.
  const handleStartRound = async () => {
    if (isSubmittingTicket) return;
    sounds.playDeal();
    setPlayError(undefined);
    setIsSubmittingTicket(true);
    try {
      const purchase = await blackRedGateway.purchaseTicket({
        prediction: picks.map((color) => (color === "red" ? "R" : "B")),
        stakeKobo: stakeNaira * 100,
        idempotencyKey: crypto.randomUUID(),
      });
      const result = await blackRedGateway.revealTicket(purchase.reference);

      setSettlement(result);
      setBalanceKobo(result.playBalanceAfterKobo);
      setInspectDeck(generate12CardDeck());
      setStep4Phase("inspect");
      setStep(4);
    } catch (err) {
      setPlayError(
        err instanceof BlackRedGatewayError
          ? blackRedPlayErrorMessage(err.code)
          : "Could not place that ticket. Please try again.",
      );
    } finally {
      setIsSubmittingTicket(false);
    }
  };

  // Step 4: Show New Set
  const handleShowNewSet = () => {
    sounds.playShuffle();
    setInspectDeck(generate12CardDeck());
  };

  // Step 4: Begin Shuffle
  const handleBeginShuffle = () => {
    sounds.playShuffle();
    setStep4Phase("shuffle");
  };

  // Step 4: Stop & Reveal -> Go to Step 5. serverResult/serverWon come from
  // settlement (the real, already-decided outcome) — never Math.random().
  const handleStopAndReveal = () => {
    if (!settlement) return;
    sounds.playShuffle();

    const drawn = drawCardsMatchingServerResult(settlement.result.slice(0, selectedCardsCount));
    setDrawnCards(drawn);
    setRevealedIndex(-1);
    setIsResolved(false);

    setRoundRef(settlement.reference);
    setStep(5);

    // Start sequential 3D card flip
    drawn.forEach((card, idx) => {
      setTimeout(() => {
        setRevealedIndex(idx);
        const pick = picks[idx];
        const isHit = card.color === pick;
        sounds.playFlip(isHit);
      }, (idx + 1) * 900);
    });

    // Final result resolution after all cards flip
    setTimeout(() => {
      setRoundWon(settlement.won);
      setIsResolved(true);

      if (settlement.won) {
        sounds.playWinFanfare();
        onBalanceChange?.(settlement.playBalanceAfterKobo, settlement.netCreditKobo);
      } else {
        onBalanceChange?.(settlement.playBalanceAfterKobo, 0);
      }
    }, (drawn.length + 1) * 900 + 400);
  };

  // Step 5: Play Again
  const handlePlayAgain = () => {
    sounds.playDeal();
    setStep(1);
    setIsResolved(false);
    setRevealedIndex(-1);
    setSettlement(undefined);
  };

  // Counts of hits
  const currentRevealed = drawnCards.slice(0, revealedIndex + 1);
  const hitsCount = currentRevealed.filter((c, idx) => c.color === picks[idx]).length;
  const missesCount = currentRevealed.length - hitsCount;

  return (
    <div className={styles.backdrop} role="dialog" aria-modal="true" aria-labelledby={titleId}>
      <div ref={modalRef} className={styles.modalWindow}>
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
            <button
              type="button"
              className={styles.closeBtn}
              onClick={onClose}
              aria-label="Close game modal"
            >
              ✕
            </button>
          </div>
        </header>

        {/* Modal Body */}
        <div className={styles.modalBody}>
          {/* Progress Bar */}
          <div className={styles.progressWrap}>
            <div className={styles.progressLabel}>
              <span>Step <strong className={styles.stepNumber}>{step}</strong> of 5</span>
              <span>
                {step === 1 && "Pick Game"}
                {step === 2 && "Colours & Stake"}
                {step === 3 && "Confirm Stake"}
                {step === 4 && (step4Phase === "inspect" ? "Meet Your Deck" : "Live Shuffle")}
                {step === 5 && "The Draw"}
              </span>
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
              STEP 1: PICK GAME TYPE
              ======================================================== */}
          {step === 1 && (
            <div>
              <h1 id={titleId} className={styles.stepTitle}>
                Pick your <em>game.</em>
              </h1>
              <p className={styles.stepSubtitle}>
                Select the number of cards you want to play to set your multiplier.
              </p>

              <div className={styles.gameTypesGrid}>
                {GAME_TYPE_COPY.map((gt) => {
                  const tileMultiplier = multiplierFor(gt.count);
                  return (
                    <button
                      key={gt.count}
                      type="button"
                      className={`${styles.gameTypeTile} ${selectedCardsCount === gt.count ? styles.active : ""}`}
                      onClick={() => handleSelectGameType(gt.count)}
                    >
                      <div className={styles.tileCardCount}>{gt.count}</div>
                      <div className={styles.tileLabel}>{gt.label}</div>
                      <div className={styles.tileMultiplier}>{tileMultiplier !== undefined ? `×${tileMultiplier}` : "…"}</div>
                      <div className={styles.tileSub}>{gt.sub}</div>
                    </button>
                  );
                })}
              </div>

              <div className={styles.btnGroup}>
                <button
                  type="button"
                  className={styles.btnPrimary}
                  onClick={handleProceedToColours}
                  disabled={!tiers}
                >
                  {tiers ? "Choose your colours →" : "Loading odds…"}
                </button>
              </div>
            </div>
          )}

          {/* ========================================================
              STEP 2: PICK COLOURS & STAKE
              ======================================================== */}
          {step === 2 && (
            <div>
              <h1 id={titleId} className={styles.stepTitle}>
                Pick your <em>colours.</em>
              </h1>
              <p className={styles.stepSubtitle}>
                Tap each card to toggle Red or Black, set your stake, then proceed.
              </p>

              <div className={styles.pickerHead}>
                <div className={styles.pickerHeadLeft}>
                  <span className={styles.pickerHeadLabel}>Selected Mode</span>
                  <span className={styles.pickerHeadValue}>{selectedCardsCount} Cards</span>
                </div>
                <div className={styles.pickerHeadMult}>{multiplier !== undefined ? `×${multiplier} Multiplier` : "Loading…"}</div>
              </div>

              {/* Cards Grid */}
              <div
                className={`${styles.cardsGrid} ${
                  selectedCardsCount === 1
                    ? styles.cardsGrid1
                    : selectedCardsCount === 2
                    ? styles.cardsGrid2
                    : selectedCardsCount === 3
                    ? styles.cardsGrid3
                    : selectedCardsCount === 4
                    ? styles.cardsGrid4
                    : styles.cardsGrid5
                }`}
              >
                {picks.map((color, idx) => (
                  <div key={idx} className={styles.cardPickerItem}>
                    <div className={styles.cardPickerNum}>Card {idx + 1}</div>
                    <button
                      type="button"
                      className={`${styles.colorToggle} ${styles[color]}`}
                      onClick={() => handleToggleColor(idx)}
                      aria-label={`Card ${idx + 1}: ${color}. Tap to toggle.`}
                    >
                      {color === "red" ? "R" : "B"}
                    </button>
                    <div className={styles.cardPickerHint}>Tap to toggle</div>
                  </div>
                ))}
              </div>

              {/* Stake Block */}
              <div className={styles.stakeBlock}>
                <div className={styles.stakeHeader}>
                  <span className={styles.stakeTitle}>Your Stake</span>
                  <span className={styles.stakeBalanceBadge}>
                    Balance: {currency}{balanceNaira.toLocaleString()}
                  </span>
                </div>

                <div className={styles.stakeInputRow}>
                  <span className={styles.currencyPrefix}>{currency}</span>
                  <input
                    type="tel"
                    className={styles.stakeInput}
                    value={stakeNaira || ""}
                    onChange={(e) => handleStakeInput(e.target.value)}
                    placeholder="0"
                  />
                </div>

                <div className={styles.chipList}>
                  {STAKE_PRESETS_NAIRA.map((preset) => (
                    <button
                      key={preset}
                      type="button"
                      className={styles.chipBtn}
                      onClick={() => handleApplyPreset(preset)}
                    >
                      {currency}{preset.toLocaleString()}
                    </button>
                  ))}
                </div>

                <div className={styles.potentialWinBar}>
                  <span className={styles.potentialWinLabel}>Potential Win</span>
                  <span className={styles.potentialWinValue}>
                    {potentialWinNaira !== undefined ? `${currency}${potentialWinNaira.toLocaleString()}` : "…"}
                  </span>
                </div>
              </div>

              <div className={styles.btnGroup}>
                <button
                  type="button"
                  className={styles.btnSecondary}
                  onClick={() => setStep(1)}
                >
                  ← Back
                </button>
                <button
                  type="button"
                  className={styles.btnPrimary}
                  disabled={stakeNaira <= 0 || stakeNaira > balanceNaira || multiplier === undefined}
                  onClick={handleProceedToConfirm}
                >
                  Proceed to confirm →
                </button>
              </div>
            </div>
          )}

          {/* ========================================================
              STEP 3: READY TO PLAY (CONFIRM)
              ======================================================== */}
          {step === 3 && (
            <div>
              <h1 id={titleId} className={styles.stepTitle}>
                Ready to <em>play?</em>
              </h1>
              <p className={styles.stepSubtitle}>
                Review your prediction and stake before meeting your deck of cards.
              </p>

              <div className={styles.confirmHero}>
                <div className={styles.confirmLabel}>Your Picks Sequence</div>
                <div className={styles.confirmPicksRow}>
                  {picks.map((p, idx) => (
                    <div key={idx} className={`${styles.confirmPickCard} ${styles[p]}`}>
                      {p === "red" ? "R" : "B"}
                    </div>
                  ))}
                </div>
              </div>

              <div className={styles.confirmBreakdown}>
                <div className={styles.breakdownRow}>
                  <span className={styles.breakdownRowLabel}>Stake</span>
                  <span className={styles.breakdownRowVal}>{currency}{stakeNaira.toLocaleString()}</span>
                </div>
                <div className={styles.breakdownRow}>
                  <span className={styles.breakdownRowLabel}>Multiplier</span>
                  <span className={styles.breakdownRowVal}>{multiplier !== undefined ? `×${multiplier}` : "…"}</span>
                </div>
                <div className={styles.breakdownRow}>
                  <span className={styles.breakdownRowLabel}>Balance after stake</span>
                  <span className={styles.breakdownRowVal}>
                    {currency}{Math.max(0, balanceNaira - stakeNaira).toLocaleString()}
                  </span>
                </div>
                <div className={styles.breakdownRow}>
                  <span className={styles.breakdownRowLabel}>Potential Win</span>
                  <span className={`${styles.breakdownRowVal} ${styles.gold}`}>
                    {potentialWinNaira !== undefined ? `${currency}${potentialWinNaira.toLocaleString()}` : "…"}
                  </span>
                </div>
              </div>

              {playError && <p className={styles.fieldError} role="alert">{playError}</p>}
              <div className={styles.btnGroup}>
                <button
                  type="button"
                  className={styles.btnSecondary}
                  onClick={() => setStep(2)}
                  disabled={isSubmittingTicket}
                >
                  ← Edit Picks
                </button>
                <button
                  type="button"
                  className={styles.btnPrimary}
                  onClick={handleStartRound}
                  disabled={isSubmittingTicket || multiplier === undefined}
                >
                  {isSubmittingTicket ? "Placing ticket…" : "Start the round ▶"}
                </button>
              </div>
            </div>
          )}

          {/* ========================================================
              STEP 4: DECK INSPECTION & RIFFLE SHUFFLE
              ======================================================== */}
          {step === 4 && (
            <div>
              <div className={styles.deckContainer}>
                {step4Phase === "inspect" ? (
                  <div>
                    <div className={styles.deckPhaseBadge}>The Deck Inspection</div>
                    <h2 className={styles.stepTitle}>
                      Meet your <em>deck.</em>
                    </h2>
                    <p className={styles.stepSubtitle}>
                      Every round is dealt from 12 distinct cards (6 Red, 6 Black).
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
                            <span className={styles.inspectRank} style={{ transform: "rotate(180deg)", alignSelf: "flex-end" }}>{card.rank}</span>
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
                    <div className={styles.deckPhaseBadge}>Live Card Shuffle</div>
                    <h2 className={styles.stepTitle}>
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

                    <div className={styles.confirmPicksRow} style={{ marginBottom: "16px" }}>
                      {picks.map((p, idx) => (
                        <div key={idx} className={`${styles.confirmPickCard} ${styles[p]}`} style={{ width: "36px", height: "48px", fontSize: "18px" }}>
                          {p === "red" ? "R" : "B"}
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
              STEP 5: THE DRAW & REVEAL
              ======================================================== */}
          {step === 5 && (
            <div>
              <div className={styles.revealHeader}>
                <h2 className={styles.stepTitle}>
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
                  const isHit = card.color === pick;

                  return (
                    <div key={idx} className={styles.revealCardWrap}>
                      <span className={styles.revealPickTag}>
                        Pick: {pick === "red" ? "R" : "B"}
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
                            <span style={{ fontSize: "14px", alignSelf: "flex-start" }}>{card.rank}</span>
                            <span style={{ fontSize: "28px" }}>{card.suit}</span>
                            <span style={{ fontSize: "14px", alignSelf: "flex-end", transform: "rotate(180deg)" }}>{card.rank}</span>
                          </div>
                        </div>
                      </div>

                      {isRevealed && (
                        <span className={`${styles.matchIndicator} ${isHit ? styles.hit : styles.miss}`}>
                          {isHit ? "MATCH" : "MISS"}
                        </span>
                      )}
                    </div>
                  );
                })}
              </div>

              {/* Settlement Panel */}
              {isResolved && (
                <div className={`${styles.resultPanel} ${roundWon ? styles.win : styles.lose}`}>
                  <h3 className={`${styles.resultTitle} ${roundWon ? styles.win : styles.lose}`}>
                    {roundWon ? "🎉 You Won!" : "Round Complete"}
                  </h3>
                  <p style={{ margin: 0, color: "rgba(246,241,231,0.85)" }}>
                    {roundWon
                      ? `Congratulations! All ${selectedCardsCount} cards matched your prediction.`
                      : `You matched ${hitsCount} of ${selectedCardsCount} cards. All cards must match to win.`}
                  </p>

                  <div className={styles.resultAmount}>
                    {roundWon && settlement
                      ? `+ ${currency}${(settlement.netCreditKobo / 100).toLocaleString()} net of tax`
                      : `- ${currency}${stakeNaira.toLocaleString()}`}
                  </div>

                  <div className={styles.resultRef}>
                    Ref: <strong>{roundRef}</strong> · {selectedCardsCount} Cards ({multiplier !== undefined ? `×${multiplier}` : ""})
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
                      onClick={handlePlayAgain}
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
