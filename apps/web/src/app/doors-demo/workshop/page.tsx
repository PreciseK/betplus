"use client";

import { useCallback, useMemo, useState } from "react";
import Vault3D from "../Vault3D";
import s from "../doors.module.css";

export default function Workshop() {
  const [id, setId] = useState(1);
  const [ready, setReady] = useState(false);
  const [failed, setFailed] = useState(false);
  const onReady = useCallback(() => setReady(true), []);
  const onUnavailable = useCallback(() => setFailed(true), []);
  const state = useMemo(() => ({ id, opened: [0, 1, 2], prizes: [3, 5, 2] }), [id]);
  return <main className={`${s.page} ${s.workshop}`}>
    <header className={s.workshopHeader}><a href="/doors-demo/">← Back to Doors</a><span>BLENDER ASSET WORKSHOP</span></header>
    <div className={s.workshopTitle}><span>FIRST 3D SLICE</span><h1>Inside the compartment.</h1><p>Three Blender treasures. Working locks, bolts, and hinges. No stake required.</p></div>
    <div className={s.showcase}>{!failed && <Vault3D showcase state={state} onReady={onReady} onUnavailable={onUnavailable} />}{failed && <p>3D rendering is unavailable in this browser. The illustrated game remains available.</p>}</div>
    <div className={s.showcaseLabels}><div><b>Gold bullion</b><span>3× your stake</span></div><div><b>Treasure chest</b><span>5× your stake</span></div><div><b>Multiplier plaque</b><span>2× your stake</span></div></div>
    <button className={`${s.start} ${s.workshopReplay}`} disabled={!ready || failed} onClick={() => setId(value => value + 1)}><span>{ready ? "REPLAY REVEAL" : "LOADING BLENDER MODELS"}</span><small>LOCK · BOLTS · DOOR · TREASURE</small></button>
    <p className={s.workshopNote}>Prototype materials and lighting. The vault room remains a backdrop; the compartments and treasures are real-time 3D.</p>
  </main>;
}
