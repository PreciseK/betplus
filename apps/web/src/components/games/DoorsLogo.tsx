import { useId } from "react";

/** The gold marquee wordmark used by the Doors game header. */
export function DoorsLogo({ className }: { className?: string }) {
  const id = useId().replaceAll(":", "");
  return (
    <svg className={className} role="img" aria-label="Doors logo" viewBox="0 0 340 88" width="180" style={{ width: "100%", maxWidth: 180, height: "auto", overflow: "visible" }}>
      <defs>
        <linearGradient id={`${id}-gold`} x1="0" y1="0" x2="0" y2="1">
          <stop stopColor="#ffedb6" /><stop offset=".48" stopColor="#b7742e" />
          <stop offset=".52" stopColor="#ffebb3" /><stop offset="1" stopColor="#c79142" />
        </linearGradient>
        <pattern id={`${id}-bulbs`} width="15" height="15" patternUnits="userSpaceOnUse">
          <circle cx="4" cy="4" r="1.5" fill="#fff3bf" />
        </pattern>
      </defs>
      <g fontFamily="Outfit, sans-serif" fontSize="78" fontWeight="900" letterSpacing="1" style={{ filter: "drop-shadow(0 3px 2px #000)" }}>
        <text x="4" y="72" textLength="330" lengthAdjust="spacingAndGlyphs" fill={`url(#${id}-gold)`} stroke="#ebc680" strokeWidth="1">DOORS</text>
        <text x="4" y="72" textLength="330" lengthAdjust="spacingAndGlyphs" fill={`url(#${id}-bulbs)`} aria-hidden="true">DOORS</text>
      </g>
    </svg>
  );
}
