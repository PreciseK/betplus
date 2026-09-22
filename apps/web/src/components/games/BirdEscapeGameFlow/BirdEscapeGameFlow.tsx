"use client";

import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { birdEscapeMultiplierHundredthsAtElapsedMs as multiplierHundredthsAtElapsedMs } from "@betplus/api-client";
import styles from "./BirdEscapeGameFlow.module.css";
import { BirdEscape3DStage } from "./BirdEscape3DStage";
import { BetSlot, type BetSlotState } from "./BetSlot";
import { gameAudio } from "@/lib/gameAudio";
import {
  mockBirdEscapeGateway,
  BirdEscapeCashoutError,
  type BirdEscapeGateway,
  type BirdEscapeRoundState,
  MOCK_CHAT_MESSAGES,
  INITIAL_LIVE_WINNERS,
  ChatMessage,
} from "@/mocks/birdescape";

const PRESET_CHIPS_NAIRA = [100, 500, 2500, 10000];
// Polling every 1000ms (1s) during FLYING keeps the single-threaded PHP dev server
// responsive without request starvation or backlog queues. Since the growth rate
// is 10s per 1.00x, 1000ms is accurate and smooth while reducing server load by 80%.
const FLYING_POLL_INTERVAL_MS = 1000;

export interface BirdEscapeGameFlowProps {
  gateway?: BirdEscapeGateway;
}

function idleSlot(stake: number, autoCashoutMult: number): BetSlotState {
  return { stake, autoCashout: false, autoCashoutMult, status: "idle" };
}

function generateUUID(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === "x" ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

function syncSlotFromServer(
  setSlot: React.Dispatch<React.SetStateAction<BetSlotState>>,
  slot: BetSlotState,
  result: BirdEscapeRoundState,
  slotIndex: number,
): void {
  // Never override these — they are terminal or in-progress states already controlled by cashout/bet handlers
  if (slot.status === "queued" || slot.status === "placing" || slot.status === "cashing_out" || slot.status === "cashed_out") return;

  let serverBet = slot.betId !== undefined ? result.myBets.find((b) => b.betId === slot.betId) : undefined;

  if (!serverBet && slot.betId === undefined && result.myBets.length > slotIndex) {
    serverBet = result.myBets[slotIndex];
    if (serverBet) {
      setSlot((s) => ({
        ...s,
        betId: serverBet!.betId,
        stake: Math.max(1, Math.round(serverBet!.stakeKobo / 100)),
        autoCashout: serverBet!.autoCashoutMultiplierHundredths !== null,
        autoCashoutMult: (serverBet!.autoCashoutMultiplierHundredths ?? 200) / 100,
      }));
    }
  }

  if (!serverBet) {
    // Round crashed, no bet — ensure active/placed slots are reset to lost/idle
    if (result.status === "CRASHED" && (slot.status === "active" || slot.status === "placed")) {
      setSlot((s) => ({ ...s, status: "lost" }));
    }
    return;
  }

  if (serverBet.status === "CASHED_OUT") {
    setSlot((s) =>
      s.status === "cashed_out"
        ? s
        : {
            ...s,
            status: "cashed_out",
            cashedOutMultiplier: serverBet!.cashedOutAtMultiplierHundredths ?? undefined,
            netCreditKobo: serverBet!.netCreditKobo ?? undefined,
          },
    );
  } else if (serverBet.status === "LOST") {
    setSlot((s) => (s.status === "lost" ? s : { ...s, status: "lost" }));
  } else if (serverBet.status === "PLACED") {
    // During FLYING, always show 'active' (cashout button visible). During BETTING, show 'placed'.
    const nextStatus = result.status === "FLYING" ? "active" : "placed";
    setSlot((s) => {
      // Don't override if already at correct status or in a more specific status
      if (s.status === nextStatus || s.status === "active") return s;
      return { ...s, status: nextStatus };
    });
  }
}

export function BirdEscapeGameFlow({ gateway = mockBirdEscapeGateway }: BirdEscapeGameFlowProps) {
  const [roundState, setRoundState] = useState<BirdEscapeRoundState | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [clockOffsetMs, setClockOffsetMs] = useState(0);
  const [liveMultiplierHundredths, setLiveMultiplierHundredths] = useState(100);

  const [leftTab, setLeftTab] = useState<"history" | "leaderboard">("history");
  const [feedTab, setFeedTab] = useState<"players" | "chat">("players");
  const [showFairnessModal, setShowFairnessModal] = useState(false);
  const [chatMessages, setChatMessages] = useState<ChatMessage[]>(MOCK_CHAT_MESSAGES);
  const [chatInput, setChatInput] = useState("");
  const [isMuted, setIsMuted] = useState(() => gameAudio.getMuted());

  const [bet1, setBet1] = useState<BetSlotState>(() => idleSlot(100, 2.0));
  const [bet2, setBet2] = useState<BetSlotState>(() => idleSlot(500, 5.0));

  const bet1Ref = useRef(bet1);
  const bet2Ref = useRef(bet2);
  useEffect(() => {
    bet1Ref.current = bet1;
  }, [bet1]);
  useEffect(() => {
    bet2Ref.current = bet2;
  }, [bet2]);

  const prevRoundStatusRef = useRef<string | null>(null);
  const prevCountdownRef = useRef<number | null>(null);
  const roundCrashedRef = useRef(false);
  const crashMultiplierRef = useRef<number | null>(null);
  const triggerImmediatePollRef = useRef<(() => void) | null>(null);

  const formatNaira = (kobo: number) => `₦${(kobo / 100).toLocaleString("en-NG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

  const handleToggleMute = () => {
    const muted = gameAudio.toggleMute();
    setIsMuted(muted);
  };

  // Self-scheduling, adaptive polling with in-flight lock to prevent request congestion
  useEffect(() => {
    let cancelled = false;
    let timerId: NodeJS.Timeout | null = null;
    let inFlight = false;

    const resetSlotsForNewRound = () => {
      setBet1((s) =>
        s.status === "queued"
          ? s
          : { ...s, status: "idle", betId: undefined, error: undefined, cashedOutMultiplier: undefined, netCreditKobo: undefined },
      );
      setBet2((s) =>
        s.status === "queued"
          ? s
          : { ...s, status: "idle", betId: undefined, error: undefined, cashedOutMultiplier: undefined, netCreditKobo: undefined },
      );
    };

    const poll = async () => {
      if (cancelled || inFlight) return;
      inFlight = true;
      let nextDelayMs = 2000;

      try {
        const reqStart = Date.now();
        const result = await gateway.loadCurrentRound();
        if (cancelled) return;
        const reqEnd = Date.now();
        const rtt = reqEnd - reqStart;
        const serverTimeMs = Date.parse(result.serverTime);
        const accurateOffset = serverTimeMs + Math.round(rtt / 2) - reqEnd;
        setClockOffsetMs(accurateOffset);

        setLoadFailed(false);
        setRoundState((prev) => {
          if (prev !== null && prev.roundNumber !== result.roundNumber) {
            resetSlotsForNewRound();
          }
          return result;
        });

        if (result.status === "CRASHED") {
          roundCrashedRef.current = true;
          if (result.crashMultiplierHundredths !== null && result.crashMultiplierHundredths !== undefined) {
            crashMultiplierRef.current = result.crashMultiplierHundredths;
            setLiveMultiplierHundredths(result.crashMultiplierHundredths);
          }
        } else if (result.status === "BETTING") {
          roundCrashedRef.current = false;
          crashMultiplierRef.current = null;
        } else if (result.status === "FLYING") {
          roundCrashedRef.current = false;
          if (result.crashMultiplierHundredths !== null && result.crashMultiplierHundredths !== undefined) {
            crashMultiplierRef.current = result.crashMultiplierHundredths;
          }
        }

        syncSlotFromServer(setBet1, bet1Ref.current, result, 0);
        syncSlotFromServer(setBet2, bet2Ref.current, result, 1);

        if (typeof document !== "undefined" && document.hidden) {
          nextDelayMs = 5000;
        } else if (result.status === "BETTING") {
          const bettingStartMs = Date.parse(result.bettingStartedAt);
          const elapsedMs = reqEnd + accurateOffset - bettingStartMs;
          const remainingSec = Math.max(0, result.bettingWindowSeconds - Math.floor(elapsedMs / 1000));
          nextDelayMs = remainingSec <= 2 ? 800 : 1500;
        } else if (result.status === "FLYING") {
          // Poll at 300ms during flight to minimize crash latency and prevent overshoot
          nextDelayMs = 300;
        } else if (result.status === "CRASHED") {
          nextDelayMs = 1500;
        }
      } catch {
        if (!cancelled) setLoadFailed(true);
        nextDelayMs = 2500;
      } finally {
        inFlight = false;
        if (!cancelled) {
          timerId = setTimeout(poll, nextDelayMs);
        }
      }
    };

    triggerImmediatePollRef.current = () => {
      if (timerId) clearTimeout(timerId);
      void poll();
    };

    const handleVisibilityChange = () => {
      if (typeof document !== "undefined" && !document.hidden && !cancelled) {
        if (timerId) clearTimeout(timerId);
        void poll();
      }
    };

    if (typeof document !== "undefined") {
      document.addEventListener("visibilitychange", handleVisibilityChange);
    }

    void poll();
    return () => {
      cancelled = true;
      triggerImmediatePollRef.current = null;
      if (timerId) clearTimeout(timerId);
      if (typeof document !== "undefined") {
        document.removeEventListener("visibilitychange", handleVisibilityChange);
      }
    };
  }, [gateway]);

  // Live multiplier animation: reads 1.00x (break-even) during betting and at flight
  // takeoff — never 0.00x, since a crash game's multiplier is never below what an
  // instant cash-out would return. Freezes at the true, server-revealed crash value
  // the moment status flips to CRASHED (both here and in the poll handler above,
  // which sets it immediately on the same tick the crash is learned about, rather
  // than waiting for this effect's dependencies to settle).
  useEffect(() => {
    if (roundState?.status === "CRASHED") {
      roundCrashedRef.current = true;
      if (roundState.crashMultiplierHundredths !== null && roundState.crashMultiplierHundredths !== undefined) {
        crashMultiplierRef.current = roundState.crashMultiplierHundredths;
        setLiveMultiplierHundredths(roundState.crashMultiplierHundredths);
      }
      return;
    }

    if (roundState?.status === "BETTING") {
      roundCrashedRef.current = false;
      crashMultiplierRef.current = null;
      setLiveMultiplierHundredths(100);
      return;
    }

    if (roundState?.status !== "FLYING" || !roundState.flightStartedAt) {
      setLiveMultiplierHundredths(100);
      return;
    }

    roundCrashedRef.current = false;
    crashMultiplierRef.current = roundState.crashMultiplierHundredths ?? null;

    const flightStartMs = Date.parse(roundState.flightStartedAt);
    const growthRateConstant = roundState.growthRateConstant;
    let animationFrameId: number;

    const tickFlight = () => {
      if (roundCrashedRef.current) {
        if (crashMultiplierRef.current !== null) {
          setLiveMultiplierHundredths(crashMultiplierRef.current);
        }
        return;
      }

      const estimatedNow = Date.now() + clockOffsetMs;
      const elapsed = Math.max(0, estimatedNow - flightStartMs);
      const mult = multiplierHundredthsAtElapsedMs(elapsed, growthRateConstant);

      // Prevent visual overshoot if crashMultiplier is already known
      if (crashMultiplierRef.current !== null && mult >= crashMultiplierRef.current) {
        setLiveMultiplierHundredths(crashMultiplierRef.current);
        return;
      }

      setLiveMultiplierHundredths(mult);
      animationFrameId = requestAnimationFrame(tickFlight);
    };

    animationFrameId = requestAnimationFrame(tickFlight);
    return () => cancelAnimationFrame(animationFrameId);
  }, [
    roundState?.status,
    roundState?.flightStartedAt,
    roundState?.growthRateConstant,
    roundState?.crashMultiplierHundredths,
    clockOffsetMs,
  ]);

  // Audio cues on round state transitions
  useEffect(() => {
    if (!roundState) return;
    const prevStatus = prevRoundStatusRef.current;
    const currentStatus = roundState.status;

    if (prevStatus !== currentStatus) {
      if (currentStatus === "BETTING") {
        gameAudio.playGameStart();
      } else if (currentStatus === "FLYING") {
        gameAudio.playTakeoff();
      } else if (currentStatus === "CRASHED") {
        gameAudio.playCrash();
      }
      prevRoundStatusRef.current = currentStatus;
    }
  }, [roundState?.status]);

  const countdownSeconds =
    roundState?.status === "BETTING"
      ? Math.max(0, roundState.bettingWindowSeconds - Math.floor((Date.now() + clockOffsetMs - Date.parse(roundState.bettingStartedAt)) / 1000))
      : 0;

  // Countdown audio ticks
  useEffect(() => {
    if (roundState?.status === "BETTING" && countdownSeconds > 0 && countdownSeconds !== prevCountdownRef.current) {
      if (countdownSeconds <= 5) {
        gameAudio.playCountdownTick(countdownSeconds);
      }
      prevCountdownRef.current = countdownSeconds;
    }
  }, [roundState?.status, countdownSeconds]);

  const handlePlaceBet = useCallback(
    async (slotIndex: 1 | 2) => {
      const slot = slotIndex === 1 ? bet1Ref.current : bet2Ref.current;
      const setSlot = slotIndex === 1 ? setBet1 : setBet2;
      if (!roundState || roundState.status !== "BETTING") return;

      const stakeKobo = Math.round(slot.stake * 100);
      if (roundState.playBalanceKobo < stakeKobo) {
        setSlot((s) => ({ ...s, error: "Insufficient play balance." }));
        return;
      }

      // 1. Instant optimistic transition: immediately mark as placed and deduct balance in 0ms
      setSlot((s) => ({ ...s, status: "placed", error: undefined }));
      setRoundState((prev) => (prev ? { ...prev, playBalanceKobo: Math.max(0, prev.playBalanceKobo - stakeKobo) } : prev));

      const idempotencyKey = generateUUID();

      try {
        const result = await gateway.placeBet({
          roundId: roundState.roundId,
          stakeKobo,
          autoCashoutMultiplierHundredths: slot.autoCashout ? Math.max(200, Math.round(slot.autoCashoutMult * 100)) : undefined,
          idempotencyKey,
        });
        setSlot((s) => ({ ...s, status: "placed", betId: result.betId }));
        setRoundState((prev) => (prev ? { ...prev, playBalanceKobo: result.playBalanceAfterKobo } : prev));
      } catch (error) {
        // Rollback state if network/server rejects
        setSlot((s) => ({
          ...s,
          status: "idle",
          error: error instanceof Error ? error.message : "Could not place this bet.",
        }));
        setRoundState((prev) => (prev ? { ...prev, playBalanceKobo: prev.playBalanceKobo + stakeKobo } : prev));
      }
    },
    [gateway, roundState],
  );

  // Automatically execute queued bets when new betting round opens
  useEffect(() => {
    if (roundState?.status === "BETTING") {
      if (bet1.status === "queued") {
        void handlePlaceBet(1);
      }
      if (bet2.status === "queued") {
        void handlePlaceBet(2);
      }
    }
  }, [roundState?.status, roundState?.roundId, bet1.status, bet2.status, handlePlaceBet]);

  // Support placing bet immediately or queuing for next round anytime
  const handlePlaceOrQueueBet = useCallback(
    (slotIndex: 1 | 2) => {
      const slot = slotIndex === 1 ? bet1 : bet2;
      const setSlot = slotIndex === 1 ? setBet1 : setBet2;

      if (slot.status === "queued") {
        // Cancel queued bet
        setSlot((s) => ({ ...s, status: "idle" }));
        return;
      }

      if (slot.status === "idle") {
        if (roundState?.status === "BETTING") {
          void handlePlaceBet(slotIndex);
        } else {
          // Queue for next round
          setSlot((s) => ({ ...s, status: "queued", error: undefined }));
        }
      }
    },
    [bet1, bet2, roundState?.status, handlePlaceBet],
  );

  const handleCashout = useCallback(
    async (slotIndex: 1 | 2) => {
      const slot = slotIndex === 1 ? bet1Ref.current : bet2Ref.current;
      const setSlot = slotIndex === 1 ? setBet1 : setBet2;
      // Allow cashout from both 'active' (confirmed by server) and 'placed' (during FLYING — optimistic placement)
      if ((slot.status !== "active" && slot.status !== "placed") || slot.betId === undefined) return;

      const preCashoutStatus = slot.status;
      setSlot((s) => ({ ...s, status: "cashing_out", error: undefined }));
      try {
        const result = await gateway.cashout(slot.betId);
        if (result.status === "CASHED_OUT") {
          gameAudio.playCashout();
          setSlot((s) => ({
            ...s,
            status: "cashed_out",
            cashedOutMultiplier: result.cashedOutAtMultiplierHundredths ?? undefined,
            netCreditKobo: result.netCreditKobo ?? undefined,
          }));
          setRoundState((prev) => (prev ? { ...prev, winningsBalanceKobo: result.winningsBalanceAfterKobo } : prev));
        } else if (result.status === "LOST") {
          // Round crashed at the same moment — a normal loss, not an error
          roundCrashedRef.current = true;
          setSlot((s) => ({ ...s, status: "lost" }));
          triggerImmediatePollRef.current?.();
        } else if (result.status === "PLACED") {
          // Server returned the bet still PLACED — restore to active during flight
          setSlot((s) => ({ ...s, status: preCashoutStatus }));
        } else {
          setSlot((s) => ({ ...s, status: preCashoutStatus }));
        }
      } catch (error) {
        // On network/server error, restore to prior state so player can retry
        const isRoundCrashed =
          error instanceof BirdEscapeCashoutError && error.code === "TOO_LATE_ROUND_CRASHED";
        if (isRoundCrashed) {
          roundCrashedRef.current = true;
          triggerImmediatePollRef.current?.();
        }
        setSlot((s) => ({
          ...s,
          status: isRoundCrashed ? "lost" : preCashoutStatus,
          error: isRoundCrashed ? undefined : "Cash out failed — please try again.",
        }));
      }
    },
    [gateway],
  );

  // Client-side auto-cashout backstopped server-side
  useEffect(() => {
    if (roundState?.status !== "FLYING") return;
    if ((bet1.status === "active" || bet1.status === "placed") && bet1.autoCashout && liveMultiplierHundredths >= Math.round(bet1.autoCashoutMult * 100)) {
      void handleCashout(1);
    }
    if ((bet2.status === "active" || bet2.status === "placed") && bet2.autoCashout && liveMultiplierHundredths >= Math.round(bet2.autoCashoutMult * 100)) {
      void handleCashout(2);
    }
  }, [liveMultiplierHundredths, roundState?.status, bet1, bet2, handleCashout]);

  const handleSendChat = (e: React.FormEvent) => {
    e.preventDefault();
    if (!chatInput.trim()) return;
    setChatMessages((prev) => [
      ...prev,
      { id: `chat_${Date.now()}`, username: "You", avatar: "", text: chatInput, time: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) },
    ]);
    setChatInput("");
  };

  const recentCrashes = useMemo(() => {
    if (!roundState) return [];
    const list = [...(roundState.recentRounds ?? [])];
    if (
      roundState.status === "CRASHED" &&
      roundState.crashMultiplierHundredths !== null &&
      roundState.crashMultiplierHundredths !== undefined
    ) {
      if (!list.some((r) => r.roundNumber === roundState.roundNumber)) {
        list.unshift({
          roundNumber: roundState.roundNumber,
          crashMultiplierHundredths: roundState.crashMultiplierHundredths,
          crashedAt: new Date().toISOString(),
        });
      }
    }
    return list;
  }, [roundState?.recentRounds, roundState?.status, roundState?.crashMultiplierHundredths, roundState?.roundNumber]);

  if (loadFailed && roundState === null) {
    return (
      <div className={styles.container}>
        <div className={styles.stageCenterDisplay} style={{ position: "static", padding: "4rem" }}>
          <span className={styles.roundStatusLabel} style={{ color: "#ef4444" }}>
            Couldn&apos;t reach Caged right now.
          </span>
        </div>
      </div>
    );
  }

  if (roundState === null) {
    return (
      <div className={styles.container}>
        <div className={styles.stageCenterDisplay} style={{ position: "static", padding: "4rem" }}>
          <span className={styles.roundStatusLabel}>Loading…</span>
        </div>
      </div>
    );
  }

  return (
    <div className={styles.container}>
      <header className={styles.topNav}>
        <div className={styles.brandGroup}>
          <svg className={styles.birdLogo} viewBox="0 0 24 24" fill="currentColor">
            <path d="M21 4.5c-.8.4-1.6.6-2.5.7.9-.5 1.6-1.4 1.9-2.4-.8.5-1.8.9-2.8 1.1-.8-.8-1.9-1.4-3.1-1.4-2.4 0-4.3 1.9-4.3 4.3 0 .3 0 .7.1 1-3.6-.2-6.7-1.9-8.8-4.5-.4.7-.6 1.4-.6 2.2 0 1.5.8 2.8 1.9 3.6-.7 0-1.4-.2-2-.6v.1c0 2.1 1.5 3.8 3.5 4.2-.4.1-.7.2-1.1.2-.3 0-.5 0-.8-.1.6 1.7 2.1 3 4 3-1.5 1.2-3.4 1.9-5.4 1.9-.4 0-.7 0-1.1-.1 1.9 1.2 4.2 1.9 6.7 1.9 8 0 12.4-6.6 12.4-12.4v-.6c.9-.6 1.6-1.4 2.2-2.3z" />
          </svg>
          <span className={styles.gameTitle}>Caged</span>
          <span className={styles.crashBadge}>CRASH</span>
        </div>

        <div className={styles.headerActions}>
          <button className={styles.soundToggleBtn} onClick={handleToggleMute} aria-label={isMuted ? "Unmute sound" : "Mute sound"}>
            {isMuted ? (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M11 5L6 9H2v6h4l5 4V5z" />
                <line x1="23" y1="9" x2="17" y2="15" />
                <line x1="17" y1="9" x2="23" y2="15" />
              </svg>
            ) : (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
                <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07" />
              </svg>
            )}
            <span>{isMuted ? "Muted" : "Sound"}</span>
          </button>

          <button className={styles.provablyFairBtn} onClick={() => setShowFairnessModal(true)} aria-label="View provably fair verification">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
            </svg>
            Provably Fair
          </button>

          <div className={styles.balancePill} aria-label="Wallet Balance">
            <svg className={styles.walletIcon} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="2" y="4" width="20" height="16" rx="2" />
              <path d="M6 12h.01M18 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" />
            </svg>
            <span>{formatNaira(roundState.playBalanceKobo)}</span>
          </div>

          <a href="/games" className={styles.exitGameBtn} aria-label="Exit game">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            <span>Exit Game</span>
          </a>
        </div>
      </header>

      <section className={styles.tickerBar} aria-label="Round history">
        <span className={styles.tickerLabel}>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
            <circle cx="12" cy="12" r="10" />
            <polyline points="12 6 12 12 16 14" />
          </svg>
          ROUND HISTORY
        </span>
        <div className={styles.historyStream}>
          {recentCrashes.length === 0 ? (
            <div className={styles.historyPill} style={{ opacity: 0.6 }}>Waiting for round results…</div>
          ) : (
            recentCrashes.map((r, index) => {
              const mult = r.crashMultiplierHundredths / 100;
              const colorClass =
                mult >= 10.0
                  ? styles.historyPillHigh
                  : mult >= 2.0
                  ? styles.historyPillMid
                  : styles.historyPillLow;

              return (
                <div
                  key={`${r.roundNumber}-${index}`}
                  className={`${styles.historyPill} ${colorClass}`}
                  title={`Round #${r.roundNumber} crashed at ${mult.toFixed(2)}×`}
                >
                  {mult.toFixed(2)}×
                </div>
              );
            })
          )}
        </div>
      </section>

      <main className={styles.mainLayout}>
        {/* Left Sidebar: History & Leaderboard */}
        <aside className={styles.leftSidebar}>
          <div className={styles.leftTabsCard}>
            <div className={styles.leftTabsHeader}>
              <button
                className={`${styles.leftTabBtn} ${leftTab === "history" ? styles.activeLeftTab : ""}`}
                onClick={() => setLeftTab("history")}
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2">
                  <circle cx="12" cy="12" r="10" />
                  <polyline points="12 6 12 12 16 14" />
                </svg>
                History
              </button>
              <button
                className={`${styles.leftTabBtn} ${leftTab === "leaderboard" ? styles.activeLeftTab : ""}`}
                onClick={() => setLeftTab("leaderboard")}
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2">
                  <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6" />
                  <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18" />
                  <path d="M4 22h16" />
                  <path d="M10 14.66V17c0 .55-.45 1-1 1H7c-.55 0-1-.45-1-1v-2.34" />
                  <path d="M18 14.66V17c0 .55-.45 1-1 1h-2c-.55 0-1-.45-1-1v-2.34" />
                  <path d="M6 2h12v7a6 6 0 0 1-12 0V2z" />
                </svg>
                Leaderboard
              </button>
            </div>

            <div className={styles.leftTabBody}>
              {leftTab === "history" ? (
                recentCrashes.length === 0 ? (
                  <div className={styles.historyRowItem} style={{ justifyContent: "center", opacity: 0.6 }}>
                    <span>No previous rounds yet</span>
                  </div>
                ) : (
                  recentCrashes.map((r, idx) => {
                    const mult = r.crashMultiplierHundredths / 100;
                    const colorClass =
                      mult >= 10.0
                        ? styles.historyPillHigh
                        : mult >= 2.0
                        ? styles.historyPillMid
                        : styles.historyPillLow;

                    return (
                      <div key={`${r.roundNumber}-${idx}`} className={styles.historyRowItem}>
                        <div className={styles.historyRoundMeta}>
                          <span className={styles.historyRoundNum}>Round #{r.roundNumber}</span>
                          <span className={styles.historyRoundTime}>
                            {r.crashedAt ? new Date(r.crashedAt).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : "Just now"}
                          </span>
                        </div>
                        <span className={`${styles.historyPill} ${colorClass}`}>
                          {mult.toFixed(2)}×
                        </span>
                      </div>
                    );
                  })
                )
              ) : (
                INITIAL_LIVE_WINNERS.map((winner, idx) => (
                  <div key={winner.id} className={styles.leaderboardRowItem}>
                    <span className={`${styles.leaderRank} ${idx < 3 ? styles.leaderRankTop : ""}`}>
                      {idx === 0 ? "🥇" : idx === 1 ? "🥈" : idx === 2 ? "🥉" : `#${idx + 1}`}
                    </span>
                    <div className={styles.leaderUserMeta}>
                      <span className={styles.leaderUserName}>{winner.username}</span>
                    </div>
                    <span className={`${styles.historyPill} ${winner.multiplier >= 5.0 ? styles.historyPillHigh : styles.historyPillMid}`}>
                      {winner.multiplier.toFixed(2)}×
                    </span>
                    <span className={styles.leaderPayout}>{formatNaira(winner.amountKobo)}</span>
                  </div>
                ))
              )}
            </div>
          </div>
        </aside>
        <div className={styles.stageAndControls}>
          <div className={`${styles.gameStage} ${roundState.status === "CRASHED" ? styles.stageCrashedFlash : ""}`}>
            {/* Top right floating countdown on stage */}
            {roundState.status === "BETTING" && (
              <div className={styles.stageFloatingCountdown}>
                <span className={styles.countdownPulseDot} />
                <span className={styles.countdownLabel}>BETTING OPEN</span>
                <span className={styles.countdownTimerNum}>{countdownSeconds}s</span>
              </div>
            )}

            {/* Prominent High-Visibility Crash Alert Banner */}
            {roundState.status === "CRASHED" && (
              <div className={styles.crashHeroBanner}>
                <div className={styles.crashAlertTag}>💥 BIRD ESCAPED</div>
                <div className={styles.crashMultiplierBig}>
                  @ {(liveMultiplierHundredths / 100).toFixed(2)}×
                </div>
              </div>
            )}

            <BirdEscape3DStage
              roundPhase={roundState.status === "BETTING" ? "betting" : roundState.status === "FLYING" ? "flying" : "crashed"}
              multiplier={liveMultiplierHundredths / 100}
            />

            {/* Center Multiplier Display */}
            <div className={styles.stageCenterDisplay}>
              <div
                key={roundState.status === "CRASHED" ? `crashed_${roundState.roundNumber}` : "flying"}
                className={`${styles.multiplierValue} ${
                  roundState.status === "FLYING"
                    ? styles.liveFlying
                    : roundState.status === "CRASHED"
                    ? styles.crashed
                    : styles.bettingIdle
                }`}
              >
                {roundState.status === "CRASHED" && roundState.crashMultiplierHundredths !== null && roundState.crashMultiplierHundredths !== undefined
                  ? (roundState.crashMultiplierHundredths / 100).toFixed(2)
                  : (liveMultiplierHundredths / 100).toFixed(2)}×
              </div>

              {roundState.status === "BETTING" && (
                <span className={styles.roundStatusLabel}>
                  Next flight preparing...
                </span>
              )}

              {roundState.status === "CRASHED" && (
                <span className={styles.roundStatusLabel} style={{ color: "#ef4444", fontWeight: 800 }}>
                  💥 CRASHED @ {roundState.crashMultiplierHundredths !== null && roundState.crashMultiplierHundredths !== undefined
                    ? (roundState.crashMultiplierHundredths / 100).toFixed(2)
                    : (liveMultiplierHundredths / 100).toFixed(2)}×
                </span>
              )}
            </div>
          </div>

          <div className={styles.dualBetControls}>
            <BetSlot
              label="BET 1"
              state={bet1}
              balanceKobo={roundState.playBalanceKobo}
              roundStatus={roundState.status}
              liveMultiplierHundredths={liveMultiplierHundredths}
              formatNaira={formatNaira}
              presetChips={PRESET_CHIPS_NAIRA}
              onChangeStake={(stake) => setBet1((s) => ({ ...s, stake }))}
              onHalfStake={() => setBet1((s) => ({ ...s, stake: Math.max(1, Math.floor(s.stake / 2)) }))}
              onDoubleStake={() => setBet1((s) => ({ ...s, stake: s.stake * 2 }))}
              onMaxStake={() => setBet1((s) => ({ ...s, stake: Math.floor(roundState.playBalanceKobo / 100) }))}
              onSelectPreset={(stake) => setBet1((s) => ({ ...s, stake }))}
              onChangeAutoCashoutMult={(mult) => setBet1((s) => ({ ...s, autoCashoutMult: mult }))}
              onToggleAutoCashout={(enabled) => setBet1((s) => ({ ...s, autoCashout: enabled }))}
              onPlaceOrCancel={() => handlePlaceOrQueueBet(1)}
              onCashout={() => void handleCashout(1)}
            />
            <BetSlot
              label="BET 2"
              state={bet2}
              balanceKobo={roundState.playBalanceKobo}
              roundStatus={roundState.status}
              liveMultiplierHundredths={liveMultiplierHundredths}
              formatNaira={formatNaira}
              presetChips={PRESET_CHIPS_NAIRA}
              onChangeStake={(stake) => setBet2((s) => ({ ...s, stake }))}
              onHalfStake={() => setBet2((s) => ({ ...s, stake: Math.max(1, Math.floor(s.stake / 2)) }))}
              onDoubleStake={() => setBet2((s) => ({ ...s, stake: s.stake * 2 }))}
              onMaxStake={() => setBet2((s) => ({ ...s, stake: Math.floor(roundState.playBalanceKobo / 100) }))}
              onSelectPreset={(stake) => setBet2((s) => ({ ...s, stake }))}
              onChangeAutoCashoutMult={(mult) => setBet2((s) => ({ ...s, autoCashoutMult: mult }))}
              onToggleAutoCashout={(enabled) => setBet2((s) => ({ ...s, autoCashout: enabled }))}
              onPlaceOrCancel={() => handlePlaceOrQueueBet(2)}
              onCashout={() => void handleCashout(2)}
            />
          </div>
        </div>

        <aside className={styles.sidebarColumn}>
          <div className={styles.roundStatsCard}>
            <div className={styles.roundStatsHeader}>
              <span className={styles.roundStatsLabel}>CURRENT ROUND</span>
              <span className={styles.roundStatusBadge}>
                {roundState.status === "BETTING" ? "Betting" : roundState.status === "FLYING" ? "In Flight" : "Crashed"}
              </span>
            </div>

            {/* Prominent Sidebar Countdown */}
            {roundState.status === "BETTING" ? (
              <div className={styles.sideCountdownBlock}>
                <div className={styles.sideCountdownHeader}>
                  <span className={styles.sideCountdownTitle}>FLIGHT COUNTDOWN</span>
                  <span className={styles.sideCountdownSeconds}>{countdownSeconds}s</span>
                </div>
                <div className={styles.statsProgressBar}>
                  <div
                    className={styles.statsProgressFill}
                    style={{
                      width: `${Math.max(0, Math.min(100, ((roundState.bettingWindowSeconds - countdownSeconds) / roundState.bettingWindowSeconds) * 100))}%`,
                    }}
                  />
                </div>
              </div>
            ) : (
              <div className={styles.sideStatusBlock}>
                <span className={styles.sideStatusPill}>
                  {roundState.status === "FLYING" ? "🚀 Flight in Progress" : "💥 Round Crashed"}
                </span>
              </div>
            )}

            <div className={styles.statMetricsGrid}>
              <div className={styles.metricBlock}>
                <span className={styles.metricTitle}>Players</span>
                <span className={styles.metricValue}>{roundState.players.length}</span>
              </div>
              <div className={styles.metricBlock}>
                <span className={styles.metricTitle}>Total Bets</span>
                <span className={`${styles.metricValue} ${styles.highlight}`}>
                  {formatNaira(roundState.players.reduce((sum, p) => sum + p.stakeKobo, 0))}
                </span>
              </div>
            </div>
          </div>

          <div className={styles.feedCard}>
            <div className={styles.feedTabsHeader}>
              <button className={`${styles.feedTabButton} ${feedTab === "players" ? styles.activeTab : ""}`} onClick={() => setFeedTab("players")}>
                Players
              </button>
              <button className={`${styles.feedTabButton} ${feedTab === "chat" ? styles.activeTab : ""}`} onClick={() => setFeedTab("chat")}>
                Chat
              </button>
            </div>

            {feedTab === "players" ? (
              <div className={styles.feedContentList}>
                {roundState.players.length === 0 && (
                  <div className={styles.playerRow}>
                    <span className={styles.playerName}>No other players this round yet</span>
                  </div>
                )}
                {roundState.players.map((player, index) => (
                  <div
                    key={index}
                    className={`${styles.playerRow} ${player.cashedOutAtMultiplierHundredths !== null ? styles.playerRowCashedOut : ""}`}
                  >
                    <div className={styles.playerLeft}>
                      <span className={styles.playerName}>Player</span>
                    </div>
                    <div className={styles.playerRight}>
                      <span className={styles.playerStake}>{formatNaira(player.stakeKobo)}</span>
                      {player.cashedOutAtMultiplierHundredths !== null ? (
                        <span className={styles.playerMultiplierBadge}>
                          ✓ {(player.cashedOutAtMultiplierHundredths / 100).toFixed(2)}×
                        </span>
                      ) : (
                        <span className={styles.playerStatusDot} />
                      )}
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className={styles.feedContentList} style={{ display: "flex", flexDirection: "column" }}>
                <div style={{ flex: 1, display: "flex", flexDirection: "column", gap: "0.5rem" }}>
                  {chatMessages.map((msg) => (
                    <div key={msg.id} className={`${styles.chatMessageItem} ${msg.isBigWin ? styles.bigWin : ""}`}>
                      <div className={styles.chatHeader}>
                        <span className={styles.chatUser}>{msg.username}</span>
                        <span className={styles.chatTime}>{msg.time}</span>
                      </div>
                      <span className={styles.chatText}>{msg.text}</span>
                    </div>
                  ))}
                </div>
                <form onSubmit={handleSendChat} className={styles.chatInputRow}>
                  <input
                    type="text"
                    placeholder="Send message..."
                    value={chatInput}
                    onChange={(e) => setChatInput(e.target.value)}
                    className={styles.chatInput}
                  />
                  <button type="submit" className={styles.chatSendButton}>
                    Send
                  </button>
                </form>
              </div>
            )}
          </div>
        </aside>
      </main>

      {showFairnessModal && (
        <div className={styles.modalBackdrop} onClick={() => setShowFairnessModal(false)}>
          <div className={styles.modalDialog} onClick={(e) => e.stopPropagation()}>
            <div className={styles.modalHeader}>
              <h3 className={styles.modalTitle}>Provably Fair Verification</h3>
              <button className={styles.closeModalBtn} onClick={() => setShowFairnessModal(false)}>
                &times;
              </button>
            </div>
            <div className={styles.modalBody}>
              <p>
                Every Caged crash point is committed before betting opens — the server publishes a hash of the
                seed and crash multiplier, then reveals both once the round crashes, so anyone can recompute the hash
                and confirm it was never changed after the fact.
              </p>
              <div>
                <strong>Round #{roundState.roundNumber} Commitment Hash:</strong>
                <div className={styles.hashBox}>{roundState.commitmentDigest}</div>
              </div>
              {roundState.status === "CRASHED" && roundState.seedHex && (
                <div>
                  <strong>Revealed Seed:</strong>
                  <div className={styles.hashBox}>{roundState.seedHex}</div>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
