"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { walletGateway, payoutGateway } from "@betplus/api-client";
import { formatKobo } from "@/lib/money";
import {
  formatMultiplier,
  getBlackRedTier,
  mockBlackRedGateway,
  BlackRedGatewayError,
  type BlackRedColor,
  type BlackRedDescriptor,
  type BlackRedGateway,
  type BlackRedSettlement,
} from "@/mocks/blackred";
import { BlackRedArenaModal } from "./BlackRedArenaModal";
import { OpayDirectCheckoutModal } from "@/components/wallet/OpayDirectCheckoutModal";
import { blackRedPlayErrorMessage } from "../blackRedErrors";
import styles from "./BlackRedGameFlow.module.css";

const QUICK_STAKES_KOBO = [10_000, 20_000, 50_000, 100_000, 200_000, 500_000] as const;

const GAME_TIER_SUBTITLES: Record<number, string> = {
  1: "Easiest",
  2: "Classic",
  3: "Popular",
  4: "Risky",
  5: "Big Win",
};

interface RoundRecord {
  reference: number | string;
  cards: number;
  won: boolean;
  amountKobo: number;
}

type DepositStage = "amount" | "otp" | "done";
type WithdrawStage = "amount" | "otp" | "done";

export function BlackRedGameFlow({ gateway = mockBlackRedGateway }: { gateway?: BlackRedGateway }) {
  const [descriptor, setDescriptor] = useState<BlackRedDescriptor>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [theme, setTheme] = useState<"dark" | "light">("dark");
  const [positionCount, setPositionCount] = useState<number | null>(null);
  const [prediction, setPrediction] = useState<Array<BlackRedColor | null>>([]);
  const [stakeKobo, setStakeKobo] = useState<number | null>(null);
  const [selectionError, setSelectionError] = useState<string>();
  const [stakeError, setStakeError] = useState<string>();
  const [settlement, setSettlement] = useState<BlackRedSettlement>();
  const [isSubmittingTicket, setIsSubmittingTicket] = useState(false);

  // Balances and session
  const [playBalanceKobo, setPlayBalanceKobo] = useState(0);
  const [winningsBalanceKobo, setWinningsBalanceKobo] = useState(0);
  const [roundsPlayed, setRoundsPlayed] = useState(0);
  const [totalStakedKobo, setTotalStakedKobo] = useState(0);
  const [totalWonKobo, setTotalWonKobo] = useState(0);
  const [sessionSeconds, setSessionSeconds] = useState(0);
  const [recentRounds, setRecentRounds] = useState<RoundRecord[]>([]);

  // Pop-up Screen (Steps 4 and 5 Arena)
  const [arenaModalOpen, setArenaModalOpen] = useState(false);
  const [paymentOption, setPaymentOption] = useState<"wallet" | "opay">("wallet");
  const [opayCheckoutOpen, setOpayCheckoutOpen] = useState(false);

  // Deposit Sheet & Limits
  const [depositOpen, setDepositOpen] = useState(false);
  const [depositStage, setDepositStage] = useState<DepositStage>("amount");
  const [depositAmount, setDepositAmount] = useState("");
  const [depositOtp, setDepositOtp] = useState("");
  const [depositError, setDepositError] = useState<string>();
  const [pendingCollectionId, setPendingCollectionId] = useState<string>();
  const [isSubmittingDeposit, setIsSubmittingDeposit] = useState(false);
  const depositTriggerRef = useRef<HTMLElement | null>(null);

  // Withdraw Sheet
  const [withdrawOpen, setWithdrawOpen] = useState(false);
  const [withdrawStage, setWithdrawStage] = useState<WithdrawStage>("amount");
  const [withdrawAmount, setWithdrawAmount] = useState("");
  const [withdrawError, setWithdrawError] = useState<string>();
  const [pendingWithdrawQuoteId, setPendingWithdrawQuoteId] = useState<string>();
  const [settledWithdrawReference, setSettledWithdrawReference] = useState<string>();
  const [isSubmittingWithdraw, setIsSubmittingWithdraw] = useState(false);
  const withdrawTriggerRef = useRef<HTMLElement | null>(null);

  const [limitReached, setLimitReached] = useState(false);
  const [realityAcknowledged, setRealityAcknowledged] = useState(false);
  const playButtonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    const prevBg = document.body.style.backgroundColor;
    document.body.style.backgroundColor = "#000000";
    return () => {
      document.body.style.backgroundColor = prevBg;
    };
  }, []);

  useEffect(() => {
    let active = true;
    gateway.loadGame()
      .then((game) => {
        if (!active) return;
        setDescriptor(game);
        setPlayBalanceKobo(game.playBalanceKobo);
        setWinningsBalanceKobo(game.winningsBalanceKobo);
      })
      .catch(() => {
        if (!active) return;
        setLoadFailed(true);
      });
    return () => { active = false; };
  }, [gateway]);

  useEffect(() => {
    const timer = window.setInterval(() => setSessionSeconds((current) => current + 1), 1000);
    return () => window.clearInterval(timer);
  }, []);

  if (loadFailed) return <SystemState title="BlackRed is unavailable" message="Game rules and stake limits could not be verified, so play is blocked." />;
  if (!descriptor) return <SystemState title="Loading BlackRed" message="Verifying the current prize table, tax rate, and stake range." loading />;

  const chosenPrediction = prediction.filter((choice): choice is BlackRedColor => choice !== null);
  const tier = positionCount ? getBlackRedTier(descriptor, positionCount) : undefined;
  const multiplierVal = tier ? tier.multiplierHundredths / 100 : 2;
  const grossPotentialKobo = tier && stakeKobo ? stakeKobo * multiplierVal : 0;
  const potentialTaxKobo = Math.round((grossPotentialKobo * descriptor.taxRateBasisPoints) / 10_000);
  const potentialNetKobo = grossPotentialKobo - potentialTaxKobo;
  const predictionReady = Boolean(positionCount && chosenPrediction.length === positionCount);
  const stakeReady = stakeKobo !== null && stakeKobo >= descriptor.minStakeKobo && stakeKobo <= descriptor.maxStakeKobo;
  const ready = predictionReady && stakeReady && !limitReached;
  const insufficientBalance = stakeKobo !== null && stakeKobo > playBalanceKobo;
  const netPositionKobo = totalWonKobo - totalStakedKobo;
  const showRealityCheck = !realityAcknowledged && (roundsPlayed >= 20 || sessionSeconds >= 30 * 60);

  const chooseCount = (count: number) => {
    setPositionCount(count);
    setPrediction(Array.from({ length: count }, () => null));
    setSelectionError(undefined);
    setStakeError(undefined);
  };

  const togglePrediction = (index: number) => {
    setPrediction((current) =>
      current.map((choice, choiceIndex) =>
        choiceIndex === index ? (choice === "R" ? "B" : "R") : choice
      )
    );
    setSelectionError(undefined);
  };

  const executePlaceTicket = async () => {
    if (!stakeKobo || isSubmittingTicket) return;
    setIsSubmittingTicket(true);
    try {
      const purchase = await gateway.purchaseTicket({
        prediction: chosenPrediction,
        stakeKobo,
        idempotencyKey: crypto.randomUUID(),
      });
      const result = await gateway.revealTicket(purchase.reference);

      setSettlement(result);
      setPlayBalanceKobo(result.playBalanceAfterKobo);
      setWinningsBalanceKobo(result.winningsBalanceAfterKobo);
      setTotalStakedKobo((prev) => prev + stakeKobo);
      setRoundsPlayed((prev) => prev + 1);
      setArenaModalOpen(true);
    } catch (err) {
      setStakeError(
        err instanceof BlackRedGatewayError
          ? blackRedPlayErrorMessage(err.code)
          : "Could not place that ticket. Please try again.",
      );
    } finally {
      setIsSubmittingTicket(false);
    }
  };

  const handleStartArena = async (event: FormEvent) => {
    event.preventDefault();
    let invalid = false;
    if (!positionCount || chosenPrediction.length !== positionCount) {
      setSelectionError("Choose Red or Black for every card position.");
      invalid = true;
    }
    if (!stakeReady) {
      setStakeError(`Enter a stake from ${formatKobo(descriptor.minStakeKobo)} to ${formatKobo(descriptor.maxStakeKobo)}.`);
      invalid = true;
    }
    if (paymentOption === "wallet" && insufficientBalance) {
      setStakeError("Your stake is higher than your Play Balance.");
      invalid = true;
    }
    if (invalid || !stakeKobo || isSubmittingTicket) return;

    if (paymentOption === "opay") {
      setOpayCheckoutOpen(true);
      return;
    }

    await executePlaceTicket();
  };

  const handleWinSettlement = (netCreditKobo: number) => {
    setTotalWonKobo((prev) => prev + netCreditKobo);
    setRecentRounds((prev) => [
      {
        reference: settlement?.reference ?? String(Date.now()).slice(-4),
        cards: positionCount || 1,
        won: true,
        amountKobo: netCreditKobo,
      },
      ...prev.slice(0, 4),
    ]);
  };

  const handleArenaClose = () => {
    setArenaModalOpen(false);
  };

  const handleArenaPlayAgain = () => {
    setArenaModalOpen(false);
  };

  const openDeposit = (trigger?: HTMLElement | React.MouseEvent<HTMLElement> | null) => {
    let el: HTMLElement | null = null;
    if (trigger && typeof trigger === "object" && "currentTarget" in trigger && trigger.currentTarget instanceof HTMLElement) {
      el = trigger.currentTarget;
    } else if (trigger instanceof HTMLElement) {
      el = trigger;
    } else if (document.activeElement instanceof HTMLElement) {
      el = document.activeElement;
    }
    depositTriggerRef.current = el;
    setDepositStage("amount");
    setDepositAmount("");
    setDepositOtp("");
    setPendingCollectionId(undefined);
    setDepositError(undefined);
    setDepositOpen(true);
  };

  const closeDeposit = () => {
    setDepositOpen(false);
    const trigger = depositTriggerRef.current;
    const restore = () => {
      if (trigger && document.contains(trigger) && typeof trigger.focus === "function") {
        trigger.focus({ preventScroll: true });
      } else {
        document.getElementById("blackred-inline-topup")?.focus({ preventScroll: true });
      }
    };
    restore();
    window.setTimeout(restore, 0);
  };

  const submitDepositAmount = async (event: FormEvent) => {
    event.preventDefault();
    const amount = Number(depositAmount.replaceAll(",", ""));
    if (!Number.isFinite(amount) || amount < 100) {
      setDepositError("Enter an amount of at least ₦100.");
      return;
    }
    setDepositError(undefined);
    setIsSubmittingDeposit(true);
    try {
      const quote = await walletGateway.quoteFunding(Math.round(amount * 100));
      const collection = await walletGateway.createCollection(quote.quoteId);
      setPendingCollectionId(collection.collectionId);
      setDepositStage("otp");
    } catch {
      setDepositError("Could not start that deposit. Please try again.");
    } finally {
      setIsSubmittingDeposit(false);
    }
  };

  const verifyDeposit = async (event: FormEvent) => {
    event.preventDefault();
    if (!/^\d{6}$/.test(depositOtp) || !pendingCollectionId) {
      setDepositError("Enter the six-digit OTP.");
      return;
    }
    setIsSubmittingDeposit(true);
    try {
      const transaction = await walletGateway.submitCollectionOtp(pendingCollectionId, depositOtp);
      setPlayBalanceKobo((current) => current + transaction.amountKobo);
      setStakeError(undefined);
      setDepositError(undefined);
      setDepositStage("done");
    } catch {
      setDepositError("That code didn't work — check it and try again.");
    } finally {
      setIsSubmittingDeposit(false);
    }
  };

  // Withdraw Sheet Actions
  const openWithdraw = (trigger?: HTMLElement | React.MouseEvent<HTMLElement> | null) => {
    let el: HTMLElement | null = null;
    if (trigger && typeof trigger === "object" && "currentTarget" in trigger && trigger.currentTarget instanceof HTMLElement) {
      el = trigger.currentTarget;
    } else if (trigger instanceof HTMLElement) {
      el = trigger;
    } else if (document.activeElement instanceof HTMLElement) {
      el = document.activeElement;
    }
    withdrawTriggerRef.current = el;
    setWithdrawStage("amount");
    setWithdrawAmount("");
    setPendingWithdrawQuoteId(undefined);
    setSettledWithdrawReference(undefined);
    setWithdrawError(undefined);
    setWithdrawOpen(true);
  };

  const closeWithdraw = () => {
    setWithdrawOpen(false);
    const trigger = withdrawTriggerRef.current;
    const restore = () => {
      if (trigger && document.contains(trigger) && typeof trigger.focus === "function") {
        trigger.focus({ preventScroll: true });
      }
    };
    restore();
    window.setTimeout(restore, 0);
  };

  const submitWithdrawAmount = async (event: FormEvent) => {
    event.preventDefault();
    const amountNaira = Number(withdrawAmount.replaceAll(",", ""));
    const amountKobo = amountNaira * 100;
    if (!Number.isFinite(amountNaira) || amountNaira < 500) {
      setWithdrawError("Enter a withdrawal amount of at least ₦500.");
      return;
    }
    if (amountKobo > winningsBalanceKobo) {
      setWithdrawError(`Amount exceeds your available Winnings Balance (${formatKobo(winningsBalanceKobo)}).`);
      return;
    }
    setWithdrawError(undefined);
    setIsSubmittingWithdraw(true);
    try {
      const quote = await payoutGateway.quoteWithdrawal("winnings", amountKobo);
      setPendingWithdrawQuoteId(quote.quoteId);
      setWithdrawStage("otp");
    } catch {
      setWithdrawError("Could not start that withdrawal. Please try again.");
    } finally {
      setIsSubmittingWithdraw(false);
    }
  };

  const verifyWithdraw = async (event: FormEvent) => {
    event.preventDefault();
    if (!pendingWithdrawQuoteId) return;
    setIsSubmittingWithdraw(true);
    try {
      const payout = await payoutGateway.requestWithdrawal(pendingWithdrawQuoteId);
      setWinningsBalanceKobo((current) => Math.max(0, current - payout.amountKobo));
      setSettledWithdrawReference(payout.reference);
      setWithdrawError(undefined);
      setWithdrawStage("done");
    } catch {
      setWithdrawError("Could not complete that withdrawal. Please try again.");
    } finally {
      setIsSubmittingWithdraw(false);
    }
  };

  return (
    <main className={styles.viewportContainer} data-theme={theme} id="main-content">
      <a className="skip-link" href="#blackred-playfield">Skip to game controls</a>

      {/* Top Header (Compact ~48px) */}
      <header className={styles.compactTopbar}>
        <div className={styles.topbarLeft}>
          <a className={styles.backBtn} href="/games" aria-label="Exit game and return to dashboard">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style={{ marginRight: '2px' }}>
              <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
            <span>Exit Game</span>
          </a>
          <div className={styles.topbarDivider}></div>
          <a className={styles.brand} href="/games" aria-label="Exit BlackRed and return to games">
            <img src="/assets/blackred-logo.png" alt="BlackRed" className={styles.brandLogo} />
            <strong className={styles.brandTitle}>Betplus BlackRed</strong>
          </a>
          <span className={styles.instantBadge}>⚡ Instant Fixed-Odds</span>
        </div>

        <div className={styles.topbarRight}>
          <div className={styles.compactBalanceChip}>
            <span className={styles.chipLabel}>Play:</span>
            <strong>{formatKobo(playBalanceKobo)}</strong>
          </div>
          <div className={styles.compactBalanceChip}>
            <span className={styles.chipLabel}>Winnings:</span>
            <strong className={styles.chipGold}>{formatKobo(winningsBalanceKobo)}</strong>
          </div>
          <button className={styles.topupBtn} type="button" onClick={(e) => openDeposit(e.currentTarget)}>+ Top Up</button>
          <button className={styles.withdrawTopBtn} type="button" onClick={(e) => openWithdraw(e.currentTarget)}>Withdraw</button>
          <button className={styles.themeBtn} type="button" onClick={() => setTheme((c) => c === "dark" ? "light" : "dark")} title="Toggle Theme">
            {theme === "dark" ? "☀" : "☾"}
          </button>
          <span className={styles.ageBadge}>18+</span>
        </div>
      </header>

      {/* Main Single-Viewport Workspace (Zero scroll, fills 100% remaining height) */}
      <div className={styles.viewportWorkspace} id="blackred-playfield">
        {/* Left Arena Gaming Surface */}
        <form className={styles.mainGamingSurface} noValidate autoComplete="off" onSubmit={handleStartArena}>
          
          {/* ========================================================
              STEP 1: PICK YOUR GAME (Cards Selector)
              ======================================================== */}
          <section className={styles.stepSectionCompact} aria-labelledby="step1-title">
            <div className={styles.stepHeaderRow}>
              <span className={styles.stepBadge}>Step 1</span>
              <h2 id="step1-title" className={styles.stepTitle}>Pick your <em>game</em></h2>
              <span className={styles.stepSubtitle}>Select cards count to set your prize multiplier</span>
            </div>

            <div className={styles.countTilesGrid}>
              {descriptor.tiers.map((option) => (
                <button
                  key={option.positions}
                  type="button"
                  className={`${styles.countTile} ${positionCount === option.positions ? styles.countTileActive : ""}`}
                  aria-label={`${option.positions} ${option.positions === 1 ? "position" : "positions"}`}
                  aria-pressed={positionCount === option.positions}
                  onClick={() => chooseCount(option.positions)}
                >
                  <strong className={styles.countTileNumber}>{option.positions}</strong>
                  <span className={styles.countTileMultiplier}>{formatMultiplier(option)}</span>
                  <small className={styles.countTileSubtitle}>{GAME_TIER_SUBTITLES[option.positions] || `1 in ${option.probabilityDenominator}`}</small>
                </button>
              ))}
            </div>
          </section>

          {/* ========================================================
              MIDDLE ROW: STEP 2 (PICK COLOURS) + LIVE WINNERS BESIDE IT
              ======================================================== */}
          <div className={styles.step2AndWinnersRow}>
            {/* Step 2: Prediction Cards */}
            <section className={styles.stepSectionCards} aria-labelledby="step2-title">
              <div className={styles.stepHeaderRow}>
                <span className={styles.stepBadge}>Step 2</span>
                <h2 id="step2-title" className={styles.stepTitle}>Pick your <em>colours</em></h2>
                <span className={styles.stepSubtitle}>Tap card to toggle Red/Black</span>
              </div>

              {positionCount ? (
                <div className={styles.cardsRowWrapper}>
                  <ol className={styles.cardsRow} aria-label="Predicted sequence">
                    {prediction.map((choice, index) => (
                      <li key={`prediction-${index}`} className={styles.cardItem}>
                        <button
                          className={styles.formerPredictionCard}
                          data-colour={choice ?? "empty"}
                          type="button"
                          aria-label={`Position ${index + 1}: ${choice === "R" ? "Red (R)" : choice === "B" ? "Black (B)" : "Not selected"}`}
                          onClick={() => togglePrediction(index)}
                        >
                          <span className={styles.cardSlotBadge}>{index + 1}</span>
                          <div className={styles.cardCenterBody}>
                            {choice ? (
                              <>
                                <b className={styles.cardSuitIcon} aria-hidden="true">
                                  {choice === "R" ? "♥" : "♠"}
                                </b>
                                <span className={styles.cardColorLabel}>
                                  {choice === "R" ? "Red" : "Black"}
                                </span>
                              </>
                            ) : (
                              <span className={styles.cardTapPrompt}>Tap</span>
                            )}
                          </div>
                        </button>
                      </li>
                    ))}
                  </ol>
                </div>
              ) : (
                <div className={styles.emptyCardsHero}>
                  <div className={styles.emptyHeroIcon}>B / R</div>
                  <p>Choose 1 to 5 cards above in Step 1 to pick colours</p>
                </div>
              )}
              {selectionError && <p className={styles.fieldError} role="alert">{selectionError}</p>}
            </section>
          </div>

          {/* ========================================================
              BOTTOM ROW: YOUR STAKE + STEP 3 CONFIRMATION & LAUNCH
              ======================================================== */}
          <div className={styles.bottomControlSplit}>
            {/* Stake Box */}
            <div className={styles.stakeBoxSection}>
              <div className={styles.splitBoxHeader}>
                <span className={styles.splitBoxTitle}>Your Stake</span>
                <span className={styles.stakeRangeHint}>
                  {formatKobo(descriptor.minStakeKobo)} min · {formatKobo(descriptor.maxStakeKobo)} max
                </span>
              </div>

              <div className={styles.stakeChipsGrid}>
                {QUICK_STAKES_KOBO.map((amount) => (
                  <button
                    key={amount}
                    type="button"
                    className={`${styles.stakeChip} ${stakeKobo === amount ? styles.stakeChipActive : ""}`}
                    aria-pressed={stakeKobo === amount}
                    onClick={() => {
                      setStakeKobo(amount);
                      setStakeError(undefined);
                    }}
                  >
                    {formatKobo(amount)}
                  </button>
                ))}
              </div>

              <div className={styles.stakeInputWrapper}>
                <span className={styles.nairaPrefix}>₦</span>
                <input
                  aria-label="Stake amount"
                  className={styles.stakeCustomInput}
                  inputMode="numeric"
                  value={stakeKobo === null ? "" : stakeKobo / 100}
                  placeholder="Custom stake"
                  onChange={(event) => {
                    const value = event.target.value.replace(/\D/g, "");
                    setStakeKobo(value ? Number(value) * 100 : null);
                    setStakeError(undefined);
                  }}
                />
              </div>
              {stakeError && <p className={styles.fieldError} role="alert">{stakeError}</p>}
              {insufficientBalance && (
                <div className={styles.inlineRecoveryRow}>
                  <span>Not enough Play Balance.</span>
                  <button id="blackred-inline-topup" type="button" onClick={(e) => openDeposit(e.currentTarget)}>Top up here</button>
                </div>
              )}
            </div>

            {/* Step 3 Confirmation & Launch Box */}
            <section className={styles.step3LaunchSection} aria-labelledby="step3-title">
              <div className={styles.splitBoxHeader}>
                <div style={{ display: "flex", alignItems: "center", gap: "6px" }}>
                  <span className={styles.stepBadge}>Step 3</span>
                  <h2 id="step3-title" className={styles.splitBoxTitle}>Ready to <em>Play</em></h2>
                </div>
                <div className={styles.recapPillRow}>
                  {prediction.length > 0 ? (
                    prediction.map((p, idx) => (
                      <span
                        key={idx}
                        className={`${styles.recapPill} ${p === "R" ? styles.pillRed : p === "B" ? styles.pillBlack : styles.pillEmpty}`}
                      >
                        {p ?? "?"}
                      </span>
                    ))
                  ) : (
                    <span className={styles.noPillText}>No cards</span>
                  )}
                </div>
              </div>

              {/* Financial Metrics Row */}
              <div className={styles.breakdownMetricsRow}>
                <div className={styles.breakdownItem}>
                  <span>Stake</span>
                  <strong>{formatKobo(stakeKobo ?? 0)}</strong>
                </div>
                <div className={styles.breakdownItem}>
                  <span>Multiplier</span>
                  <strong>{tier ? formatMultiplier(tier) : "—"}</strong>
                </div>
                <div className={styles.breakdownItem}>
                  <span>Potential Win</span>
                  <strong className={styles.goldWinAmount}>{formatKobo(potentialNetKobo)}</strong>
                </div>
              </div>

              {/* Payment Method Selector (Wallet vs OPay Direct) */}
              <div className={styles.paymentMethodSelector}>
                <button
                  type="button"
                  className={`${styles.paymentMethodTab} ${paymentOption === "wallet" ? styles.paymentMethodTabActive : ""}`}
                  onClick={() => setPaymentOption("wallet")}
                >
                  <span className={styles.paymentMethodTabTitle}>👛 Wallet Balance</span>
                  <span className={styles.paymentMethodTabSub}>{formatKobo(playBalanceKobo)} available</span>
                </button>
                <button
                  type="button"
                  className={`${styles.paymentMethodTab} ${paymentOption === "opay" ? styles.paymentMethodTabActiveOpay : ""}`}
                  onClick={() => setPaymentOption("opay")}
                >
                  <span className={styles.paymentMethodTabTitle}>⚡ OPay Direct</span>
                  <span className={styles.paymentMethodTabSubOpay}>Direct Debit & Auto-Payout</span>
                </button>
              </div>

              {/* Large Glowing CTA Button */}
              <button
                ref={playButtonRef}
                className={`${styles.bigLaunchCta} ${paymentOption === "opay" ? styles.bigLaunchCtaOpay : ""}`}
                type="submit"
                disabled={!ready || (paymentOption === "wallet" && insufficientBalance) || isSubmittingTicket}
              >
                {isSubmittingTicket
                  ? "Placing ticket…"
                  : ready
                    ? paymentOption === "opay"
                      ? `⚡ Pay with OPay · ${formatKobo(stakeKobo ?? 0)}`
                      : `Start Round ▶ · ${formatKobo(stakeKobo ?? 0)}`
                    : "Complete Steps 1 & 2 Above"}
              </button>
            </section>
          </div>
        </form>

        {/* Right Sidebar: Compact Account & History Rail */}
        <aside className={styles.compactAccountRail} aria-label="Account Overview and Recent Rounds">
          <div className={styles.railHeader}>
            <h3>Your Account</h3>
          </div>

          <div className={styles.railBalancesCard}>
            {/* Total Balance */}
            <div className={styles.railTotalBalanceRow}>
              <span className={styles.totalBalanceLabel}>Total Balance</span>
              <strong className={styles.totalBalanceAmount}>
                {formatKobo(playBalanceKobo + winningsBalanceKobo)}
              </strong>
            </div>

            <div className={styles.railBalanceRow}>
              <div>
                <span>Play Balance</span>
                <strong>{formatKobo(playBalanceKobo)}</strong>
              </div>
              <button className={styles.railTopupMini} type="button" onClick={(e) => openDeposit(e.currentTarget)}>+ Top Up</button>
            </div>

            <div className={styles.railBalanceRow}>
              <div>
                <span>Winnings Balance</span>
                <strong className={styles.goldWinAmount}>{formatKobo(winningsBalanceKobo)}</strong>
              </div>
              <button className={styles.railWithdrawMini} type="button" onClick={(e) => openWithdraw(e.currentTarget)}>Withdraw</button>
            </div>
          </div>

          {/* Turnover Progress */}
          <div className={styles.railTurnoverCard}>
            <div className={styles.turnoverLabels}>
              <span>Turnover:</span>
              <strong>{formatKobo(descriptor.turnoverStakedKobo)} / {formatKobo(descriptor.turnoverRequiredKobo)}</strong>
            </div>
            <div className={styles.turnoverTrack}>
              <div
                className={styles.turnoverFill}
                style={{ width: `${Math.min(100, (descriptor.turnoverStakedKobo / descriptor.turnoverRequiredKobo) * 100)}%` }}
              />
            </div>
          </div>

          {/* Recent Rounds */}
          <div className={styles.railRecentRounds}>
            <h4>Recent Activity</h4>
            <div className={styles.roundsListScroll}>
              {recentRounds.map((round, idx) => (
                <div key={`${round.reference}-${idx}`} className={styles.roundItemRow}>
                  <span className={styles.roundRefText}>#{round.reference} · {round.cards}c</span>
                  <span className={round.won ? styles.wonTag : styles.lostTag}>{round.won ? "Win" : "Loss"}</span>
                  <strong className={round.won ? styles.wonAmount : styles.lostAmount}>
                    {round.won ? "+" : ""}{formatKobo(Math.abs(round.amountKobo))}
                  </strong>
                </div>
              ))}
            </div>
          </div>
        </aside>
      </div>

      {/* Ultra Compact Legal Footer */}
      <footer className={styles.compactLegalBar}>
        <span>Tax deducted at source · 5% WHT</span>
        <span>Outcomes fixed at placement · 18+</span>
        <a href="/responsible-gambling">Responsible gambling</a>
      </footer>

      {/* Deposit Sheet Pop-up */}
      {depositOpen && (
        <DepositSheet
          stage={depositStage}
          amount={depositAmount}
          otp={depositOtp}
          error={depositError}
          isSubmitting={isSubmittingDeposit}
          onAmountChange={setDepositAmount}
          onOtpChange={setDepositOtp}
          onAmountSubmit={submitDepositAmount}
          onOtpSubmit={verifyDeposit}
          onClose={closeDeposit}
        />
      )}

      {/* Withdraw Sheet Pop-up */}
      {withdrawOpen && (
        <WithdrawSheet
          stage={withdrawStage}
          amount={withdrawAmount}
          error={withdrawError}
          availableKobo={winningsBalanceKobo}
          isSubmitting={isSubmittingWithdraw}
          reference={settledWithdrawReference}
          onAmountChange={setWithdrawAmount}
          onAmountSubmit={submitWithdrawAmount}
          onOtpSubmit={verifyWithdraw}
          onClose={closeWithdraw}
        />
      )}

      {/* Reality Check */}
      {showRealityCheck && (
        <RealityCheck
          rounds={roundsPlayed}
          totalStakedKobo={totalStakedKobo}
          netPositionKobo={netPositionKobo}
          seconds={sessionSeconds}
          onContinue={() => setRealityAcknowledged(true)}
        />
      )}

      {/* Step 4 & 5 Interactive Arena Modal Pop-up Screen — only rendered once a
          real, settled outcome exists; never opened speculatively. */}
      {settlement && (
        <BlackRedArenaModal
          isOpen={arenaModalOpen}
          onClose={handleArenaClose}
          cardsCount={positionCount || 1}
          multiplier={multiplierVal}
          picks={chosenPrediction}
          stakeNaira={(stakeKobo || 0) / 100}
          onPlayAgain={handleArenaPlayAgain}
          onWinSettlement={handleWinSettlement}
          serverResult={settlement.result}
          serverWon={settlement.won}
          netCreditKobo={settlement.netCreditKobo}
          reference={settlement.reference}
        />
      )}

      {/* OPay Direct Checkout Modal */}
      <OpayDirectCheckoutModal
        isOpen={opayCheckoutOpen}
        onClose={() => setOpayCheckoutOpen(false)}
        stakeKobo={stakeKobo ?? 0}
        potentialWinKobo={potentialNetKobo}
        gameName="Black & Red"
        onPaymentSuccess={executePlaceTicket}
      />
    </main>
  );
}

function containDialogFocus(event: React.KeyboardEvent<HTMLElement>, container: HTMLElement | null, onDismiss?: () => void) {
  if (event.key === "Escape") {
    event.preventDefault();
    onDismiss?.();
    return;
  }
  if (event.key !== "Tab" || !container) return;
  const focusables = Array.from(
    container.querySelectorAll<HTMLElement>(
      'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )
  ).filter((element) => element.offsetParent !== null || element.getClientRects().length > 0);
  if (!focusables.length) return;
  const first = focusables[0];
  const last = focusables[focusables.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

function DepositSheet({
  stage,
  amount,
  otp,
  error,
  isSubmitting,
  onAmountChange,
  onOtpChange,
  onAmountSubmit,
  onOtpSubmit,
  onClose,
}: {
  stage: DepositStage;
  amount: string;
  otp: string;
  error?: string;
  isSubmitting: boolean;
  onAmountChange: (value: string) => void;
  onOtpChange: (value: string) => void;
  onAmountSubmit: (event: FormEvent) => void;
  onOtpSubmit: (event: FormEvent) => void;
  onClose: () => void;
}) {
  const sheetRef = useRef<HTMLElement>(null);
  useEffect(() => {
    const initialTarget = sheetRef.current?.querySelector<HTMLElement>("input") ?? sheetRef.current?.querySelector<HTMLElement>("button");
    initialTarget?.focus({ preventScroll: true });
  }, []);

  const QUICK_AMOUNTS = [1000, 2000, 5000, 10000];

  return (
    <div className={styles.sheetBackdrop} onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
      <aside
        ref={sheetRef}
        className={styles.depositSheet}
        role="dialog"
        aria-modal="true"
        aria-label="Top up"
        tabIndex={-1}
        onKeyDown={(event) => containDialogFocus(event, sheetRef.current, onClose)}
      >
        <div className={styles.sheetHeading}>
          <div>
            <span className={styles.modalEyebrow}>Play Balance</span>
            <h2 id="deposit-title" className={styles.modalHeadingTitle}>
              {stage === "amount" ? "Top Up Account" : stage === "otp" ? "Enter OTP" : "Top-up complete"}
            </h2>
          </div>
          <button className={styles.modalCloseBtn} type="button" onClick={onClose} aria-label="Close top-up sheet">✕</button>
        </div>

        {stage === "amount" && (
          <form className={styles.modalForm} onSubmit={onAmountSubmit}>
            <div className={styles.withdrawQuickChipsGrid}>
              {QUICK_AMOUNTS.map((amt) => (
                <button
                  key={amt}
                  type="button"
                  className={styles.withdrawQuickChip}
                  onClick={() => onAmountChange(String(amt))}
                >
                  ₦{amt.toLocaleString()}
                </button>
              ))}
            </div>

            <div className={styles.modalInputGroup}>
              <label htmlFor="deposit-amount-input" className={styles.modalInputLabel}>
                Deposit Amount (min ₦100)
              </label>
              <div className={styles.modalInputWrapper}>
                <span className={styles.modalCurrencyPrefix}>₦</span>
                <input
                  id="deposit-amount-input"
                  autoFocus
                  className={styles.modalInputField}
                  inputMode="numeric"
                  value={amount}
                  onChange={(event) => onAmountChange(event.target.value.replace(/\D/g, ""))}
                  placeholder="1,000"
                />
              </div>
            </div>

            <div className={styles.destinationHint}>
              <span>Payment source:</span>
              <strong>OPay Instant Transfer</strong>
            </div>

            {error && <p className={styles.fieldError} role="alert">{error}</p>}
            <button className={styles.primaryAction} type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Starting…" : "Continue"}
            </button>
          </form>
        )}

        {stage === "otp" && (
          <form className={styles.modalForm} onSubmit={onOtpSubmit}>
            <p className={styles.modalInstructions}>Enter the six-digit code sent to your registered phone.</p>
            <div className={styles.modalInputGroup}>
              <label htmlFor="deposit-otp-input" className={styles.modalInputLabel}>
                One-time password
              </label>
              <div className={styles.modalInputWrapper}>
                <input
                  id="deposit-otp-input"
                  autoFocus
                  className={styles.modalInputField}
                  inputMode="numeric"
                  maxLength={6}
                  value={otp}
                  onChange={(event) => onOtpChange(event.target.value.replace(/\D/g, ""))}
                  placeholder="000000"
                />
              </div>
            </div>
            {error && <p className={styles.fieldError} role="alert">{error}</p>}
            <button className={styles.primaryAction} type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Verifying…" : "Verify and top up"}
            </button>
          </form>
        )}

        {stage === "done" && (
          <div className={styles.depositDone}>
            <span className={styles.doneCheckIcon} aria-hidden="true">✓</span>
            <p className={styles.doneMessage}>{formatKobo(Number(amount) * 100)} was added to Play Balance. Your BlackRed game is still configured.</p>
            <button className={styles.primaryAction} type="button" onClick={onClose}>Back to game</button>
          </div>
        )}
      </aside>
    </div>
  );
}

function WithdrawSheet({
  stage,
  amount,
  error,
  availableKobo,
  isSubmitting,
  reference,
  onAmountChange,
  onAmountSubmit,
  onOtpSubmit,
  onClose,
}: {
  stage: WithdrawStage;
  amount: string;
  error?: string;
  availableKobo: number;
  isSubmitting: boolean;
  reference?: string;
  onAmountChange: (value: string) => void;
  onAmountSubmit: (event: FormEvent) => void;
  onOtpSubmit: (event: FormEvent) => void;
  onClose: () => void;
}) {
  const sheetRef = useRef<HTMLElement>(null);
  useEffect(() => {
    const initialTarget = sheetRef.current?.querySelector<HTMLElement>("input") ?? sheetRef.current?.querySelector<HTMLElement>("button");
    initialTarget?.focus({ preventScroll: true });
  }, []);

  const QUICK_WITHDRAWS = [1000, 5000, 10000, 50000];

  return (
    <div className={styles.sheetBackdrop} onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
      <aside
        ref={sheetRef}
        className={styles.depositSheet}
        role="dialog"
        aria-modal="true"
        aria-labelledby="withdraw-title"
        tabIndex={-1}
        onKeyDown={(event) => containDialogFocus(event, sheetRef.current, onClose)}
      >
        <div className={styles.sheetHeading}>
          <div>
            <span className={styles.modalEyebrow}>Winnings Balance</span>
            <h2 id="withdraw-title" className={styles.modalHeadingTitle}>
              {stage === "amount" ? "Withdraw Funds" : stage === "otp" ? "Confirm Payout" : "Withdrawal submitted"}
            </h2>
          </div>
          <button className={styles.modalCloseBtn} type="button" onClick={onClose} aria-label="Close withdrawal sheet">✕</button>
        </div>

        {stage === "amount" && (
          <form className={styles.modalForm} onSubmit={onAmountSubmit}>
            <div className={styles.withdrawAvailableNotice}>
              <span>Available to withdraw:</span>
              <strong className={styles.goldWinAmount}>{formatKobo(availableKobo)}</strong>
            </div>

            <div className={styles.withdrawQuickChipsGrid}>
              {QUICK_WITHDRAWS.map((amt) => (
                <button
                  key={amt}
                  type="button"
                  className={styles.withdrawQuickChip}
                  onClick={() => onAmountChange(String(amt))}
                >
                  ₦{amt.toLocaleString()}
                </button>
              ))}
              <button
                type="button"
                className={styles.withdrawQuickChip}
                onClick={() => onAmountChange(String(Math.floor(availableKobo / 100)))}
              >
                Max All
              </button>
            </div>

            <div className={styles.modalInputGroup}>
              <label htmlFor="withdraw-amount-input" className={styles.modalInputLabel}>
                Amount (min ₦500)
              </label>
              <div className={styles.modalInputWrapper}>
                <span className={styles.modalCurrencyPrefix}>₦</span>
                <input
                  id="withdraw-amount-input"
                  autoFocus
                  className={styles.modalInputField}
                  inputMode="numeric"
                  value={amount}
                  onChange={(event) => onAmountChange(event.target.value.replace(/\D/g, ""))}
                  placeholder="5,000"
                />
              </div>
            </div>

            <div className={styles.destinationHint}>
              <span>Payout destination:</span>
              <strong>OPay · 0803***812 (Verified)</strong>
            </div>

            {error && <p className={styles.fieldError} role="alert">{error}</p>}
            <button className={styles.primaryAction} type="submit" disabled={availableKobo < 50000}>
              {availableKobo < 50000 ? "Minimum ₦500 required" : "Continue to review"}
            </button>
          </form>
        )}

        {stage === "otp" && (
          <form className={styles.modalForm} onSubmit={onOtpSubmit}>
            <p className={styles.modalInstructions}>
              Release ₦{Number(amount).toLocaleString()} to your verified OPay account. This can't be undone once confirmed.
            </p>
            {error && <p className={styles.fieldError} role="alert">{error}</p>}
            <button className={styles.primaryAction} type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Confirming…" : "Confirm withdrawal"}
            </button>
          </form>
        )}

        {stage === "done" && (
          <div className={styles.depositDone}>
            <span className={styles.doneCheckIcon} aria-hidden="true">✓</span>
            <p className={styles.doneMessage}>
              {formatKobo(Number(amount) * 100)} payout is on its way to your verified OPay account.
              {reference && <> Payout reference: <strong>{reference}</strong>.</>}
            </p>
            <button className={styles.primaryAction} type="button" onClick={onClose}>Back to game</button>
          </div>
        )}
      </aside>
    </div>
  );
}

function RealityCheck({
  rounds,
  totalStakedKobo,
  netPositionKobo,
  seconds,
  onContinue,
}: {
  rounds: number;
  totalStakedKobo: number;
  netPositionKobo: number;
  seconds: number;
  onContinue: () => void;
}) {
  const sheetRef = useRef<HTMLElement>(null);
  const headingRef = useRef<HTMLHeadingElement>(null);
  useEffect(() => { headingRef.current?.focus({ preventScroll: true }); }, []);
  return (
    <div className={styles.sheetBackdrop}>
      <aside
        ref={sheetRef}
        className={styles.realitySheet}
        role="dialog"
        aria-modal="true"
        aria-labelledby="reality-title"
        tabIndex={-1}
        onKeyDown={(event) => containDialogFocus(event, sheetRef.current, onContinue)}
      >
        <div className={styles.realityHeader}>
          <div className={styles.realityBadgeWrapper}>
            <span className={styles.realityShieldIcon}>🛡️</span>
            <span className={styles.realityBadge}>Responsible Play</span>
          </div>
          <span className={styles.realityLiveTimer}>⏱ {formatDuration(seconds)}</span>
        </div>

        <div className={styles.realityTitleBlock}>
          <h2 id="reality-title" ref={headingRef} tabIndex={-1} className={styles.realityTitle}>
            Session Reality Check
          </h2>
          <p className={styles.realitySubtitle}>
            You have been playing for <strong>{formatDuration(seconds)}</strong>. Here is your current session summary:
          </p>
        </div>

        <div className={styles.realityMetricsGrid}>
          <div className={styles.realityMetricCard}>
            <span className={styles.realityMetricLabel}>Rounds</span>
            <strong className={styles.realityMetricValue}>{rounds}</strong>
          </div>
          <div className={styles.realityMetricCard}>
            <span className={styles.realityMetricLabel}>Total Staked</span>
            <strong className={styles.realityMetricValue}>{formatKobo(totalStakedKobo)}</strong>
          </div>
          <div className={styles.realityMetricCard}>
            <span className={styles.realityMetricLabel}>Net Position</span>
            <strong className={netPositionKobo >= 0 ? styles.realityNetPositive : styles.realityNetNegative}>
              {netPositionKobo < 0 ? "−" : "+"}{formatKobo(Math.abs(netPositionKobo))}
            </strong>
          </div>
        </div>

        <div className={styles.realityActionsRow}>
          <button className={styles.primaryAction} type="button" onClick={onContinue}>
            Continue Playing
          </button>
          <a className={styles.secondaryAction} href="/games">
            End Session & Exit
          </a>
        </div>

        <a className={styles.realityHelpLink} href="/responsible-gambling">
          Need a break? Set player limits or self-exclude →
        </a>
      </aside>
    </div>
  );
}

function SystemState({ title, message, loading = false }: { title: string; message: string; loading?: boolean }) {
  return (
    <main className={styles.systemState} data-theme="dark">
      <div className={styles.systemStateCard}>
        {loading ? (
          <div className={styles.systemStateLoaderWrapper}>
            <div className={styles.systemStateNeonSpinner}>
              <div className={styles.spinnerCoreCards}>
                <span className={styles.spinnerCardSuitRed}>♥</span>
                <span className={styles.spinnerCardSuitBlack}>♠</span>
              </div>
            </div>
            <div className={styles.loadingShimmerTrack}>
              <div className={styles.loadingShimmerBar} />
            </div>
          </div>
        ) : (
          <div className={styles.systemErrorIconWrapper}>
            <span className={styles.systemErrorIcon} aria-hidden="true">⚠️</span>
          </div>
        )}

        <h1 className={styles.systemStateTitle}>{title}</h1>
        <p className={styles.systemStateMessage}>{message}</p>

        {!loading && (
          <div className={styles.systemStateRecoveryBox}>
            <p className={styles.systemAvailability}>Your account, activity and withdrawals remain available.</p>
            <nav className={styles.systemActions} aria-label="Available account actions">
              <a className={styles.systemActionBtnPrimary} href="/games">Back to games</a>
              <a className={styles.systemActionBtn} href="/wallet">Open Wallet</a>
              <a className={styles.systemActionBtn} href="/activity">View Activity</a>
            </nav>
          </div>
        )}
      </div>
    </main>
  );
}

function formatDuration(seconds: number) {
  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;
  return `${minutes}:${String(remainder).padStart(2, "0")}`;
}
