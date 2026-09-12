"use client";

const SOUNDS = {
  gameStart:  "/sounds/birdescape/game-start.mp3",
  gameOver:   "/sounds/birdescape/game-over.mp3",
  musicLoop1: "/sounds/birdescape/music-loop-1.mp3", // During game (FLYING)
  musicLoop2: "/sounds/birdescape/music-loop-2.mp3", // In-between games (BETTING/IDLE)
} as const;

type MusicMode = "game" | "inbetween" | "none";

class SoundManager {
  private ctx: AudioContext | null = null;
  private isMuted: boolean = false;

  // Background music state
  private musicTrack: HTMLAudioElement | null = null;
  private currentMode: MusicMode = "none";

  // One-shot SFX cache
  private sfxCache: Map<string, HTMLAudioElement> = new Map();

  constructor() {
    if (typeof window !== "undefined") {
      const stored = localStorage.getItem("birdescape_sound_muted");
      this.isMuted = stored === "true";
    }
  }

  // ---------------------------------------------------------------------------
  // Web Audio Context (for zero-latency synthetics)
  // ---------------------------------------------------------------------------
  private getContext(): AudioContext | null {
    if (typeof window === "undefined") return null;
    if (!this.ctx) {
      const AudioCtx =
        window.AudioContext ||
        (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
      if (AudioCtx) this.ctx = new AudioCtx();
    }
    if (this.ctx?.state === "suspended") void this.ctx.resume();
    return this.ctx;
  }

  // ---------------------------------------------------------------------------
  // Mute Toggle
  // ---------------------------------------------------------------------------
  public toggleMute(): boolean {
    this.isMuted = !this.isMuted;
    if (typeof window !== "undefined") {
      localStorage.setItem("birdescape_sound_muted", String(this.isMuted));
    }

    if (this.isMuted) {
      this.stopMusic();
    } else {
      // Resume whatever mode was active before muting
      if (this.currentMode === "game") {
        this.startMusic(SOUNDS.musicLoop1, 0.40);
      } else if (this.currentMode === "inbetween") {
        this.startMusic(SOUNDS.musicLoop2, 0.30);
      }
    }
    return this.isMuted;
  }

  public getMuted(): boolean {
    return this.isMuted;
  }

  // ---------------------------------------------------------------------------
  // SFX Player
  // ---------------------------------------------------------------------------
  private playSfx(src: string, volume = 0.75): void {
    if (this.isMuted || typeof window === "undefined") return;
    try {
      let el = this.sfxCache.get(src);
      if (!el) {
        el = new Audio(src);
        this.sfxCache.set(src, el);
      }
      if (!el.paused && el.currentTime > 0) {
        el = el.cloneNode() as HTMLAudioElement;
      }
      el.volume = volume;
      el.currentTime = 0;
      void el.play().catch(() => undefined);
    } catch {
      // Autoplay or audio policy fallback
    }
  }

  // ---------------------------------------------------------------------------
  // Continuous Loop Music Manager
  // ---------------------------------------------------------------------------
  private startMusic(src: string, volume: number): void {
    if (this.isMuted || typeof window === "undefined") return;

    // If already playing this exact track, don't restart it
    if (this.musicTrack && this.musicTrack.src.endsWith(src) && !this.musicTrack.paused) {
      return;
    }

    this.stopMusic();

    try {
      const track = new Audio(src);
      track.volume = volume;
      track.loop = true;
      this.musicTrack = track;
      void track.play().catch(() => undefined);
    } catch {
      // Autoplay restriction guard
    }
  }

  public stopMusic(): void {
    if (this.musicTrack) {
      try {
        this.musicTrack.pause();
        this.musicTrack.currentTime = 0;
      } catch {
        // Safe guard
      }
      this.musicTrack = null;
    }
  }

  // ---------------------------------------------------------------------------
  // Public Game Lifecycle Audio Events
  // ---------------------------------------------------------------------------

  /**
   * BETTING Phase (In-between games):
   * Plays game-start jingle and starts music loop 2 in the background.
   */
  public playGameStart(): void {
    this.currentMode = "inbetween";
    this.playSfx(SOUNDS.gameStart, 0.75);
    this.startMusic(SOUNDS.musicLoop2, 0.30);
  }

  /**
   * FLYING Phase (During game):
   * Transitions seamlessly to music loop 1 for the duration of the flight.
   */
  public playTakeoff(): void {
    this.currentMode = "game";
    this.startMusic(SOUNDS.musicLoop1, 0.40);
  }

  /**
   * CRASHED Phase (Game Over):
   * Stops game music, plays game-over sound, and transitions to in-between loop 2.
   */
  public playCrash(): void {
    this.currentMode = "inbetween";
    this.stopMusic();
    this.playSfx(SOUNDS.gameOver, 0.85);
    // Begin the in-between background loop
    this.startMusic(SOUNDS.musicLoop2, 0.30);
  }

  /**
   * Cashout celebratory chime (Low latency synthetic audio)
   */
  public playCashout(): void {
    if (this.isMuted) return;
    const ctx = this.getContext();
    if (!ctx) return;
    try {
      [523.25, 659.25, 783.99, 1046.5].forEach((freq, idx) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        const start = ctx.currentTime + idx * 0.07;
        osc.type = "sine";
        osc.frequency.setValueAtTime(freq, start);
        gain.gain.setValueAtTime(0.22, start);
        gain.gain.exponentialRampToValueAtTime(0.001, start + 0.25);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(start);
        osc.stop(start + 0.3);
      });
    } catch {
      // AudioContext policy guard
    }
  }

  /**
   * Countdown tick (Low latency synthetic audio)
   */
  public playCountdownTick(secondsRemaining: number): void {
    if (this.isMuted) return;
    const ctx = this.getContext();
    if (!ctx) return;
    try {
      const isUrgent = secondsRemaining <= 3 && secondsRemaining > 0;
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = "sine";
      osc.frequency.setValueAtTime(isUrgent ? 880 : 440, ctx.currentTime);
      gain.gain.setValueAtTime(0.12, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.08);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.09);
    } catch {
      // AudioContext policy guard
    }
  }

  /** Legacy compatibility hook */
  public stopFlightLoop(): void {
    this.stopMusic();
  }
}

export const gameAudio = new SoundManager();
