"use client";

import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent, type RefObject } from "react";
import { formatKobo, parseNairaInputToKobo } from "@/lib/money";
import { sounds } from "../BlackRedPlayModal/soundEffects";
import { HeritageArenaModal } from "./HeritageArenaModal";
import { OpayDirectCheckoutModal } from "@/components/wallet/OpayDirectCheckoutModal";
import { MonarchSilhouetteWing } from "./MonarchSilhouetteWing";
import { CommunitySidebarWing } from "./CommunitySidebarWing";
import {
  TRIBE_ATTIRE_LORE,
  RECENT_ACTIVITY_MOCK,
  HALL_OF_CHAMPIONS,
  type RecentActivityItem,
  type ChampionItem,
} from "./heritageCommunityData";
import {
  HeritageGatewayError,
  formatProbability,
  getLeaderTitle,
  mockHeritageGateway,
  type HeritageBoardPosition,
  type HeritageGateway,
  type HeritageLeader,
  type HeritagePrizeTier,
  type HeritageSettlement,
  type HeritageSnapshot,
} from "@/mocks/heritage";
import styles from "./HeritageGameFlow.module.css";

const STAKE_PRESETS_KOBO = [10_000, 50_000, 100_000, 500_000] as const;

type GamePhase = "configuring" | "confirming" | "resolving" | "settled";
type BannerPhase = "idle" | "flash" | "settled";

interface ConfettiPiece {
  id: number;
  left: number;
  color: string;
  delay: number;
  duration: number;
}

const ERROR_COPY: Record<string, string> = {
  INSUFFICIENT_PLAY_BALANCE: "Your stake exceeds your available balance. Please top up your wallet or lower the stake.",
  INVALID_SELECTION: "Please select exactly five distinct positions on the board.",
  LIMIT_REACHED: "You have reached your daily play limit. You can review or adjust your limits anytime in Account Settings.",
  PLAY_BLOCKED: "Player protection currently blocks new games (such as an active cool-off or exclusion). Withdrawals remain available.",
  GAME_UNAVAILABLE: "Heritage is temporarily undergoing maintenance. Please check back shortly.",
};

export interface HeritageGameFlowProps {
  gateway?: HeritageGateway;
  initialSnapshot?: HeritageSnapshot;
}

export function HeritageGameFlow({ gateway = mockHeritageGateway }: HeritageGameFlowProps) {
  const [snapshot, setSnapshot] = useState<HeritageSnapshot>();
  const [loadError, setLoadError] = useState(false);
  const [phase, setPhase] = useState<GamePhase>("configuring");
  const [bannerPhase, setBannerPhase] = useState<BannerPhase>("idle");
  const [traditionId, setTraditionId] = useState("");
  const [leader, setLeader] = useState<HeritageLeader | "">("");
  const [selectedPositions, setSelectedPositions] = useState<number[]>([]);
  const [stakeInput, setStakeInput] = useState("1000");
  const [selectionMessage, setSelectionMessage] = useState("");
  const [playError, setPlayError] = useState("");
  const [settlement, setSettlement] = useState<HeritageSettlement>();
  const [visibleCount, setVisibleCount] = useState(0);
  const [quickPicking, setQuickPicking] = useState(false);
  const [isMuted, setIsMuted] = useState(false);
  const [isArenaModalOpen, setIsArenaModalOpen] = useState(false);
  const [paymentOption, setPaymentOption] = useState<"wallet" | "opay">("wallet");
  const [opayCheckoutOpen, setOpayCheckoutOpen] = useState(false);
  const [confettiItems, setConfettiItems] = useState<ConfettiPiece[]>([]);
  const [recentActivity, setRecentActivity] = useState<RecentActivityItem[]>(RECENT_ACTIVITY_MOCK);
  const [hallOfChampions, setHallOfChampions] = useState<ChampionItem[]>(HALL_OF_CHAMPIONS);
  const confirmDialogId = useId();
  const confirmTitleId = useId();
  const confirmPopoverRef = useRef<HTMLDivElement>(null);
  const confirmYesRef = useRef<HTMLButtonElement>(null);
  const playButtonRef = useRef<HTMLButtonElement>(null);
  const settlementHeadingRef = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    let active = true;
    gateway.load().then((data) => {
      if (active) {
        setSnapshot(data);
        if (data.traditions.length > 0) {
          setTraditionId((prev) => prev || data.traditions[0].id);
        }
        setLeader((prev) => prev || "king");
      }
    }).catch(() => {
      if (active) setLoadError(true);
    });
    return () => { active = false; };
  }, [gateway]);

  useEffect(() => {
    if (!settlement || phase !== "resolving") return;
    const reducedMotion = typeof window !== "undefined" && (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false);
    if (reducedMotion) {
      setVisibleCount(9);
      setBannerPhase("settled");
      setPhase("settled");
      return;
    }

    const timer = window.setInterval(() => {
      setVisibleCount((current) => {
        const next = Math.min(9, current + 1);
        const positionResult = settlement.board[next - 1];
        if (positionResult) {
          const isHit = positionResult.selected && positionResult.winning;
          sounds.playFlip(isHit);
        }

        if (next === 9) {
          window.clearInterval(timer);
          const won = settlement.tier !== "loss" || settlement.netKobo > 0;
          setBannerPhase("flash");
          if (settlement.matchCount === 5) {
            sounds.playWinFanfare();
            const greenPalette = ["#22c55e", "#86efac", "#ffd88a", "#ffffff", "#15803d"];
            const particles: ConfettiPiece[] = Array.from({ length: 48 }).map((_, i) => ({
              id: i,
              left: Math.random() * 96 + 2,
              color: greenPalette[i % greenPalette.length],
              delay: Math.random() * 0.4,
              duration: 1.3 + Math.random() * 1.3,
            }));
            setConfettiItems(particles);
          } else if (settlement.matchCount === 4) {
            sounds.playWinFanfare();
            const yellowPalette = ["#fef08a", "#facc15", "#ffffff", "#eab308", "#fde047"];
            const particles: ConfettiPiece[] = Array.from({ length: 32 }).map((_, i) => ({
              id: i,
              left: Math.random() * 96 + 2,
              color: yellowPalette[i % yellowPalette.length],
              delay: Math.random() * 0.3,
              duration: 1.1 + Math.random() * 0.9,
            }));
            setConfettiItems(particles);
          } else {
            sounds.playFlip(false);
          }

          // Dynamically update recent activity and champions list for community hub
          if (settlement.matchCount === 5) {
            const newAct: RecentActivityItem = {
              id: `user-act-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`,
              timeAgo: "Just now",
              player: "You",
              tribe: snapshot?.traditions.find((t) => t.id === traditionId)?.name ?? "Royal",
              action: "Matched 5/5 Jackpot!",
              amount: `+${formatKobo(settlement.netKobo)}`,
              badge: "win",
            };
            setRecentActivity((prev) => [newAct, ...prev.slice(0, 4)]);
          } else if (settlement.matchCount === 4) {
            const newAct: RecentActivityItem = {
              id: `user-act-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`,
              timeAgo: "Just now",
              player: "You",
              tribe: snapshot?.traditions.find((t) => t.id === traditionId)?.name ?? "Royal",
              action: "Matched 4/5 (You tried)",
              amount: `+${formatKobo(settlement.netKobo)}`,
              badge: "match",
            };
            setRecentActivity((prev) => [newAct, ...prev.slice(0, 4)]);
          }

          if (settlement.matchCount === 5 && settlement.netKobo > 0) {
            const newChamp: ChampionItem = {
              id: `user-champ-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`,
              rank: 1,
              player: "You",
              tribe: snapshot?.traditions.find((t) => t.id === traditionId)?.name ?? "Royal",
              prize: formatKobo(settlement.netKobo),
              multiplier: "25x",
              date: "Just now",
            };
            setHallOfChampions((prev) => [
              newChamp,
              ...prev.map((c, idx) => ({ ...c, rank: idx + 2 })).slice(0, 3),
            ]);
          }

          window.setTimeout(() => {
            setBannerPhase("settled");
            setPhase("settled");
          }, 1800);
        }
        return next;
      });
    }, 520);
    return () => window.clearInterval(timer);
  }, [phase, settlement, traditionId, snapshot]);

  useEffect(() => {
    if (phase === "settled") settlementHeadingRef.current?.focus({ preventScroll: true });
  }, [phase]);

  useEffect(() => {
    if (phase === "confirming") confirmYesRef.current?.focus({ preventScroll: true });
  }, [phase]);

  const stakeKobo = parseNairaInputToKobo(stakeInput);
  const selectedTradition = snapshot?.traditions.find((tradition) => tradition.id === traditionId);
  const selectionReady = selectedPositions.length === 5;
  const stakeReady = Boolean(snapshot && stakeKobo !== null && stakeKobo >= snapshot.minStakeKobo && stakeKobo <= snapshot.maxStakeKobo);
  const ready = selectionReady && Boolean(traditionId) && Boolean(leader) && stakeReady;
  const controlsLocked = phase === "resolving" || phase === "settled";
  const isLoss = settlement?.tier === "loss";
  const revealedBoard = settlement?.board ?? [];
  const revealedMatches = revealedBoard.slice(0, visibleCount).filter((position) => position.selected && position.winning).length;
  const revealedSelected = revealedBoard.slice(0, visibleCount).filter((position) => position.selected).length;
  const lastRevealed = visibleCount > 0 ? revealedBoard[visibleCount - 1] : undefined;
  const dressedItems = revealedBoard.slice(0, visibleCount).filter((position) => position.selected && position.winning);
  const latestDressedItem = dressedItems[dressedItems.length - 1]?.item;

  const equippedSlotsSet = useMemo(() => {
    const set = new Set<string>();
    if (settlement) {
      settlement.board.slice(0, visibleCount).forEach((pos) => {
        if (pos.selected && pos.winning) {
          if (pos.item.slot === "wrist") set.add("hand");
          else if (pos.item.slot === "waist") set.add("torso");
          else set.add(pos.item.slot);
        }
      });
    }
    return set;
  }, [settlement, visibleCount]);

  const jackpotTier = snapshot?.prizeTiers.find((tier) => tier.multiplier === 25 || tier.outcomeType === "cash" && tier.multiplier && tier.multiplier > 1);
  const jackpotGrossKobo = stakeKobo && jackpotTier?.multiplier ? stakeKobo * jackpotTier.multiplier : 0;
  const jackpotTaxKobo = snapshot && stakeKobo ? Math.round(Math.max(0, jackpotGrossKobo - stakeKobo) * snapshot.taxRateBasisPoints / 10_000) : 0;

  const currentSession = useMemo(() => {
    if (!snapshot) return undefined;
    if (!settlement) return snapshot.session;
    return {
      ...snapshot.session,
      rounds: snapshot.session.rounds + 1,
      totalStakedKobo: snapshot.session.totalStakedKobo + settlement.stakeKobo,
      totalWonKobo: snapshot.session.totalWonKobo + settlement.netKobo,
      netPositionKobo: snapshot.session.netPositionKobo + settlement.netKobo - settlement.stakeKobo,
    };
  }, [settlement, snapshot]);

  if (loadError) {
    return <SystemState title="Heritage could not load" message="The game is unavailable right now. No ticket was created and no money moved." />;
  }
  if (!snapshot) {
    return <SystemState title="Loading Heritage" message="Checking the prize table, balances and play permissions." loading />;
  }

  function handleToggleMute() {
    const muted = sounds.toggleMute();
    setIsMuted(muted);
  }

  function togglePosition(position: number) {
    if (controlsLocked) return;
    setPlayError("");
    setSelectedPositions((current) => {
      if (current.includes(position)) {
        setSelectionMessage("");
        sounds.playToggle();
        return current.filter((item) => item !== position);
      }
      if (current.length === 5) {
        setSelectionMessage("Five positions are already selected. Remove one before choosing another.");
        return current;
      }
      setSelectionMessage("");
      sounds.playToggle();
      return [...current, position].sort((a, b) => a - b);
    });
  }

  async function quickPick() {
    setQuickPicking(true);
    setPlayError("");
    try {
      setSelectedPositions(await gateway.quickPick());
      sounds.playShuffle();
      setSelectionMessage("Five positions selected by Quick Pick. Your odds are unchanged.");
    } catch {
      setPlayError("Quick Pick is unavailable. You can still select five positions yourself.");
    } finally {
      setQuickPicking(false);
    }
  }

  async function confirmPlay() {
    if (!ready || !stakeKobo || !leader) return;
    setPhase("resolving");
    setBannerPhase("idle");
    setPlayError("");
    setVisibleCount(0);
    setConfettiItems([]);
    sounds.playShuffle();
    try {
      const result = await gateway.placeTicket({ selectedPositions, traditionId, leader, stakeKobo });
      setSettlement(result);
      setIsArenaModalOpen(true);
      const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
      if (reducedMotion) {
        setVisibleCount(9);
        setBannerPhase("settled");
        setPhase("settled");
      }
    } catch (error) {
      const friendlyMessage =
        error instanceof HeritageGatewayError
          ? (error.message && error.message !== error.code
              ? error.message
              : ERROR_COPY[error.code] ?? ERROR_COPY.GAME_UNAVAILABLE)
          : (error instanceof Error && error.message && !error.message.includes("HERITAGE_REQUEST_FAILED")
              ? error.message
              : ERROR_COPY.GAME_UNAVAILABLE);
      setPlayError(friendlyMessage);
      setPhase("configuring");
    }
  }

  function startNewRound() {
    setSelectedPositions([]);
    setSelectionMessage("");
    setPlayError("");
    setSettlement(undefined);
    setVisibleCount(0);
    setBannerPhase("idle");
    setConfettiItems([]);
    setPhase("configuring");
  }

  function closeConfirmation() {
    setPhase("configuring");
    window.setTimeout(() => playButtonRef.current?.focus({ preventScroll: true }), 0);
  }

  function handleConfirmationKeyDown(event: ReactKeyboardEvent<HTMLDivElement>) {
    if (event.key === "Escape") {
      event.preventDefault();
      closeConfirmation();
      return;
    }
    if (event.key !== "Tab") return;

    const buttons = Array.from(confirmPopoverRef.current?.querySelectorAll<HTMLButtonElement>("button") ?? []);
    if (buttons.length === 0) return;
    const first = buttons[0];
    const last = buttons[buttons.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  return (
    <div className={styles.game}>
      <a className="skip-link" href="#heritage-board">Skip to Heritage board</a>

      {/* Full-Screen Dramatic Heritage Gold & Velvet Banner (YOU WIN / YOU TRIED / MISSED) */}
      {bannerPhase === "flash" && (
        <div className={`${styles.flashOverlay} ${settlement?.matchCount === 5 ? styles.flashWin : settlement?.matchCount === 4 ? styles.flashTried : styles.flashLoss}`} aria-live="assertive" role="alert">
          <div className={styles.flashInner}>
            <div className={styles.flashEmblem} aria-hidden="true">
              {settlement?.matchCount === 5 ? "👑" : "🛡️"}
            </div>
            <h1 className={`${styles.flashBannerText} ${settlement?.matchCount === 5 ? styles.flashBannerTextWin : settlement?.matchCount === 4 ? styles.flashBannerTextTried : styles.flashBannerTextLoss}`}>
              {settlement?.matchCount === 5 ? "YOU WIN!!!" : settlement?.matchCount === 4 ? "YOU TRIED" : "MISSED"}
            </h1>
            <p className={styles.flashSubtitle}>
              {settlement?.matchCount === 5
                ? `${formatKobo(settlement.netKobo)} Net Prize · 5 of 5 Regalia Matched`
                : settlement?.matchCount === 4
                ? `Half stake returned (${formatKobo(settlement.netKobo)}) · 4 of 5 matched`
                : `${settlement?.matchCount ?? 0} of 5 matched · Round settled`}
            </p>
          </div>
          {settlement && settlement.matchCount >= 4 && confettiItems.length > 0 && (
            <div className={styles.confettiContainer} aria-hidden="true">
              {confettiItems.map((item) => (
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

      <header className={styles.topbar}>
        <a className={styles.backBtn} href="/games" aria-label="Exit game and return to dashboard">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style={{ marginRight: '2px' }}>
            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"/>
          </svg>
          <span>Exit Game</span>
        </a>
        <div className={styles.topbarDivider}></div>
        <a className={styles.brand} href="/games" aria-label="Heritage games home">
          <span className={styles.brandCrest} aria-hidden="true">👑</span>
          <div className={styles.brandTitles}>
            <span>Instant win · 5/90 draw</span>
            <strong>Heritage RPG</strong>
          </div>
        </a>
        <div className={styles.traditionSummary}>
          <span className={styles.royalGlyph} aria-hidden="true">H</span>
          <strong>{selectedTradition ? `${selectedTradition.name} · ${getLeaderTitle(selectedTradition, leader)}` : "Choose your royal"}</strong>
        </div>
        <div className={styles.prizeStrip} aria-label="Heritage prize ladder">
          {snapshot.prizeTiers.map((tier) => (
            <span key={tier.id} data-tier={tier.id}>
              <b>{tier.matches}</b>{" "}
              {tier.multiplier === 25
                ? "25× jackpot"
                : tier.multiplier === 0.5
                ? "0.5× (half stake back)"
                : tier.outcomeType === "draw_entry"
                ? "5/90 draw"
                : "No prize"}
            </span>
          ))}
        </div>
        <div className={styles.balances} aria-label="Account balances">
          <div className={`${styles.rpgCapsule} ${styles.goldCapsule}`} aria-label={`Play Balance: ${formatKobo(snapshot.playBalanceKobo)}`}>
            <div className={styles.capsuleMedal} aria-hidden="true">🪙</div>
            <div className={styles.capsuleData}>
              <span className={styles.capsuleLabel}>Play Balance</span>
              <strong className={styles.capsuleAmount}>{formatKobo(snapshot.playBalanceKobo)}</strong>
            </div>
            <a className={styles.capsuleQuickAdd} href="/wallet" title="Quick Top Up" aria-label="Top up Play Balance">+</a>
          </div>

          <div className={`${styles.rpgCapsule} ${styles.gemCapsule}`} aria-label={`Winnings Balance: ${formatKobo(snapshot.winningsBalanceKobo)}`}>
            <div className={`${styles.capsuleMedal} ${styles.gemMedal}`} aria-hidden="true">💎</div>
            <div className={styles.capsuleData}>
              <span className={styles.capsuleLabel}>Winnings Balance</span>
              <strong className={styles.capsuleAmount}>{formatKobo(snapshot.winningsBalanceKobo)}</strong>
            </div>
          </div>
        </div>
        <div className={styles.topActions}>
          <button
            type="button"
            className={styles.soundBtn}
            onClick={handleToggleMute}
            title={isMuted ? "Unmute Sound" : "Mute Sound"}
            aria-label={isMuted ? "Unmute sound effects" : "Mute sound effects"}
          >
            {isMuted ? "🔇" : "🔊"}
          </button>
          <a className={styles.topup} href="/wallet">Top up</a>
          <span className={styles.ageMark}>18+</span>
        </div>
      </header>

      <main className={styles.workspace}>
        {/* LEFT WING: Royal Monarch Silhouette Standee */}
        <div className={styles.leftWingWrapper}>
          <MonarchSilhouetteWing
            tradition={selectedTradition}
            leader={leader || "king"}
            equippedSlots={equippedSlotsSet}
            revealedBoard={settlement?.board}
          />
        </div>

        {/* CENTER ARENA: 1x2 Board & Controls */}
        <section className={styles.playSurface} data-phase={phase} aria-labelledby="heritage-title">
          <div className={styles.intro}>
            <h1 id="heritage-title">
              {phase === "settled" && settlement
                ? `${settlement.matchCount} of 5 positions matched`
                : "Select five tiles to reveal and dress your royal"}
            </h1>
            <span className={styles.phaseLabel}>{phaseCopy(phase, settlement)}</span>
          </div>

          <div className={styles.playArenaGrid}>
            <div className={styles.arenaLeftCol}>
              <div className={styles.configureRow}>
                <label>
                  <span>Royal tradition</span>
                  <select value={traditionId} onChange={(event) => setTraditionId(event.target.value)} disabled={controlsLocked} aria-label="Royal tradition">
                    <option value="">Choose a tradition</option>
                    {snapshot.traditions.map((tradition) => <option key={tradition.id} value={tradition.id}>{tradition.name}</option>)}
                  </select>
                </label>
                <fieldset disabled={controlsLocked}>
                  <legend>Leader</legend>
                  <div className={styles.segmented}>
                    {(["king", "queen"] as const).map((option) => (
                      <button key={option} type="button" aria-pressed={leader === option} onClick={() => setLeader(option)}>
                        {option === "king" ? "King" : "Queen"}
                      </button>
                    ))}
                  </div>
                </fieldset>
              </div>

              <div className={styles.manaChargeBar} aria-label={`Mana charge ${selectedPositions.length} of 5`}>
                <span className={styles.manaLabel}>⚡ Charge: {selectedPositions.length}/5</span>
                <div className={styles.manaCells}>
                  {Array.from({ length: 5 }, (_, i) => (
                    <div key={i} className={styles.manaCell} data-charged={i < selectedPositions.length} />
                  ))}
                </div>
              </div>

              <section className={styles.boardSection} aria-labelledby="board-title">
                <div className={styles.boardHeading}>
                  <div>
                    <h2 id="board-title">{phase === "settled" ? "Complete board result" : "Choose five positions"}</h2>
                    <p>{phase === "settled" ? "Every pick and winning position is shown below." : "Tiles are face down until your ticket settles."}</p>
                  </div>
                  <div className={styles.selectionCount} aria-live="polite">
                    <strong>{phase === "settled" && settlement ? `${settlement.matchCount} of 5` : `${selectedPositions.length} of 5`}</strong>
                    <span>{phase === "settled" ? "matched" : phase === "resolving" ? `${revealedMatches} matched · ${Math.max(0, 5 - revealedSelected)} picks left` : "selected"}</span>
                  </div>
                </div>

                <ol className={styles.board} id="heritage-board" aria-label={phase === "settled" ? "Complete nine-position Heritage result" : "Nine Heritage board positions"}>
                  {Array.from({ length: 9 }, (_, index) => {
                    const position = index + 1;
                    const result = settlement?.board[index];
                    const revealed = Boolean(result && index < visibleCount);
                    return (
                      <li key={position}>
                        <button
                          className={styles.tile}
                          type="button"
                          data-selected={selectedPositions.includes(position)}
                          data-revealed={revealed}
                          data-result={revealed && result ? resultState(result) : undefined}
                          aria-pressed={!controlsLocked ? selectedPositions.includes(position) : undefined}
                          aria-label={tileLabel(position, selectedPositions.includes(position), revealed ? result : undefined)}
                          onClick={() => togglePosition(position)}
                          disabled={phase === "resolving"}
                          aria-disabled={controlsLocked}
                        >
                          <span className={styles.tileInner}>
                            <span className={styles.tileBack}>
                              <span>{position}</span>
                              <strong>{selectedPositions.includes(position) ? "Selected" : "Choose"}</strong>
                            </span>
                            <span className={styles.tileFace} aria-hidden={!revealed}>
                              {result && <TileResult result={result} />}
                            </span>
                          </span>
                        </button>
                      </li>
                    );
                  })}
                </ol>

                <div className={styles.boardFoot}>
                  <p className={selectionMessage && phase !== "settled" ? styles.selectionMessage : undefined}>{phase === "settled" ? "Your five chosen positions changed placement only, never the predetermined outcome." : selectionMessage || "Your choice changes where items appear, never the predetermined outcome."}</p>
                  {!controlsLocked && <button className={styles.quickPick} type="button" onClick={quickPick} disabled={quickPicking}>{quickPicking ? "Choosing…" : "Quick Pick five"}</button>}
                </div>

                {phase === "resolving" && (
                  <div className={styles.revealStatus} role="status" aria-live="polite" aria-atomic="true">
                    <strong>{visibleCount === 0 ? "Ticket created. Revealing the board." : `${revealedMatches} of your five picks matched so far.`}</strong>
                    <span>{lastRevealed ? revealCopy(lastRevealed) : "Outcome was fixed before the first tile turned."}</span>
                  </div>
                )}
              </section>
            </div>

            <div className={styles.arenaRightCol}>
              <div className={styles.controlDock}>
                {!controlsLocked && (
                  <section className={styles.stakeSection} aria-labelledby="stake-title">
                    <div className={styles.sectionLabel}>
                      <h2 id="stake-title">Stake in Coins</h2>
                      <span>{formatKobo(snapshot.minStakeKobo)}–{formatKobo(snapshot.maxStakeKobo)}</span>
                    </div>
                    <div className={styles.stakePresets}>
                      {STAKE_PRESETS_KOBO.map((amount) => (
                        <button
                          key={amount}
                          className={styles.rpgCoinStakeBtn}
                          type="button"
                          aria-pressed={stakeKobo === amount}
                          onClick={() => setStakeInput(String(amount / 100))}
                        >
                          <span className={styles.coinIcon} aria-hidden="true">🪙</span>
                          <span className={styles.coinAmount}>{formatKobo(amount)}</span>
                        </button>
                      ))}
                    </div>
                    <label className={styles.stakeInput}>
                      <span className={styles.customLabel}>Custom</span>
                      <span className={styles.customCoinIcon} aria-hidden="true">🪙 ₦</span>
                      <input
                        aria-label="Stake amount"
                        inputMode="decimal"
                        value={stakeInput}
                        onChange={(event) => {
                          setStakeInput(event.target.value);
                          if (playError) setPlayError("");
                        }}
                        placeholder="Amount"
                      />
                    </label>
                    {!stakeReady && <p className={styles.fieldError}>Enter a stake from {formatKobo(snapshot.minStakeKobo)} to {formatKobo(snapshot.maxStakeKobo)}.</p>}
                  </section>
                )}

                {phase !== "resolving" && (
                  <div className={styles.actionZone}>
                    {playError && (
                      <div className={styles.playErrorBanner} role="alert">
                        <span className={styles.playErrorIcon} aria-hidden="true">⚠️</span>
                        <div className={styles.playErrorBody}>
                          <strong>Notice</strong>
                          <p>{playError}</p>
                          {(playError.toLowerCase().includes("balance") || playError.toLowerCase().includes("top up") || playError.toLowerCase().includes("funds")) && (
                            <a href="/wallet" className={styles.playErrorLink}>Top up Play Balance →</a>
                          )}
                        </div>
                        <button
                          type="button"
                          className={styles.playErrorClose}
                          onClick={() => setPlayError("")}
                          aria-label="Dismiss error notification"
                        >
                          ✕
                        </button>
                      </div>
                    )}
                    {phase === "confirming" && (
                      <div id={confirmDialogId} ref={confirmPopoverRef} className={styles.confirmPopover} role="dialog" aria-modal="false" aria-labelledby={confirmTitleId} onKeyDown={handleConfirmationKeyDown}>
                        <div className={styles.confirmHeader}>
                          <span className={styles.confirmIcon} aria-hidden="true">⚔️</span>
                          <strong id={confirmTitleId}>Confirm game</strong>
                        </div>
                        <p className={styles.confirmSubtext}>Stake <strong>{stakeKobo ? formatKobo(stakeKobo) : "—"}</strong></p>
                        <div className={styles.confirmBtnRow}>
                          <button ref={confirmYesRef} className={styles.confirmYesBtn} type="button" onClick={confirmPlay}>Yes</button>
                          <button className={styles.confirmNoBtn} type="button" onClick={closeConfirmation}>No</button>
                        </div>
                      </div>
                    )}

                    {(phase === "configuring" || phase === "confirming") && (
                      <div className={styles.paymentMethodSelector}>
                        <button
                          type="button"
                          className={`${styles.paymentMethodTab} ${paymentOption === "wallet" ? styles.paymentMethodTabActive : ""}`}
                          onClick={() => setPaymentOption("wallet")}
                        >
                          <span className={styles.paymentMethodTabTitle}>👛 Wallet</span>
                          <span className={styles.paymentMethodTabSub}>Betplus Balance</span>
                        </button>
                        <button
                          type="button"
                          className={`${styles.paymentMethodTab} ${paymentOption === "opay" ? styles.paymentMethodTabActiveOpay : ""}`}
                          onClick={() => setPaymentOption("opay")}
                        >
                          <span className={styles.paymentMethodTabTitle}>⚡ OPay Direct</span>
                          <span className={styles.paymentMethodTabSubOpay}>Debit & Auto-Payout</span>
                        </button>
                      </div>
                    )}

                    {phase === "configuring" || phase === "confirming" ? (
                      <button
                        ref={playButtonRef}
                        className={`${styles.primaryAction} ${paymentOption === "opay" ? styles.primaryActionOpay : ""}`}
                        type="button"
                        onClick={() => {
                          if (paymentOption === "opay") {
                            setOpayCheckoutOpen(true);
                          } else {
                            setPhase("confirming");
                          }
                        }}
                        disabled={!ready}
                        aria-haspopup="dialog"
                        aria-expanded={phase === "confirming"}
                        aria-controls={phase === "confirming" ? confirmDialogId : undefined}
                      >
                        {paymentOption === "opay"
                          ? `⚡ Pay with OPay · ${stakeKobo ? formatKobo(stakeKobo) : ""}`
                          : `Reveal and dress · ${stakeKobo ? formatKobo(stakeKobo) : ""}`}
                      </button>
                    ) : (
                      <div className={styles.resultActions} data-loss={isLoss}>
                        {isLoss ? <a className={styles.primaryAction} href="/games">Back to games</a> : <button className={styles.primaryAction} type="button" onClick={startNewRound}>Start another round</button>}
                        {isLoss ? <button className={styles.secondaryAction} type="button" onClick={startNewRound}>Start another round</button> : <a className={styles.secondaryAction} href="/games">Back to games</a>}
                      </div>
                    )}
                    {!ready && phase !== "settled" && <p>Select a tradition, a leader and exactly five positions.</p>}
                  </div>
                )}
              </div>

              <div className={styles.sideHeroCard}>
                <div className={styles.heroAvatarCard}>
                  <div className={styles.heroAvatarIcon} aria-hidden="true">
                    {leader === "queen" ? "👑" : "🤴"}
                  </div>
                  <div className={styles.heroAvatarInfo}>
                    <h3>{selectedTradition?.name ?? "Royal Realm"}</h3>
                    <p>{getLeaderTitle(selectedTradition, leader)} · {dressedItems.length}/5 Equipped</p>
                  </div>
                </div>

                <div className={styles.rpgEquipGrid} aria-label="Hero Equipment Slots">
                  {[
                    { id: "head", label: "Crown", icon: "👑" },
                    { id: "neck", label: "Amulet", icon: "📿" },
                    { id: "torso", label: "Armor", icon: "🛡️" },
                    { id: "hand", label: "Staff", icon: "🗡️" },
                    { id: "feet", label: "Boots", icon: "👢" },
                  ].map((slot) => {
                    const isEquipped = settlement?.board.slice(0, visibleCount).some(
                      (position) =>
                        position.selected &&
                        position.winning &&
                        (position.item.slot === slot.id ||
                          (slot.id === "hand" && position.item.slot === "wrist") ||
                          (slot.id === "torso" && position.item.slot === "waist"))
                    );
                    return (
                      <div key={slot.id} className={styles.rpgSlotCard} data-equipped={isEquipped}>
                        <span className={styles.rpgSlotIcon} aria-hidden="true">{slot.icon}</span>
                        <span className={styles.rpgSlotLabel}>{slot.label}</span>
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
          </div>

          <Metrics
            snapshot={snapshot}
            stakeKobo={stakeKobo ?? 0}
            selectedCount={selectedPositions.length}
            tradition={selectedTradition?.name ?? "Not selected"}
            leader={getLeaderTitle(selectedTradition, leader)}
            jackpotTier={jackpotTier}
            jackpotGrossKobo={jackpotGrossKobo}
            jackpotTaxKobo={jackpotTaxKobo}
            settlement={phase === "settled" ? settlement : undefined}
            settlementHeadingRef={settlementHeadingRef}
          />

          {settlement?.secondChance && phase === "settled" && <SecondChanceSummary entry={settlement.secondChance} reference={settlement.reference} />}

          {phase === "settled" && settlement && <ResultEvidence board={settlement.board} />}


          <section className={styles.rules} id="heritage-rules" aria-labelledby="rules-title">
            <h2 id="rules-title">Rules and exact odds</h2>
            <p>Every ticket has five winning positions. The outcome tier is fixed when your ticket is created; your selection changes presentation, not the tier. All five cash-prize matches must be in your selected positions.</p>
            <div className={styles.oddsTable} role="table" aria-label="Heritage prize table">
              {snapshot.prizeTiers.map((tier) => <PrizeRow key={tier.id} tier={tier} stakeKobo={stakeKobo ?? 0} />)}
            </div>
            <p>Modelled RTP 81.6%. Withholding tax is estimated at {snapshot.taxRateBasisPoints / 100}% of net winnings and shown before any credited figure.</p>
          </section>

          <p className={styles.culturalNotice}>The fixed 1–90 catalogue keeps unapproved entries withheld. Final names, context and artwork require recorded sign-off from a named cultural advisor before publication. No identifiable living monarch or existing palace regalia is depicted here.</p>
        </section>

        {/* RIGHT WING: Recent Activity, Winners Area, Attire Lore Deck */}
        <div className={styles.rightWingWrapper}>
          <CommunitySidebarWing
            selectedTradition={selectedTradition}
            recentActivity={recentActivity}
            winnersList={hallOfChampions}
          />
        </div>

        <aside className={styles.royalRail} aria-labelledby="royal-preview-title">
          <div className={styles.heroLoadoutSheet}>
            <div className={styles.heroAvatarCard}>
              <div className={styles.heroAvatarIcon} aria-hidden="true">
                {leader === "queen" ? "👑" : "🤴"}
              </div>
              <div className={styles.heroAvatarInfo}>
                <h3 id="royal-preview-title">{selectedTradition?.name ?? "Royal Realm"}</h3>
                <p>{getLeaderTitle(selectedTradition, leader)} · {dressedItems.length}/5 Equipped</p>
              </div>
            </div>

            <div className={styles.rpgEquipGrid} aria-label="Hero Equipment Slots">
              {[
                { id: "head", label: "Crown", icon: "👑" },
                { id: "neck", label: "Amulet", icon: "📿" },
                { id: "torso", label: "Armor", icon: "🛡️" },
                { id: "hand", label: "Staff", icon: "🗡️" },
                { id: "feet", label: "Boots", icon: "👢" },
              ].map((slot) => {
                const isEquipped = settlement?.board.slice(0, visibleCount).some(
                  (position) =>
                    position.selected &&
                    position.winning &&
                    (position.item.slot === slot.id ||
                      (slot.id === "hand" && position.item.slot === "wrist") ||
                      (slot.id === "torso" && position.item.slot === "waist"))
                );
                return (
                  <div key={slot.id} className={styles.rpgSlotCard} data-equipped={isEquipped}>
                    <span className={styles.rpgSlotIcon} aria-hidden="true">{slot.icon}</span>
                    <span className={styles.rpgSlotLabel}>{slot.label}</span>
                  </div>
                );
              })}
            </div>
          </div>

          <div className={styles.figure} aria-label="Abstract royal figure with five regalia slots">
            <div className={styles.figureCrown} aria-hidden="true"><i /><i /><i /></div>
            <div className={styles.figureHead} />
            <div className={styles.figureBody} />
            <div className={styles.figureArmLeft} />
            <div className={styles.figureArmRight} />
            <div className={styles.figureLegLeft} />
            <div className={styles.figureLegRight} />
            {(["head", "neck", "torso", "hand", "feet"] as const).map((slot) => {
              const filled = settlement?.board.slice(0, visibleCount).some(
                (position) =>
                  position.selected &&
                  position.winning &&
                  (position.item.slot === slot ||
                    (slot === "hand" && position.item.slot === "wrist") ||
                    (slot === "torso" && position.item.slot === "waist"))
              );
              return <span key={slot} className={styles[`slot_${slot}`]} data-filled={filled}>{slot}</span>;
            })}
          </div>
          <div className={styles.itemSpotlight}>
            <span>{latestDressedItem ? `No. ${latestDressedItem.number} · ${latestDressedItem.origin}` : "Regalia reveal"}</span>
            <strong>{latestDressedItem?.localName ?? latestDressedItem?.canonicalName ?? "Your matched pieces dress the figure"}</strong>
            <p>{latestDressedItem?.context ?? "Each revealed item includes its name, origin and one line of cultural context."}</p>
          </div>
          <div className={styles.railFacts}>
            <div><span>Selection</span><strong>{selectedPositions.length}/5 positions</strong></div>
            <div><span>Visual choice</span><strong>Does not affect odds</strong></div>
            <div><span>Deposit turnover</span><strong>{formatKobo(snapshot.depositStakedKobo)} of {formatKobo(snapshot.depositRequiredKobo)} staked</strong></div>
          </div>
          <a className={styles.responsibleLink} href="/account">Limits and responsible play</a>
        </aside>
      </main>

      {currentSession && (
        <footer className={styles.sessionStrip} aria-label="Current play session">
          <div><span>Rounds</span><strong>{currentSession.rounds}</strong></div>
          <div><span>Total staked</span><strong>{formatKobo(currentSession.totalStakedKobo)}</strong></div>
          <div><span>Total won</span><strong>{formatKobo(currentSession.totalWonKobo)}</strong></div>
          <div><span>Net position</span><strong>{signedMoney(currentSession.netPositionKobo)}</strong></div>
          <div><span>Session time</span><strong>{formatDuration(currentSession.elapsedSeconds)}</strong></div>
          <div><span>Daily stake limit</span><strong>{formatKobo(currentSession.activeLimitKobo)}</strong></div>
        </footer>
      )}

      <HeritageArenaModal
        isOpen={isArenaModalOpen}
        onClose={() => {
          setIsArenaModalOpen(false);
          setPhase("settled");
          setVisibleCount(9);
          setBannerPhase("settled");
        }}
        settlement={settlement ?? null}
        traditionName={selectedTradition?.name ?? "Royal Tradition"}
        leaderTitle={getLeaderTitle(selectedTradition, leader)}
        isMuted={isMuted}
        toggleMute={() => {
          const next = sounds.toggleMute();
          setIsMuted(next);
        }}
        onPlayAgain={() => {
          setIsArenaModalOpen(false);
          startNewRound();
        }}
        prizeTiers={snapshot?.prizeTiers ?? []}
      />

      {/* OPay Direct Checkout Modal */}
      <OpayDirectCheckoutModal
        isOpen={opayCheckoutOpen}
        onClose={() => setOpayCheckoutOpen(false)}
        stakeKobo={stakeKobo ?? 0}
        potentialWinKobo={(stakeKobo ?? 0) * 25}
        gameName="Heritage Quest"
        onPaymentSuccess={confirmPlay}
      />
    </div>
  );
}

function TileResult({ result }: { result: HeritageBoardPosition }) {
  return (
    <>
      <span className={styles.itemNumber}>{result.item.number}</span>
      <strong>{result.item.localName ?? result.item.canonicalName}</strong>
      {result.item.localName && <small>{result.item.canonicalName}</small>}
      <span className={styles.origin}>{result.item.origin}</span>
      <p>{result.item.context}</p>
      <b>{result.selected ? "Picked" : "Not picked"} · {result.winning ? "Winning" : "Not winning"}</b>
    </>
  );
}

function ResultEvidence({ board }: { board: HeritageBoardPosition[] }) {
  return (
    <section className={styles.resultEvidence} aria-labelledby="result-evidence-title">
      <div className={styles.resultEvidenceHeading}>
        <div>
          <h2 id="result-evidence-title">Board proof</h2>
          <p>Every position is shown. Unpicked winning tiles appear only as factual outcome evidence.</p>
        </div>
        <div className={styles.resultLegend} aria-label="Result state key">
          <span data-result="picked-winning">Picked · winning</span>
          <span data-result="picked-not">Picked · not winning</span>
          <span data-result="unpicked-winning">Not picked · winning</span>
          <span data-result="unpicked-not">Not picked · not winning</span>
        </div>
      </div>
      <ol className={styles.resultTranscript} aria-label="Nine revealed catalogue items">
        {board.map((position) => (
          <li key={position.position} data-result={resultState(position)} tabIndex={0}>
            <span>Position {position.position} · No. {position.item.number}</span>
            <strong>{position.item.localName ?? position.item.canonicalName}</strong>
            <small>{position.item.origin}</small>
            <p>{position.item.context}</p>
            <b>{resultStateLabel(position)}</b>
          </li>
        ))}
      </ol>
    </section>
  );
}

function Metrics({ snapshot, stakeKobo, selectedCount, tradition, leader, jackpotTier, jackpotGrossKobo, jackpotTaxKobo, settlement, settlementHeadingRef }: {
  snapshot: HeritageSnapshot;
  stakeKobo: number;
  selectedCount: number;
  tradition: string;
  leader: string;
  jackpotTier?: HeritagePrizeTier;
  jackpotGrossKobo: number;
  jackpotTaxKobo: number;
  settlement?: HeritageSettlement;
  settlementHeadingRef: RefObject<HTMLHeadingElement | null>;
}) {
  if (settlement) {
    const heading = settlement.tier === "jackpot" || settlement.matchCount === 5
      ? "Jackpot settled"
      : settlement.tier === "high" || settlement.matchCount === 4
      ? "You tried — half stake returned"
      : settlement.tier === "second-chance"
      ? "Second-chance entry earned"
      : "Round settled — no prize";
    return (
      <section className={styles.metrics} data-result={settlement.tier} aria-labelledby="settlement-title" role="status">
        <div className={styles.metricsHeading}>
          <div><span>Settlement</span><h2 id="settlement-title" ref={settlementHeadingRef} tabIndex={-1}>{heading}</h2></div>
          <strong>{settlement.matchCount} of 5 matched</strong>
        </div>
        <dl className={styles.metricGrid}>
          <div><dt>Stake</dt><dd>{formatKobo(settlement.stakeKobo)}</dd></div>
          <div><dt>Outcome odds</dt><dd>{tierOdds(snapshot.prizeTiers, settlement.tier)}</dd></div>
          <div><dt>Gross prize</dt><dd>{formatKobo(settlement.grossKobo)}</dd></div>
          <div><dt>Tax ({snapshot.taxRateBasisPoints / 100}% net winnings)</dt><dd>−{formatKobo(settlement.taxKobo)}</dd></div>
          <div className={styles.netMetric}><dt>Net credited</dt><dd>{formatKobo(settlement.netKobo)}</dd></div>
          <div><dt>Ticket reference</dt><dd>{settlement.reference}</dd></div>
        </dl>
      </section>
    );
  }

  return (
    <section className={styles.metrics} aria-labelledby="summary-title">
      <div className={styles.metricsHeading}>
        <div><span>Round summary</span><h2 id="summary-title">What this game can return</h2></div>
        <strong>{selectedCount}/5 selected</strong>
      </div>
      <dl className={styles.metricGrid}>
        <div><dt>Tradition</dt><dd>{tradition}</dd></div>
        <div><dt>Leader</dt><dd>{leader}</dd></div>
        <div><dt>Stake</dt><dd>{formatKobo(stakeKobo)}</dd></div>
        <div><dt>Jackpot odds</dt><dd>{jackpotTier ? formatProbability(jackpotTier.probabilityBasisPoints) : "—"}</dd></div>
        <div><dt>Potential gross</dt><dd>{formatKobo(jackpotGrossKobo)} · 25×</dd></div>
        <div><dt>Estimated tax</dt><dd>−{formatKobo(jackpotTaxKobo)}</dd></div>
        <div className={styles.netMetric}><dt>Potential net</dt><dd>{formatKobo(jackpotGrossKobo - jackpotTaxKobo)}</dd></div>
      </dl>
    </section>
  );
}

function SecondChanceSummary({ entry, reference }: { entry: NonNullable<HeritageSettlement["secondChance"]>; reference: string }) {
  const statusCopy = entry.status === "lodged"
    ? `Lodged for ${formatWat(entry.drawAt)}. An SMS receipt is due by ${formatWat(entry.receiptDueAt ?? entry.drawAt)}.`
    : entry.status === "pending"
      ? "Queued with Betplus. It has not been lodged with the draw partner yet, so no partner reference exists. Play is not blocked."
      : entry.status === "delayed"
        ? "The draw partner has not acknowledged the entry. It remains queued and will move to the next eligible draw if necessary."
        : `Three eligible draws passed without a successful lodge. ${formatKobo(entry.compensationKobo ?? entry.entryStakeKobo)} was credited instead.`;
  const heading = entry.status === "lodged" ? "Entry confirmed" : entry.status === "compensated" ? "Compensation credited" : entry.status === "delayed" ? "Partner delay" : "Entry queued";
  return (
    <section className={styles.secondChance} data-status={entry.status} aria-labelledby="draw-entry-title" aria-live="polite">
      <div><span>Automatic 5/90 entry</span><h2 id="draw-entry-title">{heading}</h2></div>
      <p>{statusCopy}</p>
      {entry.rolloverReason && <p>{entry.rolloverReason}</p>}
      <p>Result SMS: {entry.resultNotification === "sent" ? "sent" : entry.resultNotification === "scheduled" ? "scheduled" : "pending"}. Betplus sends it whether the entry wins or not.</p>
      <dl>
        <div><dt>Your five numbers</dt><dd>{entry.numbers.join(" · ")}</dd></div>
        <div><dt>Entry value</dt><dd>{formatKobo(entry.entryStakeKobo)}</dd></div>
        <div><dt>Draw</dt><dd>{entry.drawName} · {formatWat(entry.drawAt)}</dd></div>
        <div><dt>Partner reference</dt><dd>{entry.partnerReference ?? "Not lodged yet"}</dd></div>
        <div><dt>Betplus ticket</dt><dd>{reference}</dd></div>
        {entry.drawsMissed > 0 && <div><dt>Eligible draws missed</dt><dd>{entry.drawsMissed}</dd></div>}
      </dl>
    </section>
  );
}

function PrizeRow({ tier, stakeKobo }: { tier: HeritagePrizeTier; stakeKobo: number }) {
  const outcome = tier.multiplier === 25
    ? `${formatKobo(stakeKobo * 25)} · 25× stake`
    : tier.multiplier === 0.5
      ? `${formatKobo(Math.round(stakeKobo * 0.5))} · 0.5× stake (half back)`
      : tier.outcomeType === "draw_entry"
        ? `${formatKobo(Math.round(stakeKobo * 0.1))} 5/90 entry`
        : "No prize";
  return (
    <div className={styles.prizeRow} role="row">
      <span role="cell"><strong>{tier.matches}</strong>{tier.label}</span>
      <span role="cell">{formatProbability(tier.probabilityBasisPoints)}</span>
      <strong role="cell">{outcome}</strong>
    </div>
  );
}

function SystemState({ title, message, loading = false }: { title: string; message: string; loading?: boolean }) {
  return <main className={styles.systemState}><span aria-hidden="true">{loading ? "…" : "!"}</span><h1>{title}</h1><p>{message}</p>{!loading && <a href="/games">Back to games</a>}</main>;
}

function tileLabel(position: number, selected: boolean, result?: HeritageBoardPosition) {
  if (!result) return `Position ${position}: ${selected ? "selected" : "not selected"}`;
  return `Position ${position}, ${result.selected ? "picked" : "not picked"}, ${result.winning ? "winning" : "not winning"}, number ${result.item.number}, ${result.item.localName ? `${result.item.localName}, ` : ""}${result.item.canonicalName}, ${result.item.origin}. ${result.item.context}`;
}

function resultState(result: HeritageBoardPosition) {
  if (result.selected && result.winning) return "picked-winning";
  if (result.selected) return "picked-not";
  if (result.winning) return "unpicked-winning";
  return "unpicked-not";
}

function resultStateLabel(result: HeritageBoardPosition) {
  return `${result.selected ? "Picked" : "Not picked"} · ${result.winning ? "Winning" : "Not winning"}`;
}

function revealCopy(result: HeritageBoardPosition) {
  return `Position ${result.position}: ${resultStateLabel(result)}. Number ${result.item.number}, ${result.item.localName ?? result.item.canonicalName}, ${result.item.origin}. ${result.item.context}`;
}

function phaseCopy(phase: GamePhase, settlement?: HeritageSettlement) {
  if (phase === "configuring") return "Configure your round";
  if (phase === "confirming") return "Ready to place";
  if (phase === "resolving") return "Board revealing";
  return settlement ? `${settlement.matchCount} of 5 matched` : "Round settled";
}

function tierOdds(tiers: HeritagePrizeTier[], tierId: HeritagePrizeTier["id"]) {
  const tier = tiers.find((item) => item.id === tierId);
  return tier ? formatProbability(tier.probabilityBasisPoints) : "—";
}

function formatWat(value: string) {
  return new Intl.DateTimeFormat("en-NG", { dateStyle: "medium", timeStyle: "short", timeZone: "Africa/Lagos" }).format(new Date(value));
}

function signedMoney(value: number) {
  return `${value < 0 ? "−" : "+"}${formatKobo(Math.abs(value))}`;
}

function formatDuration(seconds: number) {
  const minutes = Math.floor(seconds / 60);
  return `${minutes}:${String(seconds % 60).padStart(2, "0")}`;
}
