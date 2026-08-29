export const REVIEWABLE_CHANGE_TYPES = [
  "Prize table publication",
  "Game registry change",
  "Catalogue publication",
  "Manual payout approval",
  "Manual credit or debit",
  "Limit override",
  "Jurisdiction ruleset change",
  "Tax rate change",
  "Float top-up recording",
] as const;

export type ReviewableChangeType = (typeof REVIEWABLE_CHANGE_TYPES)[number];
export type ChangeWorkflowState = "DRAFT" | "AWAITING_APPROVAL" | "APPROVED" | "REJECTED" | "APPLIED";

export interface ApprovalChangeValue {
  field: string;
  before: string;
  after: string;
}

export interface ApprovalValidation {
  label: string;
  result: "passed" | "warning";
  detail: string;
}

export interface ReviewableChange {
  id: string;
  type: ReviewableChangeType;
  title: string;
  state: ChangeWorkflowState;
  version: string;
  maker: string;
  makerRole: string;
  createdAt: string;
  justification: string;
  risk: "High" | "Medium" | "Low";
  riskContext: string;
  affectedStates: readonly string[];
  affectedGames: readonly string[];
  effectiveAt: string;
  changes: readonly ApprovalChangeValue[];
  validations: readonly ApprovalValidation[];
  rejectionReason?: string;
  draftPreserved?: boolean;
  approver?: string;
  appliedAt?: string;
  configuredPrizeTableBy?: string;
}

export const REVIEWABLE_CHANGES: readonly ReviewableChange[] = [
  {
    id: "CHG-260818-048",
    type: "Tax rate change",
    title: "Apply Enugu player withholding rate",
    state: "AWAITING_APPROVAL",
    version: "EN-TAX-2026.09-v3",
    maker: "Adewale Bello",
    makerRole: "Finance",
    createdAt: "18 Aug 2026, 1:22 PM WAT",
    justification: "Signed directive EN-LG-2026-08 takes effect on 1 September. Legal basis is attached to compliance case CMP-9412.",
    risk: "High",
    riskContext: "Changes the tax withheld from every eligible winning ticket settled in Enugu after the effective time.",
    affectedStates: ["Enugu"],
    affectedGames: ["BlackRed", "Heritage"],
    effectiveAt: "1 Sep 2026, 12:00 AM WAT",
    changes: [
      { field: "Player withholding", before: "5.00%", after: "7.50%" },
      { field: "Ruleset version", before: "EN-TAX-2026.06-v2", after: "EN-TAX-2026.09-v3" },
    ],
    validations: [
      { label: "Legal basis", result: "passed", detail: "Directive reference and signed document present" },
      { label: "Historical settlement", result: "passed", detail: "Existing tickets remain bound to their settlement version" },
      { label: "Effective time", result: "passed", detail: "Future-dated and expressed in WAT" },
    ],
  },
  {
    id: "CHG-260818-047",
    type: "Prize table publication",
    title: "Publish BlackRed prize table v12",
    state: "AWAITING_APPROVAL",
    version: "BR-PT-v12",
    maker: "Ifeoma Nwosu",
    makerRole: "Compliance",
    createdAt: "18 Aug 2026, 12:38 PM WAT",
    justification: "Align the five-card tier with the approved RTP envelope in review RTP-2026-118.",
    risk: "High",
    riskContext: "Changes player payout multipliers and modelled RTP for new BlackRed tickets.",
    affectedStates: ["Lagos", "Enugu", "Rivers"],
    affectedGames: ["BlackRed"],
    effectiveAt: "19 Aug 2026, 6:00 AM WAT",
    changes: [
      { field: "Five-card multiplier", before: "26.00×", after: "25.50×" },
      { field: "Aggregate modelled RTP", before: "91.88%", after: "91.72%" },
    ],
    validations: [
      { label: "RTP envelope", result: "passed", detail: "Within the approved jurisdiction range" },
      { label: "Payout liability", result: "passed", detail: "Float cover remains above two operating days" },
    ],
  },
  {
    id: "CHG-260818-046",
    type: "Manual payout approval",
    title: "Approve compensating payout WD-0084",
    state: "AWAITING_APPROVAL",
    version: "PAY-ADJ-v1",
    maker: "Tunde Bamidele",
    makerRole: "Finance",
    createdAt: "18 Aug 2026, 11:54 AM WAT",
    justification: "Correct a provider-confirmed payout delivery failure without altering the settled ticket outcome.",
    risk: "High",
    riskContext: "Creates a compensating ledger entry and credits the player's winnings balance.",
    affectedStates: ["Lagos"],
    affectedGames: ["BlackRed"],
    effectiveAt: "Immediately after approval",
    changes: [
      { field: "Compensating credit", before: "₦0", after: "₦6,300" },
      { field: "Ledger reference", before: "Not created", after: "Generated on application" },
    ],
    validations: [
      { label: "Ticket settlement", result: "passed", detail: "Original ticket remains immutable" },
      { label: "Duplicate credit", result: "passed", detail: "No matching compensating entry found" },
    ],
    configuredPrizeTableBy: "Ifeoma Nwosu",
  },
  {
    id: "CHG-260817-039",
    type: "Limit override",
    title: "Temporary daily deposit limit override",
    state: "REJECTED",
    version: "RG-LIM-v4-draft",
    maker: "Amaka Okafor",
    makerRole: "Support Lead",
    createdAt: "17 Aug 2026, 4:16 PM WAT",
    justification: "Player supplied updated affordability evidence for compliance review.",
    risk: "High",
    riskContext: "Would increase a player protection limit for 30 days.",
    affectedStates: ["Rivers"],
    affectedGames: ["BlackRed", "Heritage"],
    effectiveAt: "Not scheduled",
    changes: [{ field: "Daily deposit limit", before: "₦50,000", after: "₦80,000" }],
    validations: [{ label: "Affordability evidence", result: "warning", detail: "Income evidence was older than the permitted review window" }],
    rejectionReason: "Request newer affordability evidence before resubmission.",
    draftPreserved: true,
  },
  {
    id: "CHG-260817-035",
    type: "Game registry change",
    title: "Restore BlackRed availability in Rivers",
    state: "APPLIED",
    version: "GAME-BR-v28",
    maker: "Chidi Eze",
    makerRole: "Game Ops",
    createdAt: "17 Aug 2026, 9:31 AM WAT",
    justification: "Restore configuration after the renewed Rivers licence passed compliance verification.",
    risk: "Medium",
    riskContext: "Re-enables new BlackRed tickets in Rivers across approved channels.",
    affectedStates: ["Rivers"],
    affectedGames: ["BlackRed"],
    effectiveAt: "17 Aug 2026, 10:00 AM WAT",
    changes: [{ field: "Availability", before: "Suspended", after: "Available" }],
    validations: [
      { label: "Licence status", result: "passed", detail: "Active through 31 Jul 2027" },
      { label: "Engine health", result: "passed", detail: "Version 2.8.4 is responding normally" },
    ],
    approver: "Ngozi Umeh",
    appliedAt: "17 Aug 2026, 10:00:04 AM WAT",
  },
];
