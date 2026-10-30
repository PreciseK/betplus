"use client";

import {
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
  type RefObject,
} from "react";
import Image from "next/image";
import Link from "next/link";
import { formatKobo, parseNairaInputToKobo } from "@/lib/money";
import { sounds } from "../BlackRedPlayModal/soundEffects";
import { OpayDirectCheckoutModal } from "@/components/wallet/OpayDirectCheckoutModal";
import { RegaliaArtwork } from "./RegaliaArtwork";
import { RegaliaReview } from "./RegaliaReview";
import { MONARCH_ARTWORK_MAP } from "./heritageCommunityData";
import {
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

const STAKE_PRESETS_KOBO = [20_000, 50_000, 100_000, 500_000] as const;
type GamePhase = "configuring" | "confirming" | "resolving" | "settled";
const ERROR_COPY: Record<string, string> = {
  INSUFFICIENT_PLAY_BALANCE:
    "Your stake exceeds your available balance. Lower your stake or add funds to your wallet.",
  INVALID_SELECTION: "Select exactly five different positions on the board.",
  LIMIT_REACHED:
    "You have reached your daily play limit. Review your limits in Account.",
  PLAY_BLOCKED:
    "Player protection currently blocks new games, such as an active break or exclusion. Withdrawals remain available.",
  GAME_UNAVAILABLE:
    "Heritage is unavailable right now. Your selection is saved on this screen.",
};
export interface HeritageGameFlowProps {
  gateway?: HeritageGateway;
  initialSnapshot?: HeritageSnapshot;
}

export function HeritageGameFlow({
  gateway = mockHeritageGateway,
  initialSnapshot,
}: HeritageGameFlowProps) {
  const [snapshot, setSnapshot] = useState<HeritageSnapshot | undefined>(
    initialSnapshot,
  );
  const [session, setSession] = useState(initialSnapshot?.session);
  const [loadError, setLoadError] = useState(false);
  const [phase, setPhase] = useState<GamePhase>("configuring");
  const [traditionId, setTraditionId] = useState(
    initialSnapshot?.traditions[0]?.id ?? "",
  );
  const [leader, setLeader] = useState<HeritageLeader>("king");
  const [selectedPositions, setSelectedPositions] = useState<number[]>([]);
  const [stakeInput, setStakeInput] = useState("1000");
  const [selectionMessage, setSelectionMessage] = useState("");
  const [playError, setPlayError] = useState("");
  const [settlement, setSettlement] = useState<HeritageSettlement>();
  const [inspectedPosition, setInspectedPosition] =
    useState<HeritageBoardPosition>();
  const [reviewTrigger, setReviewTrigger] = useState<HTMLButtonElement | null>(
    null,
  );
  const reviewOpen = useRef(false);
  const [visibleCount, setVisibleCount] = useState(0);
  const [quickPicking, setQuickPicking] = useState(false);
  const [isMuted, setIsMuted] = useState(false);
  const [paymentOption, setPaymentOption] = useState<"wallet" | "opay">(
    "wallet",
  );
  const [opayCheckoutOpen, setOpayCheckoutOpen] = useState(false);
  const [balanceStatus, setBalanceStatus] = useState<
    "ready" | "loading" | "error"
  >("ready");
  const submitting = useRef(false);
  const mounted = useRef(false);
  const rulesRef = useRef<HTMLDetailsElement>(null);
  const confirmDialogId = useId();
  const confirmTitleId = useId();
  const confirmPopoverRef = useRef<HTMLDivElement>(null);
  const confirmYesRef = useRef<HTMLButtonElement>(null);
  const playButtonRef = useRef<HTMLButtonElement>(null);
  const settlementHeadingRef = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    mounted.current = true;
    let active = true;
    gateway
      .load()
      .then((data) => {
        if (!active) return;
        setSnapshot(data);
        setSession(data.session);
        setTraditionId((current) => current || data.traditions[0]?.id || "");
      })
      .catch(() => {
        if (active) setLoadError(true);
      });
    return () => {
      active = false;
      mounted.current = false;
    };
  }, [gateway]);

  // One reveal, on the board itself. The server has already settled the ticket.
  useEffect(() => {
    if (!settlement || phase !== "resolving") return;
    let count = 0;
    const timer = window.setInterval(() => {
      count += 1;
      setVisibleCount(count);
      const tile = settlement.board[count - 1];
      sounds.playFlip(Boolean(tile?.selected && tile.winning));
      if (count >= settlement.board.length) {
        window.clearInterval(timer);
        setPhase("settled");
      }
    }, 420);
    return () => window.clearInterval(timer);
  }, [phase, settlement]);

  useEffect(() => {
    if (phase === "settled" && !reviewOpen.current) {
      settlementHeadingRef.current?.focus({ preventScroll: true });
      settlementHeadingRef.current?.scrollIntoView?.({ block: "nearest" });
    }
    if (phase === "confirming")
      confirmYesRef.current?.focus({ preventScroll: true });
  }, [phase]);

  const stakeKobo = parseNairaInputToKobo(stakeInput);
  const selectedTradition = snapshot?.traditions.find(
    (tradition) => tradition.id === traditionId,
  );
  const stakeReady = Boolean(
    snapshot &&
    stakeKobo !== null &&
    stakeKobo >= snapshot.minStakeKobo &&
    stakeKobo <= snapshot.maxStakeKobo,
  );
  const ready =
    selectedPositions.length === 5 && Boolean(selectedTradition) && stakeReady;
  const controlsLocked = phase !== "configuring" || opayCheckoutOpen;
  const jackpotTier = snapshot?.prizeTiers.find(
    (tier) => tier.outcomeType === "cash" && (tier.multiplier ?? 0) > 1,
  );
  const jackpotGrossKobo =
    stakeKobo && jackpotTier?.multiplier
      ? Math.round(stakeKobo * jackpotTier.multiplier)
      : 0;
  const jackpotTaxKobo =
    snapshot && stakeKobo
      ? Math.round(
          (Math.max(0, jackpotGrossKobo - stakeKobo) *
            snapshot.taxRateBasisPoints) /
            10_000,
        )
      : 0;
  const dressedItems = useMemo(
    () =>
      settlement?.board
        .slice(0, visibleCount)
        .filter((tile) => tile.selected && tile.winning) ?? [],
    [settlement, visibleCount],
  );
  const equippedSlots = new Set(
    dressedItems.map(({ item }) =>
      item.slot === "wrist"
        ? "hand"
        : item.slot === "waist"
          ? "torso"
          : item.slot,
    ),
  );
  const artwork = selectedTradition
    ? MONARCH_ARTWORK_MAP[selectedTradition.id.replaceAll("_", "-")]?.[leader]
    : undefined;
  const leaderTitle = selectedTradition
    ? getLeaderTitle(selectedTradition, leader)
    : leader === "king"
      ? "King"
      : "Queen";
  const balanceText = (value: number) =>
    balanceStatus === "ready"
      ? formatKobo(value)
      : balanceStatus === "loading"
        ? "Updating…"
        : "Unavailable";

  function inspectRegalia(
    position: HeritageBoardPosition,
    trigger: HTMLButtonElement,
  ) {
    setReviewTrigger(trigger);
    reviewOpen.current = true;
    setInspectedPosition(position);
  }
  function closeRegaliaReview() {
    reviewOpen.current = false;
    setInspectedPosition(undefined);
  }

  function togglePosition(position: number) {
    if (controlsLocked || quickPicking) return;
    if (selectedPositions.includes(position)) {
      setSelectedPositions(
        selectedPositions.filter((item) => item !== position),
      );
      setSelectionMessage("");
    } else if (selectedPositions.length === 5) {
      setSelectionMessage(
        "Five positions are already selected. Remove one before choosing another.",
      );
    } else {
      setSelectedPositions(
        [...selectedPositions, position].sort((a, b) => a - b),
      );
      setSelectionMessage("");
    }
    setPlayError("");
    sounds.playToggle();
  }

  async function quickPick() {
    setQuickPicking(true);
    setPlayError("");
    try {
      const positions = await gateway.quickPick();
      if (!mounted.current) return;
      setSelectedPositions(positions);
      setSelectionMessage(
        "Five positions selected by Quick Pick. Your odds are unchanged.",
      );
    } catch {
      if (mounted.current)
        setPlayError(
          "Quick Pick is unavailable. You can still select five positions yourself.",
        );
    } finally {
      if (mounted.current) setQuickPicking(false);
    }
  }

  async function refreshBalances() {
    setBalanceStatus("loading");
    try {
      const fresh = await gateway.load();
      if (!mounted.current) return;
      setSnapshot((current) =>
        current
          ? {
              ...current,
              playBalanceKobo: fresh.playBalanceKobo,
              winningsBalanceKobo: fresh.winningsBalanceKobo,
            }
          : current,
      );
      setBalanceStatus("ready");
    } catch {
      if (mounted.current) setBalanceStatus("error");
    }
  }

  async function confirmPlay() {
    if (!ready || !stakeKobo || submitting.current) return;
    submitting.current = true;
    setPhase("resolving");
    setPlayError("");
    setVisibleCount(0);
    try {
      const result = await gateway.placeTicket({
        selectedPositions,
        traditionId,
        leader,
        stakeKobo,
      });
      if (!mounted.current) return;
      setSettlement(result);
      setSession((current) =>
        current
          ? {
              ...current,
              rounds: current.rounds + 1,
              totalStakedKobo: current.totalStakedKobo + result.stakeKobo,
              totalWonKobo: current.totalWonKobo + result.netKobo,
              netPositionKobo:
                current.netPositionKobo + result.netKobo - result.stakeKobo,
            }
          : current,
      );
      if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) {
        setVisibleCount(result.board.length);
        setPhase("settled");
      }
      void refreshBalances();
    } catch (error) {
      if (!mounted.current) return;
      const code =
        error &&
        typeof error === "object" &&
        "code" in error &&
        typeof error.code === "string"
          ? error.code
          : "GAME_UNAVAILABLE";
      setPlayError(ERROR_COPY[code] ?? ERROR_COPY.GAME_UNAVAILABLE);
      setPhase("configuring");
    } finally {
      submitting.current = false;
    }
  }

  function startNewRound() {
    setSelectedPositions([]);
    setSelectionMessage("");
    setPlayError("");
    setSettlement(undefined);
    setVisibleCount(0);
    setPhase("configuring");
  }
  function closeConfirmation() {
    setPhase("configuring");
    window.setTimeout(
      () => playButtonRef.current?.focus({ preventScroll: true }),
      0,
    );
  }
  function handleConfirmationKeyDown(
    event: ReactKeyboardEvent<HTMLDivElement>,
  ) {
    if (event.key === "Escape") {
      event.preventDefault();
      closeConfirmation();
      return;
    }
    if (event.key !== "Tab") return;
    const buttons = Array.from(
      confirmPopoverRef.current?.querySelectorAll<HTMLButtonElement>(
        "button",
      ) ?? [],
    );
    const first = buttons[0],
      last = buttons[buttons.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }

  if (loadError)
    return (
      <SystemState
        title="Heritage could not load"
        message="The game is unavailable right now. No ticket was created and no money moved."
      />
    );
  if (!snapshot)
    return (
      <SystemState
        title="Loading Heritage"
        message="Loading the board, balances and prize table."
        loading
      />
    );

  return (
    <div className={styles.game}>
      <a className="skip-link" href="#heritage-board">
        Skip to Heritage board
      </a>
      <header className={styles.topbar}>
        <Link href="/games" className={styles.backBtn}>
          <span aria-hidden="true">←</span> Games
        </Link>
        <Link href="/games" className={styles.brand}>
          Heritage<span>by Betplus</span>
        </Link>
        <div className={styles.topActions}>
          <a
            href="#heritage-rules"
            onClick={() => {
              if (rulesRef.current) rulesRef.current.open = true;
            }}
          >
            Rules & odds
          </a>
          <button
            type="button"
            className={styles.soundBtn}
            onClick={() => setIsMuted(sounds.toggleMute())}
            aria-label={isMuted ? "Unmute sound effects" : "Mute sound effects"}
            aria-pressed={isMuted}
          >
            Sound {isMuted ? "off" : "on"}
          </button>
        </div>
      </header>
      <main className={styles.main}>
        <div className={styles.pageHeading}>
          <div>
            <p className={styles.eyebrow}>The royal arena · Five of nine</p>
            <h1>Heritage</h1>
            <p>Choose your royal. Pick five tiles. Reveal the regalia.</p>
          </div>
          <div className={styles.balances} aria-label="Account balances">
            <div>
              <span>Play Balance</span>
              <strong>{balanceText(snapshot.playBalanceKobo)}</strong>
            </div>
            <div>
              <span>Winnings Balance</span>
              <strong>{balanceText(snapshot.winningsBalanceKobo)}</strong>
            </div>
            <Link href="/wallet">
              Wallet <span aria-hidden="true">↗</span>
            </Link>
          </div>
        </div>
        {balanceStatus === "error" && (
          <p className={styles.balanceNotice} role="status">
            Your ticket is settled. Balances could not refresh.{" "}
            <button type="button" onClick={refreshBalances}>
              Refresh balances
            </button>
          </p>
        )}
        <div className={styles.workspace}>
          <aside
            className={styles.monarchPanel}
            aria-label="Your royal and regalia"
          >
            <div className={styles.royalCaption}>
              <p className={styles.eyebrow}>
                {selectedTradition?.name ?? "Choose a tradition"}
              </p>
              <h2>{leaderTitle}</h2>
              <span className={styles.royalIdentity}>Your royal</span>
            </div>
            <div
              className={styles.royalArtwork}
              data-equipped={dressedItems.length > 0}
            >
              <div className={styles.royalHalo} aria-hidden="true" />
              {artwork ? (
                <Image
                  src={artwork}
                  alt={`${selectedTradition?.name} ${leaderTitle}`}
                  width={300}
                  height={360}
                  priority
                  unoptimized
                />
              ) : (
                <svg viewBox="0 0 100 120" aria-hidden="true">
                  <path
                    d="m14 30 20 14 16-23 16 23 20-14-10 42H24Z"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="3"
                  />
                  <path
                    d="M25 82h50M32 96h36"
                    stroke="currentColor"
                    strokeWidth="3"
                  />
                </svg>
              )}
              <span className={styles.royalPedestal} aria-hidden="true" />
            </div>
            <div className={styles.inventoryHeading}>
              <h3>Royal regalia</h3>
              <span>{dressedItems.length}/5 matched</span>
            </div>
            <ul className={styles.equipmentGrid} aria-label="Royal equipment">
              {(
                [
                  ["head", "Headwear"],
                  ["neck", "Neckwear"],
                  ["torso", "Clothing"],
                  ["hand", "Hand & wrist"],
                  ["feet", "Footwear"],
                ] as const
              ).map(([slot, label]) => {
                const matched = dressedItems.find(
                  ({ item }) =>
                    (item.slot === "wrist"
                      ? "hand"
                      : item.slot === "waist"
                        ? "torso"
                        : item.slot) === slot,
                );
                return (
                  <li key={slot} data-equipped={equippedSlots.has(slot)}>
                    <button
                      type="button"
                      disabled={!matched}
                      aria-label={
                        matched
                          ? `Inspect matched ${label}`
                          : `Empty ${label} slot`
                      }
                      onClick={(event) => {
                        if (matched)
                          inspectRegalia(matched, event.currentTarget);
                      }}
                    >
                      <RegaliaArtwork
                        item={
                          matched?.item ?? {
                            number: 0,
                            slot,
                            canonicalName: label,
                          }
                        }
                      />
                      <span>{label}</span>
                      <b aria-hidden="true">{matched ? "✓" : "◇"}</b>
                    </button>
                  </li>
                );
              })}
            </ul>
            <p className={styles.inventoryNote}>
              {settlement
                ? "Inspect a matched piece to view its regalia."
                : "Matched regalia fill your royal’s equipment slots."}
            </p>
          </aside>

          <section
            className={styles.playSurface}
            aria-label="Heritage board and royal selection"
          >
            <div className={styles.setupHeading}>
              <span className={styles.stepNumber}>1</span>
              <h2>Choose your royal</h2>
              <span>Visual choice · same odds</span>
            </div>
            <div className={styles.configureRow}>
              <label>
                <span>Royal tradition</span>
                <select
                  value={traditionId}
                  onChange={(event) => setTraditionId(event.target.value)}
                  disabled={controlsLocked}
                >
                  <option value="">Choose a tradition</option>
                  {snapshot.traditions.map((tradition) => (
                    <option key={tradition.id} value={tradition.id}>
                      {tradition.name}
                    </option>
                  ))}
                </select>
              </label>
              <fieldset disabled={controlsLocked}>
                <legend>Royal</legend>
                <div className={styles.segmented}>
                  {(["king", "queen"] as const).map((option) => (
                    <button
                      key={option}
                      type="button"
                      aria-pressed={leader === option}
                      onClick={() => setLeader(option)}
                    >
                      {option === "king" ? "King" : "Queen"}
                    </button>
                  ))}
                </div>
              </fieldset>
            </div>
            <section
              className={styles.boardSection}
              aria-labelledby="board-title"
            >
              <div className={styles.boardHeading}>
                <div>
                  <div className={styles.setupHeading}>
                    <span className={styles.stepNumber}>2</span>
                    <h2 id="board-title">
                      {phase === "settled"
                        ? "Your revealed board"
                        : "Choose five positions"}
                    </h2>
                  </div>
                  <p>
                    {phase === "settled"
                      ? "Your picks and every winning position are shown."
                      : "Pick your own tiles or use Quick Pick."}
                  </p>
                </div>
                <span className={styles.selectionCount} aria-live="polite">
                  {selectedPositions.length}/5 <span>selected</span>
                </span>
              </div>
              <div
                className={styles.prizeChips}
                aria-label="Heritage prize ladder"
              >
                {snapshot.prizeTiers
                  .filter((tier) => tier.outcomeType !== "none")
                  .map((tier) => (
                    <span
                      key={tier.id}
                      data-kind={
                        tier.outcomeType === "draw_entry"
                          ? "draw"
                          : (tier.multiplier ?? 0) > 1
                            ? "jackpot"
                            : "return"
                      }
                    >
                      <b>
                        {tier.outcomeType === "draw_entry"
                          ? "5/90"
                          : `${tier.multiplier}×`}
                      </b>
                      {tier.outcomeType === "draw_entry"
                        ? "Draw entry"
                        : (tier.multiplier ?? 0) > 1
                          ? "Jackpot"
                          : "Stake returned"}
                    </span>
                  ))}
              </div>
              <div
                className={styles.selectionTrack}
                aria-label={`${selectedPositions.length} of 5 tiles selected`}
              >
                <span>YOUR PICKS</span>
                <div aria-hidden="true">
                  {Array.from({ length: 5 }, (_, index) => (
                    <i
                      key={index}
                      data-filled={index < selectedPositions.length}
                    />
                  ))}
                </div>
                <strong>{selectedPositions.length}/5</strong>
              </div>
              <ol
                className={styles.board}
                id="heritage-board"
                aria-label={
                  phase === "settled"
                    ? "Complete nine-position Heritage result"
                    : "Nine Heritage board positions"
                }
              >
                {Array.from({ length: 9 }, (_, index) => {
                  const position = index + 1,
                    result = settlement?.board[index];
                  const revealed = Boolean(result && index < visibleCount);
                  const selected = selectedPositions.includes(position);
                  return (
                    <li key={position}>
                      <button
                        type="button"
                        className={styles.tile}
                        data-selected={selected}
                        data-revealed={revealed}
                        data-result={
                          revealed && result ? resultState(result) : undefined
                        }
                        aria-pressed={settlement ? undefined : selected}
                        aria-label={tileLabel(
                          position,
                          selected,
                          revealed ? result : undefined,
                        )}
                        aria-disabled={
                          (controlsLocked && !revealed) || quickPicking
                        }
                        aria-haspopup={revealed ? "dialog" : undefined}
                        onClick={(event) => {
                          if (revealed && result)
                            inspectRegalia(result, event.currentTarget);
                          else togglePosition(position);
                        }}
                      >
                        {revealed && result ? (
                          <TileResult result={result} />
                        ) : (
                          <>
                            <span className={styles.tileNumber}>
                              {position.toString().padStart(2, "0")}
                            </span>
                            <span
                              className={styles.tileMark}
                              aria-hidden="true"
                            >
                              {selected ? (
                                "✓"
                              ) : (
                                <svg viewBox="0 0 80 80">
                                  <path
                                    d="m15 26 15 12 10-19 10 19 15-12-8 29H23Z"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                  />
                                  <path
                                    d="M24 62h32"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                  />
                                  <path
                                    d="m40 6 4 6-4 6-4-6Z"
                                    fill="currentColor"
                                  />
                                </svg>
                              )}
                            </span>
                            <strong>
                              {selected ? "Selected" : "Pick tile"}
                            </strong>
                          </>
                        )}
                      </button>
                    </li>
                  );
                })}
              </ol>
              <div className={styles.boardFoot}>
                <p role="status">
                  {phase === "settled"
                    ? "Select any revealed tile to inspect the regalia."
                    : selectionMessage || "All positions have the same odds."}
                </p>
                {!controlsLocked && (
                  <div className={styles.boardTools}>
                    <button
                      className={styles.quickPick}
                      type="button"
                      onClick={quickPick}
                      disabled={quickPicking}
                    >
                      {quickPicking ? "Choosing…" : "Quick Pick five"}
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedPositions([]);
                        setSelectionMessage("");
                      }}
                      disabled={!selectedPositions.length || quickPicking}
                    >
                      Clear
                    </button>
                  </div>
                )}
              </div>
              {ready && !controlsLocked && (
                <a className={styles.mobileNext} href="#heritage-stake-step">
                  Continue to stake <span aria-hidden="true">↓</span>
                </a>
              )}
              {phase === "resolving" && (
                <div
                  className={styles.revealStatus}
                  role="status"
                  aria-live="polite"
                >
                  {settlement
                    ? `Revealing the board · ${visibleCount} of 9`
                    : "Submitting your ticket…"}
                  <span>
                    {visibleCount > 0 && settlement
                      ? revealCopy(settlement.board[visibleCount - 1])
                      : "Please keep this screen open."}
                  </span>
                </div>
              )}
            </section>
          </section>
          <aside
            className={styles.roundPanel}
            aria-label="Stake and round details"
          >
            {phase === "settled" && settlement ? (
              <>
                <Metrics
                  snapshot={snapshot}
                  settlement={settlement}
                  settlementHeadingRef={settlementHeadingRef}
                />
                <div className={styles.resultActions}>
                  <Link className={styles.primaryAction} href="/games">
                    Back to games
                  </Link>
                  <button
                    className={styles.secondaryAction}
                    type="button"
                    onClick={startNewRound}
                  >
                    Start another round
                  </button>
                </div>
              </>
            ) : (
              <>
                <fieldset
                  id="heritage-stake-step"
                  className={styles.stakeSection}
                  disabled={controlsLocked}
                >
                  <legend>
                    <span className={styles.stepNumber}>3</span> Set your stake
                  </legend>
                  <p className={styles.stakeRange}>
                    {formatKobo(snapshot.minStakeKobo)}–
                    {formatKobo(snapshot.maxStakeKobo)}
                  </p>
                  <label className={styles.stakeLabel} htmlFor="heritage-stake">
                    Amount in naira
                  </label>
                  <div className={styles.stakeInput}>
                    <span aria-hidden="true">₦</span>
                    <input
                      id="heritage-stake"
                      inputMode="decimal"
                      aria-label="Stake amount"
                      aria-invalid={!stakeReady}
                      aria-describedby="heritage-stake-hint"
                      value={stakeInput}
                      onChange={(event) => {
                        setStakeInput(event.target.value);
                        setPlayError("");
                      }}
                    />
                  </div>
                  <p
                    id="heritage-stake-hint"
                    className={
                      !stakeReady ? styles.fieldError : styles.fieldHint
                    }
                  >
                    {stakeReady
                      ? "This amount is charged when you confirm."
                      : `Enter a stake from ${formatKobo(snapshot.minStakeKobo)} to ${formatKobo(snapshot.maxStakeKobo)}.`}
                  </p>
                  <div className={styles.stakePresets}>
                    {STAKE_PRESETS_KOBO.filter(
                      (amount) =>
                        amount >= snapshot.minStakeKobo &&
                        amount <= snapshot.maxStakeKobo,
                    ).map((amount) => (
                      <button
                        key={amount}
                        type="button"
                        aria-pressed={stakeKobo === amount}
                        onClick={() => setStakeInput(String(amount / 100))}
                      >
                        {formatKobo(amount)}
                      </button>
                    ))}
                  </div>
                </fieldset>
                <fieldset
                  className={styles.paymentMethods}
                  disabled={controlsLocked}
                >
                  <legend>Pay from</legend>
                  <div className={styles.segmented}>
                    {(
                      [
                        ["wallet", "Betplus wallet"],
                        ["opay", "OPay"],
                      ] as const
                    ).map(([value, label]) => (
                      <button
                        key={value}
                        type="button"
                        aria-pressed={paymentOption === value}
                        onClick={() => setPaymentOption(value)}
                      >
                        {label}
                      </button>
                    ))}
                  </div>
                </fieldset>
                <div className={styles.actionZone}>
                  {playError && (
                    <div className={styles.playErrorBanner} role="alert">
                      <p>{playError}</p>
                      {playError.includes("balance") && (
                        <Link href="/wallet">Open wallet</Link>
                      )}
                    </div>
                  )}
                  {phase === "confirming" ? (
                    <div
                      id={confirmDialogId}
                      ref={confirmPopoverRef}
                      className={styles.confirmPopover}
                      role="dialog"
                      aria-modal="false"
                      aria-labelledby={confirmTitleId}
                      onKeyDown={handleConfirmationKeyDown}
                    >
                      <h2 id={confirmTitleId}>Review your round</h2>
                      <dl>
                        <div>
                          <dt>Royal</dt>
                          <dd>
                            {selectedTradition?.name} · {leaderTitle}
                          </dd>
                        </div>
                        <div>
                          <dt>Positions</dt>
                          <dd>{selectedPositions.join(" · ")}</dd>
                        </div>
                        <div>
                          <dt>Stake</dt>
                          <dd>{formatKobo(stakeKobo ?? 0)}</dd>
                        </div>
                        <div>
                          <dt>Payment</dt>
                          <dd>
                            {paymentOption === "wallet"
                              ? "Betplus wallet"
                              : "OPay"}
                          </dd>
                        </div>
                      </dl>
                      <p>
                        Your stake will be charged. Outcomes follow the
                        published prize table.
                      </p>
                      <button
                        ref={confirmYesRef}
                        className={styles.primaryAction}
                        type="button"
                        onClick={() => {
                          if (paymentOption === "opay") {
                            setPhase("configuring");
                            setOpayCheckoutOpen(true);
                          } else void confirmPlay();
                        }}
                      >
                        {paymentOption === "opay"
                          ? "Continue to OPay"
                          : `Confirm stake · ${formatKobo(stakeKobo ?? 0)}`}
                      </button>
                      <button
                        className={styles.secondaryAction}
                        type="button"
                        onClick={closeConfirmation}
                      >
                        Edit round
                      </button>
                    </div>
                  ) : null}
                  <button
                    ref={playButtonRef}
                    hidden={phase === "confirming"}
                    className={styles.primaryAction}
                    type="button"
                    disabled={
                      !ready ||
                      phase === "resolving" ||
                      quickPicking ||
                      opayCheckoutOpen
                    }
                    onClick={() => setPhase("confirming")}
                    aria-haspopup="dialog"
                  >
                    {phase === "resolving"
                      ? "Revealing…"
                      : `Review round · ${formatKobo(stakeKobo ?? 0)}`}
                  </button>
                  {selectedPositions.length !== 5 && phase !== "resolving" && (
                    <p className={styles.fieldHint}>
                      Select {5 - selectedPositions.length} more{" "}
                      {5 - selectedPositions.length === 1
                        ? "position"
                        : "positions"}{" "}
                      to continue.
                    </p>
                  )}
                </div>
              </>
            )}
            {!settlement && (
              <details className={styles.potential}>
                <summary>Potential return</summary>
                <dl>
                  <div>
                    <dt>Potential gross</dt>
                    <dd>{formatKobo(jackpotGrossKobo)}</dd>
                  </div>
                  <div>
                    <dt>Estimated tax</dt>
                    <dd>−{formatKobo(jackpotTaxKobo)}</dd>
                  </div>
                  <div>
                    <dt>Potential net</dt>
                    <dd>{formatKobo(jackpotGrossKobo - jackpotTaxKobo)}</dd>
                  </div>
                  <div>
                    <dt>Jackpot odds</dt>
                    <dd>
                      {jackpotTier
                        ? formatProbability(jackpotTier.probabilityBasisPoints)
                        : "—"}
                    </dd>
                  </div>
                </dl>
                <p>Shown for the jackpot outcome. A prize is not guaranteed.</p>
              </details>
            )}
          </aside>
        </div>
        {phase === "settled" && settlement && (
          <div className={styles.settledContent}>
            {settlement.secondChance && (
              <SecondChanceSummary
                entry={settlement.secondChance}
                reference={settlement.reference}
              />
            )}
            <details className={styles.details}>
              <summary>Board details</summary>
              <ResultEvidence board={settlement.board} />
            </details>
          </div>
        )}
        <details ref={rulesRef} className={styles.details} id="heritage-rules">
          <summary>Rules and exact odds</summary>
          <div className={styles.rules}>
            <h2>How Heritage works</h2>
            <p>
              Select five of the nine positions, choose your stake and review
              the round before confirming. Each ticket has five winning
              positions. Your chosen positions, tradition and royal do not
              change the outcome odds.
            </p>
            <div
              className={styles.oddsTable}
              role="table"
              aria-label="Heritage prize table"
            >
              <div className={styles.prizeRow} role="row">
                <strong role="columnheader">Matches</strong>
                <strong role="columnheader">Probability</strong>
                <strong role="columnheader">Return at your stake</strong>
              </div>
              {snapshot.prizeTiers.map((tier) => (
                <PrizeRow
                  key={tier.id}
                  tier={tier}
                  stakeKobo={stakeKobo ?? 0}
                />
              ))}
            </div>
            <p>
              Prizes follow the published table. Tax applies to net winnings. A
              second-chance draw entry is shown separately when awarded.
            </p>
          </div>
        </details>
        {session && (
          <details className={styles.details}>
            <summary>Your session & limits</summary>
            <dl
              className={styles.sessionStrip}
              aria-label="Current play session"
            >
              <div>
                <dt>Rounds</dt>
                <dd>{session.rounds}</dd>
              </div>
              <div>
                <dt>Total staked</dt>
                <dd>{formatKobo(session.totalStakedKobo)}</dd>
              </div>
              <div>
                <dt>Total won</dt>
                <dd>{formatKobo(session.totalWonKobo)}</dd>
              </div>
              <div>
                <dt>Net position</dt>
                <dd>{signedMoney(session.netPositionKobo)}</dd>
              </div>
              <div>
                <dt>Session time</dt>
                <dd>{formatDuration(session.elapsedSeconds)}</dd>
              </div>
              <div>
                <dt>Daily stake limit</dt>
                <dd>{formatKobo(session.activeLimitKobo)}</dd>
              </div>
            </dl>
          </details>
        )}
        <footer className={styles.footer}>
          <p>18+ only. Play within your limits.</p>
          <Link href="/account">
            Limits and responsible play <span aria-hidden="true">↗</span>
          </Link>
        </footer>
      </main>
      {inspectedPosition && (
        <RegaliaReview
          position={inspectedPosition}
          returnFocus={reviewTrigger}
          onClose={closeRegaliaReview}
        />
      )}
      <OpayDirectCheckoutModal
        isOpen={opayCheckoutOpen}
        onClose={() => setOpayCheckoutOpen(false)}
        stakeKobo={stakeKobo ?? 0}
        potentialWinKobo={jackpotGrossKobo}
        gameName="Heritage"
        onPaymentSuccess={confirmPlay}
      />
    </div>
  );
}

function TileResult({ result }: { result: HeritageBoardPosition }) {
  return (
    <>
      <span className={styles.itemNumber}>No. {result.item.number}</span>
      <RegaliaArtwork item={result.item} className={styles.tileArtwork} />
      <strong>{result.item.localName ?? result.item.canonicalName}</strong>
      <span className={styles.origin}>{result.item.origin}</span>
      <span className={styles.inspectHint}>Inspect regalia ↗</span>
      <b>
        {result.selected ? "Picked" : "Not picked"} ·{" "}
        {result.winning ? "Winning" : "Not winning"}
      </b>
    </>
  );
}

function ResultEvidence({ board }: { board: HeritageBoardPosition[] }) {
  return (
    <section
      className={styles.resultEvidence}
      aria-labelledby="result-evidence-title"
    >
      <div className={styles.resultEvidenceHeading}>
        <div>
          <h2 id="result-evidence-title">Board proof</h2>
          <p>
            Every position is shown. Unpicked winning tiles appear only as
            factual outcome evidence.
          </p>
        </div>
        <div className={styles.resultLegend} aria-label="Result state key">
          <span data-result="picked-winning">Picked · winning</span>
          <span data-result="picked-not">Picked · not winning</span>
          <span data-result="unpicked-winning">Not picked · winning</span>
          <span data-result="unpicked-not">Not picked · not winning</span>
        </div>
      </div>
      <ol
        className={styles.resultTranscript}
        aria-label="Nine revealed catalogue items"
      >
        {board.map((position) => (
          <li
            key={position.position}
            data-result={resultState(position)}
            tabIndex={0}
          >
            <span>
              Position {position.position} · No. {position.item.number}
            </span>
            <strong>
              {position.item.localName ?? position.item.canonicalName}
            </strong>
            <small>{position.item.origin}</small>
            <p>{position.item.context}</p>
            <b>{resultStateLabel(position)}</b>
          </li>
        ))}
      </ol>
    </section>
  );
}

function Metrics({
  snapshot,
  settlement,
  settlementHeadingRef,
}: {
  snapshot: HeritageSnapshot;
  settlement: HeritageSettlement;
  settlementHeadingRef: RefObject<HTMLHeadingElement | null>;
}) {
  const heading =
    settlement.matchCount === 5
      ? "Jackpot settled"
      : settlement.matchCount === 4 && settlement.netKobo > 0
        ? "Half stake returned"
        : settlement.secondChance
          ? "Second-chance entry earned"
          : "Round settled — no prize";
  return (
    <section className={styles.metrics} aria-labelledby="settlement-title">
      <div className={styles.metricsHeading}>
        <div>
          <p className={styles.eyebrow}>Round result</p>
          <h2 id="settlement-title" ref={settlementHeadingRef} tabIndex={-1}>
            {heading}
          </h2>
        </div>
        <strong>{settlement.matchCount} of 5 matched</strong>
      </div>
      <dl className={styles.metricGrid}>
        <div>
          <dt>Stake</dt>
          <dd>{formatKobo(settlement.stakeKobo)}</dd>
        </div>
        <div>
          <dt>Outcome odds</dt>
          <dd>{tierOdds(snapshot.prizeTiers, settlement.tier)}</dd>
        </div>
        <div>
          <dt>Gross prize</dt>
          <dd>{formatKobo(settlement.grossKobo)}</dd>
        </div>
        <div>
          <dt>Tax ({snapshot.taxRateBasisPoints / 100}% net winnings)</dt>
          <dd>−{formatKobo(settlement.taxKobo)}</dd>
        </div>
        <div className={styles.netMetric}>
          <dt>Net credited</dt>
          <dd>{formatKobo(settlement.netKobo)}</dd>
        </div>
        <div>
          <dt>Ticket reference</dt>
          <dd>{settlement.reference}</dd>
        </div>
      </dl>
    </section>
  );
}

function SecondChanceSummary({
  entry,
  reference,
}: {
  entry: NonNullable<HeritageSettlement["secondChance"]>;
  reference: string;
}) {
  const statusCopy =
    entry.status === "lodged"
      ? `Lodged for ${formatWat(entry.drawAt)}. An SMS receipt is due by ${formatWat(entry.receiptDueAt ?? entry.drawAt)}.`
      : entry.status === "pending"
        ? "Queued with Betplus. It has not been lodged with the draw partner yet, so no partner reference exists. Play is not blocked."
        : entry.status === "delayed"
          ? "The draw partner has not acknowledged the entry. It remains queued and will move to the next eligible draw if necessary."
          : `Three eligible draws passed without a successful lodge. ${formatKobo(entry.compensationKobo ?? entry.entryStakeKobo)} was credited instead.`;
  const heading =
    entry.status === "lodged"
      ? "Entry confirmed"
      : entry.status === "compensated"
        ? "Compensation credited"
        : entry.status === "delayed"
          ? "Partner delay"
          : "Entry queued";
  return (
    <section
      className={styles.secondChance}
      data-status={entry.status}
      aria-labelledby="draw-entry-title"
      aria-live="polite"
    >
      <div>
        <span>Automatic 5/90 entry</span>
        <h2 id="draw-entry-title">{heading}</h2>
      </div>
      <p>{statusCopy}</p>
      {entry.rolloverReason && <p>{entry.rolloverReason}</p>}
      <p>
        Result SMS:{" "}
        {entry.resultNotification === "sent"
          ? "sent"
          : entry.resultNotification === "scheduled"
            ? "scheduled"
            : "pending"}
        . Betplus sends it whether the entry wins or not.
      </p>
      <dl>
        <div>
          <dt>Your five numbers</dt>
          <dd>
            {entry.numbers.length
              ? entry.numbers.join(" · ")
              : "Assigned when lodged"}
          </dd>
        </div>
        <div>
          <dt>Entry value</dt>
          <dd>{formatKobo(entry.entryStakeKobo)}</dd>
        </div>
        <div>
          <dt>Draw</dt>
          <dd>
            {entry.drawName} · {formatWat(entry.drawAt)}
          </dd>
        </div>
        <div>
          <dt>Partner reference</dt>
          <dd>{entry.partnerReference ?? "Not lodged yet"}</dd>
        </div>
        <div>
          <dt>Betplus ticket</dt>
          <dd>{reference}</dd>
        </div>
        {entry.drawsMissed > 0 && (
          <div>
            <dt>Eligible draws missed</dt>
            <dd>{entry.drawsMissed}</dd>
          </div>
        )}
      </dl>
    </section>
  );
}

function PrizeRow({
  tier,
  stakeKobo,
}: {
  tier: HeritagePrizeTier;
  stakeKobo: number;
}) {
  const label =
    tier.outcomeType === "cash"
      ? tier.multiplier === 0.5
        ? "Half stake back"
        : "Jackpot"
      : tier.outcomeType === "draw_entry"
        ? "5/90 draw entry"
        : "No prize";
  const matches = tier.matches.startsWith("TIER_")
    ? tier.outcomeType === "cash"
      ? tier.multiplier === 0.5
        ? "4 of 5"
        : "5 of 5"
      : tier.outcomeType === "draw_entry"
        ? "3–4 matches"
        : "1–2 matches"
    : tier.matches;
  const outcome =
    tier.outcomeType === "cash" && tier.multiplier !== undefined
      ? `${formatKobo(Math.round(stakeKobo * tier.multiplier))} · ${tier.multiplier}× stake${tier.multiplier === 0.5 ? " (half back)" : ""}`
      : tier.outcomeType === "draw_entry"
        ? `${formatKobo(Math.round(stakeKobo * 0.1))} 5/90 entry`
        : "No prize";
  return (
    <div className={styles.prizeRow} role="row">
      <span role="cell">
        <strong>{matches}</strong>
        {label}
      </span>
      <span role="cell">{formatProbability(tier.probabilityBasisPoints)}</span>
      <strong role="cell">{outcome}</strong>
    </div>
  );
}

function SystemState({
  title,
  message,
  loading = false,
}: {
  title: string;
  message: string;
  loading?: boolean;
}) {
  return (
    <main className={styles.systemState}>
      <span aria-hidden="true">{loading ? "…" : "!"}</span>
      <h1>{title}</h1>
      <p>{message}</p>
      {!loading && <a href="/games">Back to games</a>}
    </main>
  );
}

function tileLabel(
  position: number,
  selected: boolean,
  result?: HeritageBoardPosition,
) {
  if (!result)
    return `Position ${position}: ${selected ? "selected" : "not selected"}`;
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

function tierOdds(tiers: HeritagePrizeTier[], tierId: HeritagePrizeTier["id"]) {
  const normalize = (value: string) =>
    value
      .toLowerCase()
      .replace(/^tier_/, "")
      .replaceAll("-", "_");
  const tier = tiers.find((item) => normalize(item.id) === normalize(tierId));
  return tier ? formatProbability(tier.probabilityBasisPoints) : "—";
}

function formatWat(value: string) {
  if (!value || Number.isNaN(new Date(value).getTime()))
    return "Awaiting draw confirmation";
  return new Intl.DateTimeFormat("en-NG", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Africa/Lagos",
  }).format(new Date(value));
}

function signedMoney(value: number) {
  return `${value < 0 ? "−" : "+"}${formatKobo(Math.abs(value))}`;
}

function formatDuration(seconds: number) {
  const minutes = Math.floor(seconds / 60);
  return `${minutes}:${String(seconds % 60).padStart(2, "0")}`;
}
