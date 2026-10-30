"use client";

import { useEffect, useRef, useState, type CSSProperties, type TouchEvent } from "react";
import Image from "next/image";
import Link from "next/link";
import dynamic from "next/dynamic";
import styles from "./concept.module.css";

const CagedScene = dynamic(() => import("@/components/games/CagedGameFlow/Caged3DStage").then((module) => module.Caged3DStage), { ssr: false });

const GAMES = [
  { id: "blackred", name: "BlackRed", category: "CARD PREDICTION", line: "Make your", emphasis: "colour call.", description: "Black or red? A little instinct. A turn of the cards.", accent: "#ff5547", number: "01", symbol: "♦", detail: "Predict the colours of one to five cards. Review your selections, stake and possible return before confirming a ticket." },
  { id: "heritage", name: "Heritage", category: "ROYAL REGALIA · INSTANT WIN", line: "A little culture.", emphasis: "A royal reveal.", description: "Nine tiles. Five choices. Discover the regalia within.", accent: "#e8b969", number: "02", symbol: "♛", detail: "Choose five positions on a nine-tile board. Reveal the regalia and check the game's rules for qualifying matches and payouts." },
  { id: "caged", name: "Caged", category: "LIVE CRASH", line: "Catch the", emphasis: "right moment.", description: "Watch it rise. Make your move before the cage drops.", accent: "#b7e2ca", number: "03", symbol: "↗", detail: "Place up to two bets in a round. The multiplier rises until the cage drops. A cash-out must be accepted before the round ends; otherwise the stake is lost." },
  { id: "doors", name: "Doors", category: "TREASURE HUNT", line: "Five chances.", emphasis: "One discovery.", description: "Enter Big Man’s vault. Your first treasure ends the round.", accent: "#e5c176", number: "04", symbol: "⚿", detail: "Choose a stake, then open up to five of twenty vault doors before your 5- or 10-second timer ends. Your first treasure ends the round: gold bars pay 3×, a chest 5×, and a multiplier card 2× your stake. Five empty doors or time running out means no prize. Payout multipliers are gross; applicable deductions and net winnings appear on settlement. Doors is available on the web; play is subject to game availability." },
] as const;

const REGALIA = ["ade", "ileke", "irukere", "agbada", "bata", "ileke", "irukere", "bata", "ade"];

export function HomepageConcept({ preview = true }: { preview?: boolean }) {
  const [selected, setSelected] = useState(0);
  const [flipped, setFlipped] = useState(false);
  const [tiles, setTiles] = useState<number[]>([]);
  const [flying, setFlying] = useState(false);
  const [autoRotate, setAutoRotate] = useState(true);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [dialogMode, setDialogMode] = useState<"play" | "guide" | "account" | "responsible">("guide");
  const dialogRef = useRef<HTMLDialogElement>(null);
  const stageRef = useRef<HTMLElement>(null);
  const touchStart = useRef<{ x: number; y: number } | null>(null);
  const game = GAMES[selected];

  useEffect(() => {
    if (!autoRotate || dialogOpen) return;
    let timer: number | undefined;
    const schedule = () => {
      window.clearTimeout(timer);
      if (document.hidden) return;
      timer = window.setTimeout(() => {
        setSelected((current) => (current + 1) % GAMES.length);
        setFlipped(false);
        setTiles([]);
        setFlying(false);
      }, 12_000);
    };
    schedule();
    document.addEventListener("visibilitychange", schedule);
    return () => {
      window.clearTimeout(timer);
      document.removeEventListener("visibilitychange", schedule);
    };
  }, [autoRotate, dialogOpen, selected, flipped, tiles.length, flying]);

  function choose(index: number) {
    setSelected((index + GAMES.length) % GAMES.length);
    setFlipped(false);
    setTiles([]);
    setFlying(false);
  }

  function openDialog(mode: typeof dialogMode) {
    setDialogMode(mode);
    setDialogOpen(true);
    dialogRef.current?.showModal();
  }

  function onSwipe(event: TouchEvent) {
    const start = touchStart.current;
    const end = event.changedTouches[0];
    if (start && Math.abs(end.clientX - start.x) > 70 && Math.abs(end.clientY - start.y) < 60) {
      choose(selected + (end.clientX < start.x ? 1 : -1));
    }
    touchStart.current = null;
  }

  return (
    <div className={styles.page} style={{ "--scene-accent": game.accent } as CSSProperties} data-game={game.id}>
      <a href="#concept-main" className={styles.skip}>Skip to game stage</a>
      {preview && <div className={styles.previewBar}><span><i /> HOMEPAGE CONCEPT</span><Link href="/">View main homepage ↗</Link></div>}
      <header className={styles.header}>
        <Link href={preview ? "/homepage-concept" : "/"} aria-label="Betplus home" className={styles.logo}><Image src="/assets/betplus-logo-white.png" alt="Betplus" width={167} height={60} priority unoptimized /></Link>
        <nav className={styles.nav} aria-label="Main navigation">
          <a href="#choose-game" className={styles.navActive}>Games</a>
          <Link href="/about" className={styles.aboutLink}>About Betplus</Link>
        </nav>
        <div className={styles.accountActions}>{preview ? <><button className={styles.signIn} onClick={() => openDialog("account")}>Sign in</button><button className={styles.join} onClick={() => openDialog("account")}>Join Betplus <span>↗</span></button></> : <><Link className={styles.signIn} href="/login">Sign in</Link><Link className={styles.join} href="/register">Join Betplus <span>↗</span></Link></>}</div>
      </header>

      <main id="concept-main">
        <section ref={stageRef} className={styles.stage} aria-label={`${game.name} game preview`}
          onTouchStart={(event) => { touchStart.current = { x: event.touches[0].clientX, y: event.touches[0].clientY }; }} onTouchEnd={onSwipe}>
          <div className={styles.sceneTexture} aria-hidden="true" />
          <div className={styles.sceneWord} aria-hidden="true">{game.name.toUpperCase()}</div>
          <div className={styles.copy} key={`copy-${game.id}`}>
            <div className={styles.gameLabel}><span>{game.number} /</span> {game.category}</div>
            <p className={styles.gameName}>{game.name}</p>
            <h1>{game.line}<br /><em>{game.emphasis}</em></h1>
            <p className={styles.description}>{game.description}</p>
            <div className={styles.actions}>{preview ? <button className={styles.play} onClick={() => openDialog("play")}>Play {game.name}<span aria-hidden="true">↗</span></button> : <Link className={styles.play} href={`/games/${game.id}`}>Play {game.name}<span aria-hidden="true">↗</span></Link>}<button className={styles.rules} onClick={() => openDialog("guide")}>How to play <span aria-hidden="true">↗</span></button></div>
            <p className={styles.age}>18+ only. Play for fun. Play within your limits.</p>
          </div>

          <div className={styles.art} key={`art-${game.id}`}>
            {game.id === "blackred" && <>
              <div className={styles.orbit} aria-hidden="true"><span>YOUR MOVE. YOUR MOMENT.</span></div>
              <div className={styles.cards}>
                <div className={`${styles.card} ${styles.blackCard}`} aria-hidden="true"><span>K<small>♣</small></span><b>♣</b><span>K<small>♣</small></span></div>
                <button className={`${styles.flipCard} ${flipped ? styles.flipped : ""}`} onClick={() => setFlipped(!flipped)} aria-label={flipped ? "Reset demonstration card" : "Flip demonstration card"} aria-pressed={flipped}>
                  <span className={styles.cardInner}>
                    <span className={`${styles.card} ${styles.cardBack}`}><span className={styles.backPattern}><Image src="/assets/betplus-mark.png" alt="" width={72} height={90} unoptimized /><span>YOUR MOVE</span></span></span>
                    <span className={`${styles.card} ${styles.redCard}`}><span>A<small>♦</small></span><b>♦</b><span>A<small>♦</small></span></span>
                  </span>
                </button>
              </div>
              <p className={styles.interactionHint}><span>↖</span> {flipped ? "Red. Tap to turn it back." : "Go on. Turn the card."}<small>Interactive demonstration · no stake</small></p>
              <span className={styles.floatingDiamond} aria-hidden="true">♦</span>
            </>}

            {game.id === "heritage" && <>
              <Image className={styles.monarch} src="/games/heritage/monarchs/yoruba_king_v2.png" alt="Yoruba monarch wearing royal regalia" width={768} height={1536} priority unoptimized />
              <div className={styles.tileBoard} aria-label="Regalia demonstration board">
                {REGALIA.map((item, index) => <button key={index} className={tiles.includes(index) ? styles.revealed : ""} aria-label={tiles.includes(index) ? `Tile ${index + 1}: ${item.replaceAll("-", " ")}` : `Reveal demonstration tile ${index + 1}`} aria-pressed={tiles.includes(index)} onClick={() => setTiles((current) => current.includes(index) ? current.filter((tile) => tile !== index) : [...current, index])}>
                  {tiles.includes(index) ? <Image src={`/games/heritage/regalia/yoruba_king/${item}_v1.png`} alt="" width={100} height={100} unoptimized /> : <span>♛</span>}
                </button>)}
              </div>
              <p className={styles.heritageHint}>Every tile tells a story.<small>Tap to explore · demonstration only</small></p>
            </>}

            {game.id === "caged" && <>
              <div className={styles.cageHalo} aria-hidden="true" />
              <div className={styles.cageScene}><CagedScene roundPhase={flying ? "flying" : "betting"} multiplier={flying ? 1.8 : 1} /></div>
              <button className={styles.release} onClick={() => setFlying(!flying)}>{flying ? "↺ Reset the scene" : "↗ Open the cage"}</button>
              <p className={styles.cageHint}>A moment changes everything.<small>Interactive artwork · no live round</small></p>
            </>}
            {game.id === "doors" && <>
              <div className={styles.vaultGlow} aria-hidden="true" />
              <Image className={styles.doorsHost} src="/assets/doors/big-man-thumbs-up.png" alt="Big Man, the host of Doors, wearing his black and gold suit" width={1107} height={1421} loading="eager" unoptimized />
              <div className={styles.vaultPreview}>
                <div className={styles.vaultPreviewTitle}><span>BIG MAN’S VAULT</span><span>{Math.max(0, 5 - tiles.length)} KEYS</span></div>
                <div className={styles.previewDoors} role="group" aria-label="Doors demonstration board">
                  {Array.from({ length: 20 }, (_, index) => {
                    const opened = tiles.includes(index);
                    const won = tiles.includes(6);
                    return <button key={index} disabled={opened || won || tiles.length >= 5} className={opened ? styles.previewDoorOpen : ""} aria-label={opened ? `Preview door ${index + 1}: ${index === 6 ? "gold bars, 3 times stake" : "empty"}` : `Open preview door ${index + 1}`} onClick={() => setTiles(current => current.length < 5 && !current.includes(6) ? [...current, index] : current)}>
                      <span>{opened ? index === 6 ? "3×" : "—" : String(index + 1).padStart(2, "0")}</span>
                    </button>;
                  })}
                </div>
                <div className={styles.previewPaytable}><span>GOLD <b>3×</b></span><span>CHEST <b>5×</b></span><span>CARD <b>2×</b></span></div>
              </div>
              <div className={styles.doorsHint}><p role="status">{tiles.includes(6) ? "Gold bars. That’s a 3× discovery." : tiles.length >= 5 ? "Five doors explored. Preview complete." : "A treasure is waiting behind a door."}</p><small>Tap to explore · demonstration only · no stake</small>{(tiles.includes(6) || tiles.length >= 5) && <button onClick={() => setTiles([])}>Reset vault preview ↺</button>}</div>
            </>}
          </div>
          <div className={styles.stageBottom}><span>FOUR GAMES. YOUR CALL.</span><div><button aria-label="Previous game" onClick={() => choose(selected - 1)}>←</button><button aria-label={autoRotate ? "Pause automatic game switching" : "Resume automatic game switching"} aria-pressed={!autoRotate} title={autoRotate ? "Games switch every 12 seconds. Pause switching." : "Resume switching every 12 seconds."} onClick={() => setAutoRotate((current) => !current)}>{autoRotate ? "Ⅱ" : "▶"}</button><span>{game.number}<i> / {String(GAMES.length).padStart(2, "0")}</i></span><button aria-label="Next game" onClick={() => choose(selected + 1)}>→</button></div><span className={styles.exploreLabel}>EXPLORE BELOW ↓</span></div>
        </section>

        <section className={styles.gamePicker} id="choose-game" aria-labelledby="picker-heading">
          <div className={styles.pickerIntro} id="games"><span>THE LINE-UP</span><h2 id="picker-heading">What’s your move?</h2></div>
          <div className={styles.choices} role="group" aria-label="Choose a featured game">
            {GAMES.map((item, index) => <button key={item.id} className={`${styles.choice} ${index === selected ? styles.chosen : ""}`} aria-label={`${item.name} ${item.category.split(" · ").at(-1)?.toLowerCase()}`} aria-pressed={index === selected} onClick={(event) => {
              choose(index);
              if (event.detail > 0 && window.matchMedia("(max-width: 700px)").matches) {
                stageRef.current?.scrollIntoView({ behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "instant" : "smooth", block: "start" });
              }
            }} style={{ "--item-accent": item.accent } as CSSProperties}>
              <span className={styles.choiceSymbol} aria-hidden="true">{item.symbol}</span><span className={styles.choiceText}><strong>{item.name}</strong><small>{item.category.split(" · ").at(-1)?.toLowerCase()}</small></span><span className={styles.choiceArrow} aria-hidden="true">{index === selected ? "↗" : "+"}</span>
            </button>)}
          </div>
        </section>
        <div className={styles.utility}><p>One Betplus account. Four games to explore.</p><div><span>Payments with <b>OPay</b></span><span className={styles.utilityDivider} /><span>Selected games on USSD <b>*7006#</b></span><button onClick={() => openDialog("guide")}>Get the game guide ↗</button></div></div>
      </main>
      <footer className={styles.footer}><span>© {new Date().getFullYear()} Betplus.</span><div>{!preview && <Link href="/login">Sign in</Link>}<Link href="/privacy-policy">Privacy</Link><Link href="/terms-and-conditions">Terms</Link>{preview ? <button onClick={() => openDialog("responsible")}>18+ · Play responsibly</button> : <Link href="/about#safe-play">18+ · Play responsibly</Link>}</div><span className={styles.footerTag}>YOUR MOVE. YOUR MOMENT.</span></footer>

      <dialog ref={dialogRef} className={styles.dialog} aria-labelledby="concept-dialog-title" onClose={() => setDialogOpen(false)}>
        <button className={styles.close} aria-label="Close dialog" onClick={() => dialogRef.current?.close()}>×</button>
        <span className={styles.dialogEyebrow}>{dialogMode === "guide" ? "GAME GUIDE" : "CONCEPT PREVIEW"}</span>
        <h2 id="concept-dialog-title">{dialogMode === "play" ? `Your move: ${game.name}.` : dialogMode === "guide" ? `How ${game.name} works.` : dialogMode === "responsible" ? "Keep play in perspective." : "Welcome to Betplus."}</h2>
        <p>{dialogMode === "play" ? "This is where the selected game would open. For this preview, you can explore the design without signing in or placing a stake." : dialogMode === "guide" ? game.detail : dialogMode === "responsible" ? "For adults aged 18 and over. Set limits you can afford, take breaks and never chase losses. In the finished homepage, this link will open the responsible-play information page." : "Sign-in and registration will connect to the existing account flows after this concept is approved."}</p>
        {dialogMode === "guide" && <p className={styles.dialogNote}>The stage interactions are visual demonstrations. <Link href={`/about#${game.id}`}>Read more about {game.name}</Link>.</p>}
        <button className={styles.play} onClick={() => dialogRef.current?.close()}>Back to exploring <span>↗</span></button>
      </dialog>
    </div>
  );
}
