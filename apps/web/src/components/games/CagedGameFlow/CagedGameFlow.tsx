"use client";

import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { cagedMultiplierHundredthsAtElapsedMs as multiplierHundredthsAtElapsedMs } from "@betplus/api-client";
import styles from "./CagedGameFlow.module.css";
import { Caged3DStage } from "./Caged3DStage";
import { BetSlot, type BetSlotState } from "./BetSlot";
import { gameAudio } from "@/lib/gameAudio";
import {
  mockCagedGateway,
  CagedCashoutError,
  type CagedGateway,
  type CagedRoundState,
} from "@/mocks/caged";
import {
  type CagedActivePlayer,
  createInitial50Players,
  startNewRoundBets,
  updateFlyingCashouts,
  settleCrashedRound,
  mergeRealAndSimulatedPlayers,
  AVATAR_GRADIENTS,
} from "./cagedActivePlayers";
import {
  type CagedChatMessage,
  INITIAL_CHAT_MESSAGES,
  CHAT_REACTION_EMOJIS,
  createRoundEventChatMessage,
  createOrganicChatMessage,
  getSimulatedOnlineCount,
} from "./cagedChatSystem";

const PRESET_CHIPS_NAIRA = [100, 500, 2500, 10000];
// Polling every 1000ms (1s) during FLYING keeps the single-threaded PHP dev server
// responsive without request starvation or backlog queues. Since the growth rate
// is 10s per 1.00x, 1000ms is accurate and smooth while reducing server load by 80%.
const FLYING_POLL_INTERVAL_MS = 1000;

export interface CagedGameFlowProps {
  gateway?: CagedGateway;
}
export type BirdEscapeGameFlowProps = CagedGameFlowProps;

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
  result: CagedRoundState,
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

export function CagedGameFlow({ gateway = mockCagedGateway }: CagedGameFlowProps) {
  const [roundState, setRoundState] = useState<CagedRoundState | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [clockOffsetMs, setClockOffsetMs] = useState(0);
  const [liveMultiplierHundredths, setLiveMultiplierHundredths] = useState(100);

  const [feedTab, setFeedTab] = useState<"players" | "chat">("players");
  const [isMobileFeedExpanded, setIsMobileFeedExpanded] = useState(false);
  const [showFairnessModal, setShowFairnessModal] = useState(false);
  const [chatMessages, setChatMessages] = useState<CagedChatMessage[]>(INITIAL_CHAT_MESSAGES);
  const [chatInput, setChatInput] = useState("");
  const [onlineUsersCount] = useState<number>(() => getSimulatedOnlineCount());
  const [hasUnreadMessages, setHasUnreadMessages] = useState(false);
  const chatContainerRef = useRef<HTMLDivElement>(null);
  const chatMessagesEndRef = useRef<HTMLDivElement>(null);
  const [isMuted, setIsMuted] = useState(() => gameAudio.getMuted());
  const [simulatedPlayers, setSimulatedPlayers] = useState<CagedActivePlayer[]>(() => createInitial50Players());
  const lastCheckedMultiplierHundredthsRef = useRef(100);

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

  // Real player bets placed by the current user (Slot 1 and Slot 2)
  const currentUserBetsAsPlayers = useMemo<CagedActivePlayer[]>(() => {
    const list: CagedActivePlayer[] = [];
    if (bet1.status === "placed" || bet1.status === "active" || bet1.status === "cashed_out" || bet1.status === "lost") {
      const isCashedOut = bet1.status === "cashed_out";
      const isLost = bet1.status === "lost";
      const mult = bet1.cashedOutMultiplier ? bet1.cashedOutMultiplier / 100 : bet1.autoCashoutMult;
      const payout = isCashedOut
        ? bet1.netCreditKobo ?? Math.round(bet1.stake * (bet1.cashedOutMultiplier ?? 100))
        : 0;
      list.push({
        id: `user_bet_slot_1_${roundState?.roundNumber || 0}`,
        username: "You",
        stakeKobo: Math.round(bet1.stake * 100),
        targetMultiplier: mult,
        cashedOut: isCashedOut,
        lost: isLost,
        cashedOutAtMultiplierHundredths: bet1.cashedOutMultiplier ?? null,
        payoutKobo: payout,
        isRealPlayer: true,
        isCurrentUser: true,
      });
    }

    if (bet2.status === "placed" || bet2.status === "active" || bet2.status === "cashed_out" || bet2.status === "lost") {
      const isCashedOut = bet2.status === "cashed_out";
      const isLost = bet2.status === "lost";
      const mult = bet2.cashedOutMultiplier ? bet2.cashedOutMultiplier / 100 : bet2.autoCashoutMult;
      const payout = isCashedOut
        ? bet2.netCreditKobo ?? Math.round(bet2.stake * (bet2.cashedOutMultiplier ?? 100))
        : 0;
      list.push({
        id: `user_bet_slot_2_${roundState?.roundNumber || 0}`,
        username: "You (Bet 2)",
        stakeKobo: Math.round(bet2.stake * 100),
        targetMultiplier: mult,
        cashedOut: isCashedOut,
        lost: isLost,
        cashedOutAtMultiplierHundredths: bet2.cashedOutMultiplier ?? null,
        payoutKobo: payout,
        isRealPlayer: true,
        isCurrentUser: true,
      });
    }
    return list;
  }, [bet1, bet2, roundState?.roundNumber]);

  // Other real players connected to this round from the gateway / server
  const otherRealPlayers = useMemo<CagedActivePlayer[]>(() => {
    if (!roundState?.players || roundState.players.length === 0) return [];
    return roundState.players.map((p, idx) => {
      const isCashedOut = p.status === "CASHED_OUT";
      const isLost = p.status === "LOST" || (roundState.status === "CRASHED" && !isCashedOut);
      const mult = p.cashedOutAtMultiplierHundredths ? p.cashedOutAtMultiplierHundredths / 100 : 2.0;
      const payout = isCashedOut && p.cashedOutAtMultiplierHundredths
        ? Math.round((p.stakeKobo * p.cashedOutAtMultiplierHundredths) / 100)
        : 0;
      return {
        id: `real_player_${idx}_${p.stakeKobo}`,
        username: p.username || `Player #${idx + 1}`,
        stakeKobo: p.stakeKobo,
        targetMultiplier: mult,
        cashedOut: isCashedOut,
        lost: isLost,
        cashedOutAtMultiplierHundredths: p.cashedOutAtMultiplierHundredths,
        payoutKobo: payout,
        isRealPlayer: true,
      };
    });
  }, [roundState?.players, roundState?.status]);

  // All active real players in the current round
  const allCurrentRealPlayers = useMemo<CagedActivePlayer[]>(() => {
    return [...currentUserBetsAsPlayers, ...otherRealPlayers];
  }, [currentUserBetsAsPlayers, otherRealPlayers]);

  // Progressive staking across BETTING countdown: simulated players place their bets dynamically
  const [bettingElapsedMs, setBettingElapsedMs] = useState<number>(Infinity);

  useEffect(() => {
    if (roundState?.status !== "BETTING") {
      setBettingElapsedMs(Infinity);
      return;
    }

    const startMs = roundState.bettingStartedAt
      ? Date.parse(roundState.bettingStartedAt)
      : Date.now() + clockOffsetMs;

    const tickStaking = () => {
      const now = Date.now() + clockOffsetMs;
      setBettingElapsedMs(Math.max(0, now - startMs));
    };

    tickStaking();
    const timer = setInterval(tickStaking, 150);
    return () => clearInterval(timer);
  }, [roundState?.status, roundState?.bettingStartedAt, clockOffsetMs]);

  // In BETTING mode: simulated players trickle in progressively as they place their stakes.
  // In FLYING / CRASHED: all players are fully placed and active in flight / settlement.
  const visibleSimulatedPlayers = useMemo(() => {
    if (roundState?.status !== "BETTING" || bettingElapsedMs === Infinity) {
      return simulatedPlayers;
    }
    return simulatedPlayers.filter(
      (p) => (p.placeBetDelayMs ?? 0) <= bettingElapsedMs,
    );
  }, [roundState?.status, simulatedPlayers, bettingElapsedMs]);

  // Total active players count (placed simulated + real other players + active user bets)
  const totalActivePlayersCount =
    visibleSimulatedPlayers.length + otherRealPlayers.length + (currentUserBetsAsPlayers.length > 0 ? 1 : 0);

  // Total bets kobo (placed simulated stakes + other real player stakes + user active bet stakes)
  const totalBetsKobo =
    visibleSimulatedPlayers.reduce((sum, p) => sum + p.stakeKobo, 0) +
    otherRealPlayers.reduce((sum, p) => sum + p.stakeKobo, 0) +
    currentUserBetsAsPlayers.reduce((sum, p) => sum + p.stakeKobo, 0);

  // Right sidebar feed players: seamlessly merge and rank real players and active simulated players
  const feedPlayers = useMemo(() => {
    return mergeRealAndSimulatedPlayers(visibleSimulatedPlayers, allCurrentRealPlayers);
  }, [visibleSimulatedPlayers, allCurrentRealPlayers]);

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

  // Audio cues on round state transitions & live players simulation update
  useEffect(() => {
    if (!roundState) return;
    const prevStatus = prevRoundStatusRef.current;
    const currentStatus = roundState.status;

    if (prevStatus !== currentStatus) {
      if (currentStatus === "BETTING") {
        gameAudio.playGameStart();
        // New round betting opens: dynamic active players place fresh bets progressively (₦1,000 - ₦150,000)
        setSimulatedPlayers((prev) =>
          startNewRoundBets(prev, roundState.bettingWindowSeconds || 5),
        );
        if (Math.random() < 0.45) {
          setChatMessages((prev) => [...prev.slice(-35), createRoundEventChatMessage("BETTING")]);
        }
      } else if (currentStatus === "FLYING") {
        gameAudio.playTakeoff();
      } else if (currentStatus === "CRASHED") {
        gameAudio.playCrash();
        // Round crashed: settle winners and sort leaderboard by winnings
        const crashMult = roundState.crashMultiplierHundredths ?? liveMultiplierHundredths;
        setSimulatedPlayers((prev) => settleCrashedRound(prev, crashMult));
        if (crashMult < 160 && Math.random() < 0.65) {
          setChatMessages((prev) => [...prev.slice(-35), createRoundEventChatMessage("EARLY_CRASH")]);
        } else if (crashMult >= 350 && Math.random() < 0.65) {
          setChatMessages((prev) => [...prev.slice(-35), createRoundEventChatMessage("HIGH_CRASH")]);
        }
      }
      prevRoundStatusRef.current = currentStatus;
    }
  }, [roundState?.status, roundState?.crashMultiplierHundredths, liveMultiplierHundredths]);

  // Live cashouts simulation during FLYING as multiplier rises
  useEffect(() => {
    if (roundState?.status !== "FLYING") {
      lastCheckedMultiplierHundredthsRef.current = 100;
      return;
    }

    if (liveMultiplierHundredths - lastCheckedMultiplierHundredthsRef.current >= 5) {
      lastCheckedMultiplierHundredthsRef.current = liveMultiplierHundredths;
      setSimulatedPlayers((prev) => {
        const { players, hasNewCashouts } = updateFlyingCashouts(
          prev,
          liveMultiplierHundredths,
          roundState.crashMultiplierHundredths ?? crashMultiplierRef.current,
        );
        return hasNewCashouts ? players : prev;
      });
    }
  }, [roundState?.status, roundState?.crashMultiplierHundredths, liveMultiplierHundredths]);

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
          error instanceof CagedCashoutError && error.code === "TOO_LATE_ROUND_CRASHED";
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

  const sendChatMessage = (textToSend: string) => {
    const trimmed = textToSend.trim();
    if (!trimmed) return;
    const newMsg: CagedChatMessage = {
      id: `chat_user_${Date.now()}`,
      username: "You",
      avatarColor: "#10b981",
      text: trimmed,
      time: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
      isCurrentUser: true,
      badge: "YOU",
    };
    setChatMessages((prev) => [...prev.slice(-35), newMsg]);
    setChatInput("");
    setHasUnreadMessages(false);
    setTimeout(() => {
      chatMessagesEndRef.current?.scrollIntoView?.({ behavior: "smooth" });
    }, 40);
  };

  const handleSendChat = (e: React.FormEvent) => {
    e.preventDefault();
    sendChatMessage(chatInput);
  };

  const handleEmojiClick = (emoji: string) => {
    sendChatMessage(emoji);
  };

  const handleChatScroll = () => {
    const el = chatContainerRef.current;
    if (!el) return;
    const isAtBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 50;
    if (isAtBottom && hasUnreadMessages) {
      setHasUnreadMessages(false);
    }
  };

  const scrollToBottom = () => {
    chatMessagesEndRef.current?.scrollIntoView?.({ behavior: "smooth" });
    setHasUnreadMessages(false);
  };

  // Auto-scroll when new messages arrive if near bottom, else flag unread
  useEffect(() => {
    const el = chatContainerRef.current;
    if (!el) return;
    const isAtBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 70;
    if (isAtBottom) {
      chatMessagesEndRef.current?.scrollIntoView?.({ behavior: "smooth" });
    } else {
      setHasUnreadMessages(true);
    }
  }, [chatMessages.length]);

  // Jump to bottom when user switches to chat tab
  useEffect(() => {
    if (feedTab === "chat") {
      scrollToBottom();
    }
  }, [feedTab]);

  // Periodic subtle organic chatter so the room stays lively
  useEffect(() => {
    const timer = setInterval(() => {
      if (Math.random() < 0.4) {
        setChatMessages((prev) => [...prev.slice(-35), createOrganicChatMessage()]);
      }
    }, 10000);
    return () => clearInterval(timer);
  }, []);

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
            <span className={styles.soundBtnText}>{isMuted ? "Muted" : "Sound"}</span>
          </button>

          <button className={styles.provablyFairBtn} onClick={() => setShowFairnessModal(true)} aria-label="View provably fair verification">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
            </svg>
            <span className={styles.provablyFairBtnText}>Provably Fair</span>
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
            <span className={styles.exitBtnText}>Exit Game</span>
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

            <Caged3DStage
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
                <span className={styles.metricValue}>{totalActivePlayersCount}</span>
              </div>
              <div className={styles.metricBlock}>
                <span className={styles.metricTitle}>Total Bets</span>
                <span className={`${styles.metricValue} ${styles.highlight}`}>
                  {formatNaira(totalBetsKobo)}
                </span>
              </div>
            </div>
          </div>

          <div className={styles.mobileAccordionWrapper}>
            <button
              type="button"
              className={styles.mobileAccordionToggle}
              onClick={() => setIsMobileFeedExpanded((prev) => !prev)}
              aria-expanded={isMobileFeedExpanded}
              aria-controls="caged-mobile-feed-body"
            >
              <div className={styles.accordionHeaderLeft}>
                <span className={styles.accordionLivePulse} />
                <span className={styles.accordionTitle}>
                  {feedTab === "players" ? "👥 Live Players" : "💬 Live Chat"} ({feedTab === "players" ? totalActivePlayersCount : onlineUsersCount})
                </span>
              </div>
              <div className={styles.accordionHeaderRight}>
                <span className={styles.accordionActionHint}>
                  {isMobileFeedExpanded ? "Collapse" : "Tap to view"}
                </span>
                <svg
                  className={`${styles.accordionChevron} ${isMobileFeedExpanded ? styles.accordionChevronOpen : ""}`}
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2.5"
                >
                  <polyline points="6 9 12 15 18 9" />
                </svg>
              </div>
            </button>

            <div
              id="caged-mobile-feed-body"
              className={`${styles.mobileAccordionBody} ${isMobileFeedExpanded ? styles.mobileAccordionBodyOpen : ""}`}
            >
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
                <div className={styles.feedSubHeader}>
                  <div className={styles.feedLiveStatus}>
                    <span className={styles.livePulseDot} />
                    <span>{totalActivePlayersCount} Live Players</span>
                  </div>
                  <span className={styles.feedStakeRange}>₦1k – ₦150k Stakes</span>
                </div>

                {feedPlayers.map((player, idx) => {
                  const isWinner = player.cashedOut && player.payoutKobo > 0;
                  const isLost = player.lost || (roundState.status === "CRASHED" && !player.cashedOut);
                  const isFlying = roundState.status === "FLYING" && !player.cashedOut;
                  const isTop3 = idx < 3;

                  const rowClass = `${styles.playerRow} ${
                    isWinner
                      ? styles.playerRowCashedOut
                      : isLost
                      ? styles.playerRowLost
                      : isFlying
                      ? styles.playerRowFlying
                      : ""
                  } ${player.isCurrentUser ? styles.leaderboardRowCurrentUser : ""} ${
                    player.isRealPlayer && !player.isCurrentUser ? styles.leaderboardRowReal : ""
                  }`;

                  return (
                    <div key={player.id} className={rowClass}>
                      <div className={styles.playerLeft}>
                        <span className={`${styles.playerRank} ${isTop3 ? styles.playerRankTop : ""}`}>
                          {isTop3 ? (idx === 0 ? "🥇" : idx === 1 ? "🥈" : "🥉") : `#${idx + 1}`}
                        </span>
                        <div
                          className={styles.playerAvatar}
                          style={{
                            background:
                              player.avatarColor ||
                              AVATAR_GRADIENTS[idx % AVATAR_GRADIENTS.length],
                          }}
                          aria-hidden="true"
                        >
                          <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                          </svg>
                        </div>
                        <div className={styles.playerUserMeta}>
                          <span className={styles.playerName}>{player.username}</span>
                          {player.isCurrentUser && <span className={styles.realPlayerBadge}>YOU</span>}
                          {player.isRealPlayer && !player.isCurrentUser && (
                            <span className={styles.realPlayerBadge} style={{ background: "#3b82f6", color: "#fff" }}>
                              LIVE
                            </span>
                          )}
                        </div>
                      </div>

                      <div className={styles.playerRight}>
                        {isWinner ? (
                          <>
                            <span className={styles.playerMultiplierBadge}>
                              ✓ {((player.cashedOutAtMultiplierHundredths ?? Math.round(player.targetMultiplier * 100)) / 100).toFixed(2)}×
                            </span>
                            <span className={styles.playerWinLabel}>+{formatNaira(player.payoutKobo)}</span>
                          </>
                        ) : isLost ? (
                          <>
                            <span className={styles.playerCrashedBadge}>💥 Crashed</span>
                            <span className={styles.playerLostLabel}>
                              -₦{(player.stakeKobo / 100).toLocaleString("en-NG", { minimumFractionDigits: 2 })}
                            </span>
                          </>
                        ) : isFlying ? (
                          <>
                            <span className={styles.liveFlyingPillSmall}>
                              <span className={styles.flyingDot} />
                              In Play
                            </span>
                            <span className={styles.playerFlyingLabel}>
                              {formatNaira(Math.round((player.stakeKobo * liveMultiplierHundredths) / 100))}
                            </span>
                          </>
                        ) : (
                          <>
                            <span className={styles.betPlacedPillSmall}>Bet Placed</span>
                            <span className={styles.playerStakeText} data-testid="player-stake">
                              {formatNaira(player.stakeKobo)}
                            </span>
                          </>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className={styles.chatWrapper}>
                <div className={styles.chatSubHeader}>
                  <div className={styles.chatOnlineBadge}>
                    <span className={styles.chatOnlineDot} />
                    <span>{onlineUsersCount} Online</span>
                  </div>
                  <span className={styles.chatLoungeRoom}>Live Room • English</span>
                </div>

                <div
                  ref={chatContainerRef}
                  onScroll={handleChatScroll}
                  className={styles.chatMessagesList}
                >
                  {chatMessages.map((msg) => {
                    const isCurrentUser = msg.isCurrentUser || msg.username === "You";
                    const itemClass = `${styles.chatMessageItem} ${
                      isCurrentUser ? styles.chatCurrentUser : ""
                    } ${msg.isBigWin ? styles.bigWin : ""} ${
                      msg.isSystem ? styles.systemNotice : ""
                    }`;

                    return (
                      <div key={msg.id} className={itemClass}>
                        <div
                          className={styles.chatAvatar}
                          style={{
                            background: msg.avatarColor || (isCurrentUser ? "#10b981" : "#047857"),
                          }}
                          aria-hidden="true"
                        >
                          {isCurrentUser ? "Y" : msg.username.charAt(0).toUpperCase()}
                        </div>
                        <div className={styles.chatBody}>
                          <div className={styles.chatHeader}>
                            <span className={styles.chatUser}>
                              {isCurrentUser ? "You" : msg.username}
                            </span>
                            {isCurrentUser && (
                              <span className={`${styles.chatUserRoleBadge} ${styles.you}`}>YOU</span>
                            )}
                            {msg.isBigWin && (
                              <span className={`${styles.chatUserRoleBadge} ${styles.bigWin}`}>WINNER</span>
                            )}
                            <span className={styles.chatTime}>{msg.time}</span>
                          </div>
                          <span className={styles.chatText}>{msg.text}</span>
                        </div>
                      </div>
                    );
                  })}
                  <div ref={chatMessagesEndRef} />
                </div>

                {hasUnreadMessages && (
                  <button
                    type="button"
                    className={styles.chatScrollBottomBtn}
                    onClick={scrollToBottom}
                    aria-label="Scroll to newest messages"
                  >
                    ↓ New messages
                  </button>
                )}

                <div className={styles.chatEmojiBar}>
                  {CHAT_REACTION_EMOJIS.map((emoji) => (
                    <button
                      key={emoji}
                      type="button"
                      className={styles.chatEmojiBtn}
                      onClick={() => handleEmojiClick(emoji)}
                      title={`Send ${emoji}`}
                      aria-label={`Send ${emoji}`}
                    >
                      {emoji}
                    </button>
                  ))}
                </div>

                <form onSubmit={handleSendChat} className={styles.chatInputRow}>
                  <input
                    type="text"
                    placeholder={`Chat with ${onlineUsersCount} players...`}
                    value={chatInput}
                    onChange={(e) => setChatInput(e.target.value)}
                    className={styles.chatInput}
                    maxLength={120}
                  />
                  <button type="submit" className={styles.chatSendButton}>
                    Send
                  </button>
                </form>
              </div>
            )}
              </div>
            </div>
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

export const BirdEscapeGameFlow = CagedGameFlow;
