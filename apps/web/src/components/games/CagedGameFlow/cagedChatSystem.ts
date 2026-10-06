import { AVATAR_GRADIENTS, CAGED_50_USERNAMES } from "./cagedActivePlayers";

export interface CagedChatMessage {
  id: string;
  username: string;
  avatarColor?: string;
  text: string;
  time: string;
  isBigWin?: boolean;
  isSystem?: boolean;
  isCurrentUser?: boolean;
  badge?: string;
  payoutKobo?: number;
  multiplier?: number;
}

export const CHAT_REACTION_EMOJIS = ["🚀", "🔥", "🦅", "💰", "👏", "😂", "😭"] as const;

export const INITIAL_CHAT_MESSAGES: CagedChatMessage[] = [
  {
    id: "msg_init_1",
    username: "0803xx1948",
    avatarColor: AVATAR_GRADIENTS[0],
    text: "Targeting 2.50x this round 🎯 Safe flight everyone!",
    time: "13:20",
  },
  {
    id: "msg_init_2",
    username: "0812xx8492",
    avatarColor: AVATAR_GRADIENTS[1],
    text: "Cashed out at 6.40x earlier!! Let's goooo 🚀🦅",
    time: "13:21",
    isBigWin: true,
  },
  {
    id: "msg_init_3",
    username: "0705xx7124",
    avatarColor: AVATAR_GRADIENTS[3],
    text: "Cage opened, speed is solid today 🔥",
    time: "13:22",
  },
  {
    id: "msg_init_4",
    username: "System",
    avatarColor: "#059669",
    text: "🟢 Caged Lounge live. Play responsibly.",
    time: "13:23",
    isSystem: true,
  },
  {
    id: "msg_init_5",
    username: "0816xx4401",
    avatarColor: AVATAR_GRADIENTS[5],
    text: "Who else has auto-cashout set to 2.0x? 💰",
    time: "13:24",
  },
];

// Contextual reaction pools for realistic live gaming conversation
const BETTING_CHATTER = [
  "Locked in for this round 🤞",
  "Let's see that 5x flight 🦅",
  "Targeting 2.20x 🎯",
  "GL everyone! 🍀",
  "Placing high stake this time, let's go!",
  "Big flight coming up 🚀",
  "Staying cautious at 1.80x 🛡️",
  "Ready for this round 🔥",
];

const EARLY_CRASH_REACTIONS = [
  "Bruh 😭",
  "Instant crash smh 💀",
  "Oof caught right out the gate!",
  "Rip my stake haha, next round 🔄",
  "Recovering next round 🤞",
  "That was sudden 😅",
  "Cage snapped shut fast 😭",
];

const HIGH_MULTIPLIER_REACTIONS = [
  "WHAT A FLIGHT!! 🚀🔥",
  "Huge multiplier!! Who held? 🦅",
  "Bird flew out of sight! 🚀",
  "Insane run!! 💰🔥",
  "Cashed out at 5.2x, thank goodness! 👏",
  "That was glorious 🦅🚀",
];

const GENERAL_CASHOUT_CHATTER = [
  "Nice catch 👏",
  "Green board! 💚",
  "Banked profits 💰",
  "Patience pays off 🎯",
  "Good round guys!",
];

/**
 * Picks a random online count between 135 and 165 for the live room indicator.
 */
export function getSimulatedOnlineCount(): number {
  return Math.floor(138 + Math.random() * 26);
}

function getRandomUser(): { username: string; avatarColor: string } {
  const idx = Math.floor(Math.random() * CAGED_50_USERNAMES.length);
  const username = CAGED_50_USERNAMES[idx];
  const avatarColor = AVATAR_GRADIENTS[idx % AVATAR_GRADIENTS.length];
  return { username, avatarColor };
}

function getCurrentTimeStr(): string {
  return new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

/**
 * Creates a reaction message triggered by round events.
 */
export function createRoundEventChatMessage(
  event: "BETTING" | "EARLY_CRASH" | "HIGH_CRASH" | "BIG_WIN",
  extra?: { winnerName?: string; multiplier?: number; payoutNairaStr?: string },
): CagedChatMessage {
  const { username, avatarColor } = getRandomUser();
  const time = getCurrentTimeStr();
  const id = `chat_${Date.now()}_${Math.random().toString(36).substring(2, 7)}`;

  if (event === "BIG_WIN" && extra?.winnerName && extra?.payoutNairaStr) {
    return {
      id,
      username: "System",
      avatarColor: "#f5b731",
      text: `🏆 ${extra.winnerName} cashed out ${extra.payoutNairaStr} at ${(extra.multiplier ?? 2).toFixed(2)}x!`,
      time,
      isBigWin: true,
      badge: "BIG WIN",
    };
  }

  let pool: string[];
  if (event === "EARLY_CRASH") {
    pool = EARLY_CRASH_REACTIONS;
  } else if (event === "HIGH_CRASH") {
    pool = HIGH_MULTIPLIER_REACTIONS;
  } else {
    pool = BETTING_CHATTER;
  }

  const text = pool[Math.floor(Math.random() * pool.length)];

  return {
    id,
    username,
    avatarColor,
    text,
    time,
  };
}

/**
 * Creates an organic background chatter message.
 */
export function createOrganicChatMessage(): CagedChatMessage {
  const { username, avatarColor } = getRandomUser();
  const pool = [...BETTING_CHATTER, ...GENERAL_CASHOUT_CHATTER];
  const text = pool[Math.floor(Math.random() * pool.length)];

  return {
    id: `chat_${Date.now()}_${Math.random().toString(36).substring(2, 7)}`,
    username,
    avatarColor,
    text,
    time: getCurrentTimeStr(),
  };
}
