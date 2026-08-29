export function HeritageCrownLogo({
  className,
  size = 64,
}: {
  className?: string;
  size?: number;
}) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 100 100"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={className}
      aria-label="Heritage Regalia Crown RPG Logo"
    >
      <defs>
        <linearGradient id="crownGoldGrad" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stopColor="#fef08a" />
          <stop offset="35%" stopColor="#f59e0b" />
          <stop offset="70%" stopColor="#d97706" />
          <stop offset="100%" stopColor="#78350f" />
        </linearGradient>
        <linearGradient id="crownRubyGrad" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stopColor="#fb7185" />
          <stop offset="100%" stopColor="#be123c" />
        </linearGradient>
        <linearGradient id="crownEmeraldGrad" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stopColor="#34d399" />
          <stop offset="100%" stopColor="#047857" />
        </linearGradient>
        <filter id="crownGlowFilter" x="-20%" y="-20%" width="140%" height="140%">
          <feDropShadow dx="0" dy="4" stdDeviation="6" floodColor="#f59e0b" floodOpacity="0.45" />
        </filter>
      </defs>

      <g filter="url(#crownGlowFilter)">
        {/* Crown Base Band */}
        <path
          d="M20 70C20 66 80 66 80 70L77 82C77 85 23 85 23 82L20 70Z"
          fill="url(#crownGoldGrad)"
          stroke="#fed7aa"
          strokeWidth="1"
        />

        {/* Crown 5 Spikes RPG Silhouette */}
        <path
          d="M20 68L15 36L34 52L50 20L66 52L85 36L80 68Z"
          fill="url(#crownGoldGrad)"
          stroke="#ffffff"
          strokeWidth="1.5"
          strokeLinejoin="round"
        />

        {/* Royal Jewel Orbs */}
        <circle cx="15" cy="34" r="5" fill="url(#crownRubyGrad)" stroke="#fef08a" strokeWidth="1.5" />
        <circle cx="50" cy="18" r="7" fill="url(#crownRubyGrad)" stroke="#fef08a" strokeWidth="2" />
        <circle cx="85" cy="34" r="5" fill="url(#crownRubyGrad)" stroke="#fef08a" strokeWidth="1.5" />
        <circle cx="34" cy="50" r="3.5" fill="url(#crownEmeraldGrad)" stroke="#ffffff" strokeWidth="1" />
        <circle cx="66" cy="50" r="3.5" fill="url(#crownEmeraldGrad)" stroke="#ffffff" strokeWidth="1" />

        {/* Central Band Jewels */}
        <polygon points="50,68 56,76 50,83 44,76" fill="url(#crownEmeraldGrad)" stroke="#ffffff" strokeWidth="1" />
        <circle cx="32" cy="76" r="3" fill="url(#crownRubyGrad)" />
        <circle cx="68" cy="76" r="3" fill="url(#crownRubyGrad)" />
      </g>
    </svg>
  );
}
