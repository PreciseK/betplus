export interface GameItem {
  id: string;
  title: string;
  category: string;
  badge: string;
  description: string;
  multiplier: string;
  compactMultiplier?: string;
  stakeRange: string;
  route: string;
  logoSrc?: string;
  accentColor: string;
  emblemText?: string;
  isCrown?: boolean;
  live?: boolean;
  hot?: boolean;
  themeGradient: string;
  statsSummary: string;
}

export const GAMES: GameItem[] = [
  {
    id: "caged",
    title: "Caged",
    category: "Live Multiplayer Crash",
    badge: "Crash Multiplier",
    description: "Watch birds escape the golden cage. Cash out your stake with dual-bet slots before the cage drops!",
    multiplier: "1.00× – 100×+",
    compactMultiplier: "100×+",
    stakeRange: "₦10 – ₦10,000",
    route: "/games/caged",
    emblemText: "🦅",
    accentColor: "#f5b731",
    themeGradient: "radial-gradient(circle at 50% 40%, rgba(245, 183, 49, 0.35) 0%, rgba(20, 16, 14, 0.95) 75%)",
    live: true,
    hot: true,
    statsSummary: "Up to 100×+ · Dual-bet cashout",
  },
  {
    id: "blackred",
    title: "BlackRed",
    category: "Instant Fixed-Odds Prediction",
    badge: "50/50 Cards",
    description: "Predict sequence of 1 to 5 Black or Red cards with live 3D deck inspection, riffle shuffle, and instant draw.",
    multiplier: "2× – 100×",
    compactMultiplier: "100×",
    stakeRange: "₦100 – ₦5,000",
    route: "/games/blackred",
    logoSrc: "/assets/blackred-logo.png",
    accentColor: "#ef4444",
    themeGradient: "radial-gradient(circle at 50% 40%, rgba(239, 68, 68, 0.35) 0%, rgba(20, 14, 14, 0.95) 75%)",
    hot: true,
    statsSummary: "2× – 100× · Instant draw",
  },
  {
    id: "heritage",
    title: "Heritage",
    category: "Culture-Themed Instant-Win",
    badge: "Regalia Discovery",
    description: "Choose 5 of 9 positions to reveal regalia from across Nigeria with complete settlement board replay.",
    multiplier: "Up to 2,000×",
    compactMultiplier: "2,000×",
    stakeRange: "₦100 – ₦5,000",
    route: "/games/heritage",
    isCrown: true,
    accentColor: "#d97706",
    themeGradient: "radial-gradient(circle at 50% 40%, rgba(217, 119, 6, 0.35) 0%, rgba(24, 18, 12, 0.95) 75%)",
    statsSummary: "Up to 2,000× · 7 Dynasties",
  },
];

export function getGameById(id: string): GameItem | undefined {
  return GAMES.find((g) => g.id === id);
}
