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
    id: "doors", title: "Doors", category: "Treasure hunt", badge: "Vault",
    description: "Five attempts. Your first treasure wins.", multiplier: "2× – 5×", compactMultiplier: "5×",
    stakeRange: "₦200 – ₦500", route: "/games/doors", accentColor: "#58e6e9",
    themeGradient: "radial-gradient(circle at 50% 40%, #1c626b 0%, #0a171b 75%)",
    statsSummary: "Gold 3× · Chest 5× · Card 2×", emblemText: "DOORS",
  },
  {
    id: "caged",
    title: "Caged",
    category: "Live crash",
    badge: "Crash",
    description: "Cash out before the cage drops. Place up to two bets per round.",
    multiplier: "1.00× – 100×+",
    compactMultiplier: "100×+",
    stakeRange: "₦200 – ₦10,000",
    route: "/games/caged",
    logoSrc: "/assets/caged-mark.svg",
    accentColor: "#f5b731",
    themeGradient: "radial-gradient(circle at 50% 40%, rgba(245, 183, 49, 0.35) 0%, rgba(20, 16, 14, 0.95) 75%)",
    live: true,
    hot: true,
    statsSummary: "Up to 100×+ · Dual-bet cashout",
  },
  {
    id: "blackred",
    title: "BlackRed",
    category: "Card prediction",
    badge: "Cards",
    description: "Predict the colours of one to five cards.",
    multiplier: "2× – 100×",
    compactMultiplier: "100×",
    stakeRange: "₦200 – ₦5,000",
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
    category: "Instant win",
    badge: "Regalia",
    description: "Choose five positions and reveal matching royal regalia.",
    multiplier: "Up to 2,000×",
    compactMultiplier: "2,000×",
    stakeRange: "₦200 – ₦5,000",
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
