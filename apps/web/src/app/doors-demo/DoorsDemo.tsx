"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ApiError, type DoorsGateway, type DoorsContext, type DoorsRoundResponse } from "@betplus/api-client";
import Vault3D from "./Vault3D";
import WinPopup from "./WinPopup";
import { BOARD_SIZE, makeBoard, MAX_PICKS, MAX_STAKE_NAIRA, MIN_STAKE_NAIRA, naira, parseStake, payout, reveal, ROUND_SECONDS, type Round } from "./rules";
import s from "./doors.module.css";

const STAKE_STEP_NAIRA = 50;
const secureRandom = () => crypto.getRandomValues(new Uint32Array(1))[0] / 4294967296;

type PendingOperation = ({ kind: "start"; key: string; stakeKobo: number; seconds: number } | { kind: "open"; key: string; reference: string; index: number }) & { retried?: boolean };

export default function DoorsDemo({ gateway }: { gateway?: DoorsGateway }) {
  const [balance, setBalance] = useState(gateway ? 0 : 10000);
  const [spendable, setSpendable] = useState(gateway ? 0 : 10000);
  const [context, setContext] = useState<DoorsContext | null>(null);
  const [serverRound, setServerRound] = useState<DoorsRoundResponse | null>(null);
  const [busy, setBusy] = useState(false);
  const [requestError, setRequestError] = useState("");
  const pending = useRef<PendingOperation | null>(null);
  // Mirrors pending.current for rendering; the ref stays the source of truth inside handlers.
  const [hasPending, setHasPending] = useState(false);
  const setPending = (operation: PendingOperation | null) => { pending.current = operation; setHasPending(operation !== null); };
  const inFlight = useRef(false);
  const expiryRead = useRef(false);
  const [stakeInput, setStakeInput] = useState(String(MIN_STAKE_NAIRA));
  const [round, setRound] = useState<Round | null>(null);
  const current = useRef<Round | null>(null);
  const [remaining, setRemaining] = useState(ROUND_SECONDS);
  const [sound, setSound] = useState(true);
  const [help, setHelp] = useState(false);
  const [showWin, setShowWin] = useState(false);
  const [intro, setIntro] = useState<"welcome" | "playing" | "done">("welcome");
  const [videoError, setVideoError] = useState(false);
  const [graphics, setGraphics] = useState<"3d" | "illustrated">("3d");
  const [ready, setReady] = useState(false);
  const onReady = useCallback(() => setReady(true), []);
  const onUnavailable = useCallback(() => { setGraphics("illustrated"); setReady(true); }, []);
  const vaultState = useMemo(() => round ? { id: round.serverReference ?? round.deadline, opened: round.opened, prizes: round.board } : null, [round]);
  const audio = useRef<AudioContext | null>(null);
  const playing = !!round && !round.settled;
  const total = gateway ? (serverRound?.gross_prize_kobo ?? 0) / 100 : round ? payout(round) : 0;
  const fundsAvailable = gateway ? spendable : balance;
  const canStart = !gateway || !!context?.available;
  const minStake = gateway && context?.min_stake_kobo ? context.min_stake_kobo / 100 : MIN_STAKE_NAIRA;
  const maxStake = gateway && context?.max_stake_kobo ? context.max_stake_kobo / 100 : MAX_STAKE_NAIRA;
  const parsedStake = parseStake(stakeInput, minStake, maxStake);
  const stake = parsedStake ?? minStake;
  const stakeLocked = playing || busy || hasPending;
  const clampStake = (amount: number) => String(Math.min(maxStake, Math.max(minStake, amount)));

  const applyServer = useCallback((value: DoorsRoundResponse) => {
    const board = Array.from({ length: value.board_size }, () => 0);
    value.opened.forEach(pick => { board[pick.index] = pick.multiplier; });
    const next: Round = { board, opened: value.opened.map(pick => pick.index),
      deadline: performance.now() + value.remaining_ms, stake: value.stake_kobo / 100,
      settled: value.status === "settled", serverReference: value.reference };
    current.current = next;
    setRound(next); setServerRound(value); setStakeInput(String(value.stake_kobo / 100));
    setRemaining(value.remaining_ms / 1000);
    setBalance((value.wallet.play_balance_kobo + value.wallet.bonus_balance_kobo + value.wallet.winnings_balance_kobo) / 100);
    setSpendable((value.wallet.play_balance_kobo + value.wallet.bonus_balance_kobo) / 100);
    expiryRead.current = false;
  }, []);

  const loadLive = useCallback(async () => {
    if (!gateway) return;
    setBusy(true); setRequestError("");
    try {
      const data = await gateway.loadGame();
      setContext(data);
      setBalance((data.wallet.play_balance_kobo + data.wallet.bonus_balance_kobo + data.wallet.winnings_balance_kobo) / 100);
      setSpendable((data.wallet.play_balance_kobo + data.wallet.bonus_balance_kobo) / 100);
      const restored = data.active_round ?? data.latest_round;
      if (restored) { applyServer(restored); if (restored.status === "in_play") setIntro("done"); }
    } catch (error) { setRequestError(error instanceof Error ? error.message : "Could not load Doors. Please retry."); }
    finally { setBusy(false); }
  }, [gateway, applyServer]);

  useEffect(() => { void loadLive(); }, [loadLive]);

  async function runPending() {
    if (!gateway || !pending.current || inFlight.current) return;
    const operation = pending.current;
    inFlight.current = true; setBusy(true); setRequestError("");
    try {
      const posted = operation.kind === "start"
        ? await gateway.startRound({ stakeKobo: operation.stakeKobo, durationSeconds: operation.seconds, idempotencyKey: operation.key })
        : await gateway.openDoor(operation.reference, operation.index, operation.key);
      // A retried key may replay an older snapshot; only then pay for a second read.
      const value = operation.retried ? await gateway.readRound(posted.reference) : posted;
      applyServer(value);
      if (operation.kind === "open") tone((value.opened.find(pick => pick.index === operation.index)?.multiplier ?? 0) > 0);
      setPending(null);
    } catch (error) {
      setRequestError(error instanceof Error ? error.message : "Connection interrupted. Retry to recover this round.");
      operation.retried = true;
      // Transport failures retain the exact key/payload. A definitive client rejection can be corrected.
      if (error instanceof ApiError && error.status >= 400 && error.status < 500 && error.status !== 409) setPending(null);
    } finally { inFlight.current = false; setBusy(false); }
  }

  async function refreshLiveRound() {
    if (!gateway || !current.current?.serverReference || expiryRead.current || inFlight.current) return;
    expiryRead.current = true;
    try { applyServer(await gateway.readRound(current.current.serverReference)); setRequestError(""); }
    catch { setRequestError("Could not confirm this round. Reconnect to recover its server result."); }
  }

  useEffect(() => {
    if (!round?.settled || !total || intro !== "done") return;
    const timeout = window.setTimeout(() => setShowWin(true), 850);
    return () => window.clearTimeout(timeout);
  }, [round, total, intro]);

  function tone(win: boolean) {
    if (!sound) return;
    try {
      const ctx = audio.current ?? new AudioContext();
      audio.current = ctx;
      void ctx.resume();
      [0, 0.08, ...(win ? [0.17] : [])].forEach((delay, i) => {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = win ? "sine" : "triangle";
        oscillator.frequency.value = win ? [523, 659, 784][i] : [110, 75][i];
        gain.gain.setValueAtTime(0.0001, ctx.currentTime + delay);
        gain.gain.exponentialRampToValueAtTime(0.1, ctx.currentTime + delay + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + delay + 0.2);
        oscillator.connect(gain).connect(ctx.destination);
        oscillator.start(ctx.currentTime + delay);
        oscillator.stop(ctx.currentTime + delay + 0.22);
      });
    } catch { /* Sound is optional when the browser does not support Web Audio. */ }
  }

  function settle(active: Round) {
    if (current.current?.settled || current.current?.deadline !== active.deadline) return;
    const finished = { ...active, settled: true };
    current.current = finished;
    setRound(finished);
    setRemaining(Math.max(0, (active.deadline - performance.now()) / 1000));
    setBalance(value => value + payout(active));
  }

  useEffect(() => {
    if (!playing) return;
    const interval = window.setInterval(() => {
      const active = current.current;
      if (!active || active.settled) return;
      const left = Math.max(0, (active.deadline - performance.now()) / 1000);
      setRemaining(left);
      if (left <= 0) { if (gateway) void refreshLiveRound(); else settle(active); }
    }, 40);
    return () => window.clearInterval(interval);
  }, [playing, gateway]);

  function start() {
    if (intro !== "done" || !ready || !canStart || busy || pending.current || current.current && !current.current.settled || parsedStake === null || fundsAvailable < stake) return;
    if (gateway) {
      setShowWin(false);
      setPending({ kind: "start", key: crypto.randomUUID(), stakeKobo: stake * 100, seconds: ROUND_SECONDS });
      void runPending();
      return;
    }
    const next = { board: makeBoard(secureRandom), opened: [], deadline: performance.now() + ROUND_SECONDS * 1000, stake, settled: false };
    setShowWin(false);
    current.current = next;
    setRemaining(ROUND_SECONDS);
    setBalance(value => value - stake);
    setRound(next);
  }

  function openDoor(index: number) {
    const active = current.current;
    if (!active || active.settled || active.opened.includes(index) || busy || pending.current) return;
    if (gateway && active.serverReference) {
      setPending({ kind: "open", key: crypto.randomUUID(), reference: active.serverReference, index });
      void runPending();
      return;
    }
    if (performance.now() >= active.deadline) { settle(active); return; }
    const next = reveal(active, index, performance.now());
    if (next === active) return;
    tone(next.board[index] > 0);
    if (next.settled) settle(next);
    else { current.current = next; setRound(next); }
  }

  return <main className={s.page}>
    {intro !== "done" && <section className={s.cinematic} aria-label="Vault cinematic">
      {intro === "playing" && <video className={s.introVideo} src="/assets/doors/vault-intro.mp4" autoPlay playsInline muted={!sound} onEnded={() => setIntro("done")} onError={() => setVideoError(true)} />}
      {(intro === "welcome" || videoError) && <div className={s.introWelcome}><span>BETPLUS PRESENTS</span><h2>DOORS</h2><p>{videoError ? "The cinematic could not load. Your vault is ready below." : "An invitation to Big Man’s private vault."}</p>{!videoError && <button className={s.start} onClick={() => setIntro("playing")}><span>Enter Game</span><small>{ROUND_SECONDS} SECONDS · ENTER THE VAULT</small></button>}</div>}
      <div className={s.introActions}><button onClick={() => setSound(!sound)} aria-pressed={sound}>Sound {sound ? "on" : "off"}</button><button onClick={() => setIntro("done")}>Skip to vault →</button></div>
    </section>}
    <div className={s.gameContent} inert={intro !== "done"}>
    <header className={s.header}>
      <div className={s.logo}><h1>DOORS</h1><span>THE BIG MAN’S VAULT</span></div>
      <div className={s.hudStake}><span className={s.label}>BET AMOUNT</span><div className={s.stepper}><button disabled={stakeLocked || stake <= minStake} onClick={() => setStakeInput(clampStake(stake - STAKE_STEP_NAIRA))} aria-label="Decrease stake">−</button><label className={s.stakeField}><span aria-hidden="true">₦</span><input aria-label={`Stake in naira, ${naira(minStake)} to ${naira(maxStake)}`} aria-invalid={parsedStake === null} inputMode="numeric" value={stakeInput} disabled={stakeLocked} onChange={event => setStakeInput(event.target.value.replace(/\D/g, ""))} onBlur={() => { if (parsedStake === null) setStakeInput(clampStake(Number(stakeInput) || minStake)); }} /></label><button disabled={stakeLocked || stake >= maxStake} onClick={() => setStakeInput(clampStake(stake + STAKE_STEP_NAIRA))} aria-label="Increase stake">+</button></div>{parsedStake === null && <small className={s.stakeHint} role="alert">Enter {naira(minStake)} – {naira(maxStake)}</small>}</div>
      <div className={s.picks}><span className={s.label}>PICKS LEFT</span><strong>{MAX_PICKS - (round?.opened.length ?? 0)}<small> / {MAX_PICKS}</small></strong></div>
      <div className={`${s.clock} ${playing && remaining < 3 ? s.urgent : ""}`} aria-label="Time remaining"><strong>{(round ? remaining : ROUND_SECONDS).toFixed(1)}</strong><span>SECONDS</span></div>
      <div className={s.winnings}><span className={s.label}>WINNINGS</span><strong>{naira(total)}</strong></div>
      <div className={s.balance}><span className={s.label}>BALANCE</span><strong>{naira(balance)}</strong></div>
    </header>
    <section className={s.scene} aria-label="Vault game">
      <div className={s.sceneHeading}><span><i /> {playing ? "VAULT UNLOCKED · CHOOSE YOUR DOORS" : round ? "ROUND COMPLETE" : "TWENTY DOORS. FIVE CHANCES TO DISCOVER."}</span><span>VAULT № 001</span></div>
      {gateway && <div className={s.connectionInfo}>
        <span>Account wallet · Available to stake {naira(spendable)}</span>
        {context && !context.available && <p>Doors is awaiting activation or a published configuration. Existing rounds can still finish.</p>}
        {requestError && <p role="alert">{requestError}</p>}
        {requestError && <button disabled={busy} onClick={() => { if (pending.current) void runPending(); else void loadLive(); }}>Reconnect / retry request</button>}
      </div>}
      <div className={s.cabinet}>
        <div className={`${s.board} ${graphics === "3d" && ready ? s.board3d : ""}`}>
          {graphics === "3d" && <Vault3D state={vaultState} onReady={onReady} onUnavailable={onUnavailable} />}
          {Array.from({ length: BOARD_SIZE }, (_, i) => {
            const opened = round?.opened.includes(i) ?? false;
            const prize = opened && round ? round.board[i] : 0;
            return <button key={i} className={`${s.door} ${opened ? s.opened : ""} ${prize > 0 ? s.prize : ""} ${prize === 2 ? s.bigPrize : ""}`} disabled={!playing || opened || busy || hasPending || remaining <= 0} onClick={() => openDoor(i)} aria-label={opened ? `Door ${i + 1}: ${prize > 0 ? `${prize} times stake` : "empty"}` : `Open door ${i + 1}`}>
              <span className={s.compartment}>{opened && <>{prize > 0 ? <><span className={s.sparkles}>✧ · ✦ · ✧</span>{prize === 5 ? <span className={s.fallbackChest}><i /><b>✦</b></span> : prize === 2 ? <span className={s.multiplierCard}><b>×{prize}</b></span> : <span className={s.goldBars}><i /><i /><i /><i /><i /><i /></span>}<strong>{naira(prize * (round?.stake ?? stake))} </strong>{prize !== 2 && <span className={s.prizeMultiplier}>{prize}× STAKE</span>}</> : <><span className={s.emptyMark}>◇</span><b>EMPTY VAULT</b></>}</>}</span>
              <span className={s.doorFace}><span className={s.doorNumber}>{String(i + 1).padStart(2, "0")}</span><span className={s.knockHint}>KNOCK</span></span>
            </button>;
          })}
        </div>
        <div className={s.console}>
          <div className={s.consoleSide}><span className={s.label}>ROUND LENGTH</span><div className={s.options}><span className={s.selected}>{ROUND_SECONDS}s <small>CLASSIC</small></span></div></div>
          <button className={s.start} onClick={start} disabled={!ready || intro !== "done" || playing || busy || hasPending || !canStart || parsedStake === null || fundsAvailable < stake}><span>{!ready || (gateway && !context && busy) ? "PREPARING THE VAULT" : busy ? "CONFIRMING ROUND" : gateway && !canStart ? "ROUNDS PAUSED" : playing ? "CHOOSE YOUR DOORS" : round ? "PLAY AGAIN" : "UNLOCK THE VAULT"}</span><small>{playing ? `${MAX_PICKS - (round?.opened.length ?? 0)} PICKS REMAINING` : `${naira(stake)} · START / KNOCK`}</small></button>
          <div className={s.consoleSide}><span className={s.label}>YOUR FIVE KEYS</span><div className={s.keys} aria-label={`${MAX_PICKS - (round?.opened.length ?? 0)} picks remaining`}>{Array.from({length:MAX_PICKS},(_,i)=><span key={i} className={i < (round?.opened.length ?? 0) ? s.usedKey : ""}>⚿</span>)}</div></div>
        </div>
        <div className={s.message} role="status" aria-live="polite">{playing ? "Find one treasure to win. You have up to five attempts." : round ? total ? (gateway ? `✦ You won ${naira(total)}. Net winnings ${naira((serverRound?.net_prize_kobo ?? 0) / 100)} added to your balance.` : `✦ You won ${naira(total)}. Added to your balance.`) : "No treasure this time. A fresh vault awaits." : "Choose your stake. Find a treasure within five attempts to win."}</div>
        {!playing && <a className={s.workshopLink} href="/doors-demo/workshop/">Inspect the 3D treasure collection →</a>}
        {!gateway && balance < stake && !playing && <button className={s.reset} onClick={() => setBalance(10000)}>Reset demo balance</button>}
      </div>
    </section>
    <footer className={s.footer}><a href="/">betplus<span>✦</span></a><span className={s.demo}>{gateway ? "ACCOUNT WALLET · NGN" : "PLAYABLE DEMO · AMOUNTS IN NAIRA"}</span><span className={s.mobileBalance}>Balance: {naira(balance)}</span><div><button onClick={() => setHelp(!help)} aria-expanded={help}>How to play</button><button disabled={stakeLocked} onClick={() => { setIntro("welcome"); setVideoError(false); }}>Replay intro</button><label className={s.graphicsLabel}>Graphics <select aria-label="Graphics" value={graphics} disabled={stakeLocked} onChange={event => { const next = event.target.value as "3d" | "illustrated"; setReady(next === "illustrated"); setGraphics(next); }}><option value="3d">3D vault</option><option value="illustrated">Illustrated</option></select></label><button onClick={() => setSound(!sound)} aria-pressed={sound}>♪ Sound {sound ? "on" : "off"}</button></div></footer>
    {help && <div className={s.help}><h2>Inside the vault</h2><p>Choose a stake from {naira(minStake)} to {naira(maxStake)}, then unlock the vault. You have up to five attempts in {ROUND_SECONDS} seconds. The first treasure ends the round immediately: gold bars pay 3× your stake, a treasure chest pays 5×, and a multiplier card pays 2×. Empty doors use one attempt. Five empty doors or an expired timer end the round without a reward.</p><p>{gateway ? "The server places one treasure among twenty doors at the start of each round. Your first treasure ends the round. Payouts shown are gross; withholding tax and any bonus principal reclaim are itemised on settlement. Refreshing resumes your account round." : "One treasure is hidden each round, with the same odds as the real game. Amounts are in naira. This prototype uses a simulated balance with no deposits or withdrawals. Refreshing resets the balance."}</p><button onClick={() => setHelp(false)}>Got it</button></div>}
    </div>
    {showWin && round && <WinPopup amount={total} stake={round.stake} multiplier={total / round.stake} balance={balance} onClose={() => setShowWin(false)} netAmount={gateway ? (serverRound?.net_prize_kobo ?? 0) / 100 : undefined} taxAmount={gateway ? (serverRound?.tax_withheld_kobo ?? 0) / 100 : undefined} onReplay={() => { setShowWin(false); start(); }} />}
  </main>;
}
