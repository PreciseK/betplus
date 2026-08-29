export interface PlayerLedgerEntry {
  id: string;
  occurredAt: string;
  type: string;
  game: "BlackRed" | "Heritage" | "Platform";
  amountKobo: number;
  account: "Play Balance" | "Winnings Balance";
  balanceAfterKobo: number;
  reference: string;
}

export interface PlayerTicketHistory {
  id: string;
  game: "BlackRed" | "Heritage";
  placedAt: string;
  stakeKobo: number;
  outcome: "Won" | "Lost";
  netKobo: number;
  prediction: string;
  outcomeTrail: readonly string[];
  settlementVersion: string;
}

export interface Player360Record {
  reference: string;
  displayName: string;
  phoneMasked: string;
  emailMasked: string;
  joinedAt: string;
  state: string;
  accountStatus: string;
  kyc: {
    status: string;
    tier: string;
    reviewedAt: string;
    nationalIdMasked: string;
    bankVerificationMasked: string;
    vaultPolicy: string;
  };
  balances: {
    playKobo: number;
    winningsKobo: number;
    turnoverStakedKobo: number;
    turnoverRequiredKobo: number;
  };
  currentCase: {
    reference: string;
    question: string;
    finding: string;
    providerEvidence: string;
    ledgerEvidence: string;
    notificationEvidence: string;
  };
  ledger: readonly PlayerLedgerEntry[];
  tickets: readonly PlayerTicketHistory[];
  payments: readonly {
    id: string;
    occurredAt: string;
    method: string;
    type: "Deposit" | "Withdrawal";
    amountKobo: number;
    status: string;
    providerReference: string;
    ledgerReference: string;
  }[];
  taxDeductions: readonly {
    id: string;
    occurredAt: string;
    ticketReference: string;
    state: string;
    rate: string;
    grossKobo: number;
    taxKobo: number;
    rulesetVersion: string;
  }[];
  notifications: readonly {
    id: string;
    occurredAt: string;
    channel: string;
    template: string;
    destinationMasked: string;
    status: "Delivered" | "Failed";
    providerReference: string;
  }[];
  responsiblePlay: {
    registryState: string;
    registryCheckedAt: string;
    dailyDepositLimitKobo: number;
    dailyDepositUsedKobo: number;
    lossLimitKobo: number;
    lossLimitUsedKobo: number;
    coolingOff: string;
    selfExclusion: string;
    latestRiskReview: string;
  };
}

export const PLAYER_360_FIXTURE: Player360Record = {
  reference: "BP-7319",
  displayName: "Adaeze N.",
  phoneMasked: "+234 ••• ••• 4207",
  emailMasked: "a••••••@example.ng",
  joinedAt: "12 Mar 2025",
  state: "Enugu",
  accountStatus: "Active",
  kyc: {
    status: "Verified",
    tier: "Tier 2",
    reviewedAt: "16 Jul 2026, 10:42 AM WAT",
    nationalIdMasked: "•••• •••• 1842",
    bankVerificationMasked: "••• ••• 9071",
    vaultPolicy: "Raw identifiers are absent from Player 360 and require separately authorised, individually audited vault access.",
  },
  balances: {
    playKobo: 12_500_00,
    winningsKobo: 8_750_00,
    turnoverStakedKobo: 4_200_00,
    turnoverRequiredKobo: 10_000_00,
  },
  currentCase: {
    reference: "CS-48218",
    question: "I paid but got nothing",
    finding: "The deposit was credited to Play Balance. The missing confirmation is a failed SMS, not a missing payment.",
    providerEvidence: "OPay confirmed DEP-260818-119 at 1:08:54 PM WAT.",
    ledgerEvidence: "Ledger entry LED-88821 credited ₦10,000 at 1:09:02 PM WAT.",
    notificationEvidence: "SMS NTF-66102 failed after provider timeout; no balance impact.",
  },
  ledger: [
    { id: "LED-88829", occurredAt: "18 Aug 2026, 1:34:42 PM WAT", type: "Ticket stake", game: "BlackRed", amountKobo: -1_000_00, account: "Play Balance", balanceAfterKobo: 12_500_00, reference: "BR-982104" },
    { id: "LED-88828", occurredAt: "18 Aug 2026, 1:34:43 PM WAT", type: "Ticket payout", game: "BlackRed", amountKobo: 7_000_00, account: "Winnings Balance", balanceAfterKobo: 8_750_00, reference: "BR-982104" },
    { id: "LED-88827", occurredAt: "18 Aug 2026, 1:34:43 PM WAT", type: "Withholding tax", game: "BlackRed", amountKobo: -700_00, account: "Winnings Balance", balanceAfterKobo: 8_750_00, reference: "TAX-4421" },
    { id: "LED-88821", occurredAt: "18 Aug 2026, 1:09:02 PM WAT", type: "Deposit credit", game: "Platform", amountKobo: 10_000_00, account: "Play Balance", balanceAfterKobo: 13_500_00, reference: "DEP-260818-119" },
    { id: "LED-88796", occurredAt: "18 Aug 2026, 11:22:16 AM WAT", type: "Ticket stake", game: "Heritage", amountKobo: -500_00, account: "Play Balance", balanceAfterKobo: 3_500_00, reference: "HG-771408" },
    { id: "LED-88795", occurredAt: "18 Aug 2026, 11:22:19 AM WAT", type: "Ticket payout", game: "Heritage", amountKobo: 2_600_00, account: "Winnings Balance", balanceAfterKobo: 2_450_00, reference: "HG-771408" },
    { id: "LED-88742", occurredAt: "17 Aug 2026, 8:14:02 PM WAT", type: "Withdrawal debit", game: "Platform", amountKobo: -5_000_00, account: "Winnings Balance", balanceAfterKobo: 150_00, reference: "WD-260817-084" },
    { id: "LED-88631", occurredAt: "17 Aug 2026, 5:02:48 PM WAT", type: "Ticket stake", game: "BlackRed", amountKobo: -500_00, account: "Play Balance", balanceAfterKobo: 4_000_00, reference: "BR-981774" },
  ],
  tickets: [
    {
      id: "BR-982104",
      game: "BlackRed",
      placedAt: "18 Aug 2026, 1:34:42 PM WAT",
      stakeKobo: 1_000_00,
      outcome: "Won",
      netKobo: 6_300_00,
      prediction: "Red → Black → Red",
      outcomeTrail: ["1 Red · matched", "2 Black · matched", "3 Red · matched"],
      settlementVersion: "BR-PT-v11 · EN-TAX-2026.06-v2",
    },
    {
      id: "HG-771408",
      game: "Heritage",
      placedAt: "18 Aug 2026, 11:22:16 AM WAT",
      stakeKobo: 500_00,
      outcome: "Won",
      netKobo: 2_100_00,
      prediction: "07, 23, 44, 68, 89",
      outcomeTrail: ["07 matched", "23 matched", "44 matched", "68 matched", "89 matched"],
      settlementVersion: "HG-5/90-v7 · EN-TAX-2026.06-v2",
    },
    {
      id: "BR-981774",
      game: "BlackRed",
      placedAt: "17 Aug 2026, 5:02:48 PM WAT",
      stakeKobo: 500_00,
      outcome: "Lost",
      netKobo: -500_00,
      prediction: "Black → Black",
      outcomeTrail: ["1 Black · matched", "2 Red · different"],
      settlementVersion: "BR-PT-v11 · EN-TAX-2026.06-v2",
    },
  ],
  payments: [
    { id: "DEP-260818-119", occurredAt: "18 Aug 2026, 1:08:54 PM WAT", method: "OPay", type: "Deposit", amountKobo: 10_000_00, status: "Provider confirmed · ledger posted", providerReference: "OP-••••-7741", ledgerReference: "LED-88821" },
    { id: "WD-260817-084", occurredAt: "17 Aug 2026, 8:14:02 PM WAT", method: "Bank transfer", type: "Withdrawal", amountKobo: 5_000_00, status: "Delivered", providerReference: "NIP-••••-1994", ledgerReference: "LED-88742" },
    { id: "DEP-260816-441", occurredAt: "16 Aug 2026, 9:40:13 AM WAT", method: "Card", type: "Deposit", amountKobo: 5_000_00, status: "Provider confirmed · ledger posted", providerReference: "PAY-••••-2207", ledgerReference: "LED-88112" },
  ],
  taxDeductions: [
    { id: "TAX-4421", occurredAt: "18 Aug 2026, 1:34:43 PM WAT", ticketReference: "BR-982104", state: "Enugu", rate: "10% WHT", grossKobo: 7_000_00, taxKobo: 700_00, rulesetVersion: "EN-TAX-2026.06-v2" },
    { id: "TAX-4402", occurredAt: "18 Aug 2026, 11:22:19 AM WAT", ticketReference: "HG-771408", state: "Enugu", rate: "10% WHT", grossKobo: 2_600_00, taxKobo: 260_00, rulesetVersion: "EN-TAX-2026.06-v2" },
  ],
  notifications: [
    { id: "NTF-66102", occurredAt: "18 Aug 2026, 1:09:03 PM WAT", channel: "SMS", template: "Deposit confirmed", destinationMasked: "+234 ••• ••• 4207", status: "Failed", providerReference: "SMS-••••-8820" },
    { id: "NTF-66105", occurredAt: "18 Aug 2026, 1:34:44 PM WAT", channel: "Push", template: "Ticket settled", destinationMasked: "Device •••• 98AF", status: "Delivered", providerReference: "PUSH-••••-0019" },
    { id: "NTF-65984", occurredAt: "17 Aug 2026, 8:15:10 PM WAT", channel: "Email", template: "Withdrawal delivered", destinationMasked: "a••••••@example.ng", status: "Delivered", providerReference: "MAIL-••••-7702" },
  ],
  responsiblePlay: {
    registryState: "No exclusion match",
    registryCheckedAt: "18 Aug 2026, 1:41 PM WAT",
    dailyDepositLimitKobo: 50_000_00,
    dailyDepositUsedKobo: 10_000_00,
    lossLimitKobo: 20_000_00,
    lossLimitUsedKobo: 500_00,
    coolingOff: "None active",
    selfExclusion: "None active",
    latestRiskReview: "Standard monitoring · reviewed 4 Aug 2026",
  },
};

export function findPlayer360(rawReference: string) {
  const normalized = rawReference.trim().toLocaleUpperCase();
  return normalized === PLAYER_360_FIXTURE.reference ? PLAYER_360_FIXTURE : null;
}
