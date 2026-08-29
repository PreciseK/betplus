import type { OperatorRole } from "@/mocks/operator-session";

export interface DashboardMetric {
  label: string;
  value: string;
  change: string;
  context: string;
}

export interface DashboardPoint {
  label: string;
  primary: number;
  comparison: number;
}

export interface DashboardAnalytics {
  title: string;
  summary: string;
  primaryLabel: string;
  comparisonLabel: string;
  valuePrefix?: string;
  valueSuffix?: string;
  points: readonly DashboardPoint[];
}

export interface DashboardQueueItem {
  id: string;
  task: string;
  detail: string;
  status: string;
  age: string;
  href: string;
}

export interface OperatorDashboardConfig {
  title: string;
  description: string;
  decisionPrompt: string;
  metrics: readonly DashboardMetric[];
  analytics: DashboardAnalytics;
  queueTitle: string;
  queueSummary: string;
  queue: readonly DashboardQueueItem[];
}

const week = (values: readonly [number, number][]): readonly DashboardPoint[] =>
  ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((label, index) => ({
    label,
    primary: values[index][0],
    comparison: values[index][1],
  }));

export const OPERATOR_DASHBOARDS: Record<OperatorRole, OperatorDashboardConfig> = {
  "support-agent": {
    title: "Support overview",
    description: "Your cases, response time and player checks for today.",
    decisionPrompt: "Start with the oldest high-impact case; escalations keep their full audit trail.",
    metrics: [
      { label: "Assigned now", value: "18", change: "4 due within 30 min", context: "Your open queue" },
      { label: "First response", value: "6m 42s", change: "1m 08s faster", context: "Against this shift yesterday" },
      { label: "Resolved today", value: "37", change: "92% without reopen", context: "Your completed cases" },
      { label: "Player safeguards", value: "3", change: "All acknowledged", context: "Responsible-play flags on assigned cases" },
    ],
    analytics: { title: "Case flow this week", summary: "Incoming cases peaked on Friday; completed cases remained within nine of arrivals each day.", primaryLabel: "Cases received", comparisonLabel: "Cases completed", points: week([[42, 39], [48, 45], [44, 43], [51, 47], [62, 54], [55, 49], [46, 44]]) },
    queueTitle: "Your next cases", queueSummary: "Ordered by service risk, then waiting time.",
    queue: [
      { id: "TKT-4821", task: "Cash-out status", detail: "Player sees a completed debit without a bank credit", status: "High impact", age: "18 min", href: "/back-office/tickets" },
      { id: "TKT-4814", task: "Identity review", detail: "Document resubmission needs a readable image check", status: "Waiting", age: "31 min", href: "/back-office/players" },
      { id: "TKT-4799", task: "BlackRed round receipt", detail: "Explain sealed outcome and tax line", status: "Standard", age: "44 min", href: "/back-office/tickets" },
    ],
  },
  "support-lead": {
    title: "Support team overview",
    description: "Team workload, service levels and important player cases.",
    decisionPrompt: "Five escalations need ownership before the next staffing hand-off.",
    metrics: [
      { label: "Open cases", value: "146", change: "12 above weekday baseline", context: "All support queues" },
      { label: "SLA at risk", value: "11", change: "5 need assignment", context: "Response due within 30 min" },
      { label: "Resolution rate", value: "88.4%", change: "Up 2.1 points", context: "Seven-day rolling rate" },
      { label: "Safeguard escalations", value: "7", change: "2 awaiting compliance", context: "Harm indicators paired with service load" },
    ],
    analytics: { title: "Demand and resolution", summary: "The team closed 91% of incoming volume this week; Friday created the largest same-day backlog.", primaryLabel: "Cases received", comparisonLabel: "Cases resolved", points: week([[188, 176], [194, 185], [201, 197], [207, 198], [252, 219], [224, 211], [196, 187]]) },
    queueTitle: "Lead interventions", queueSummary: "Cases that need assignment, approval or cross-team hand-off.",
    queue: [
      { id: "ESC-091", task: "Clustered payout complaints", detail: "Six OPay cases may share one collection incident", status: "Assign owner", age: "12 min", href: "/back-office/tickets" },
      { id: "APR-044", task: "Service compensation", detail: "Checker needed for ₦25,000 total adjustment", status: "Approval", age: "24 min", href: "/back-office/overview/approvals" },
      { id: "RGP-018", task: "Player welfare hand-off", detail: "Repeated overnight contacts meet escalation policy", status: "Safeguard", age: "39 min", href: "/back-office/responsible-play" },
    ],
  },
  finance: {
    title: "Finance overview",
    description: "Today’s collections, payouts and items waiting for review.",
    decisionPrompt: "Provider mismatches total ₦3.84m; oldest item is 43 minutes old.",
    metrics: [
      { label: "Reconciled today", value: "₦184.6m", change: "99.82% matched", context: "Collections and payouts" },
      { label: "Open variance", value: "₦3.84m", change: "Across 19 entries", context: "Unmatched provider records" },
      { label: "Payout cover", value: "2.4 days", change: "Above 2-day floor", context: "₦87.5m available float" },
      { label: "Checker queue", value: "6", change: "₦1.26m in review", context: "No self-approval permitted" },
    ],
    analytics: { title: "Settled value and exceptions", summary: "Settlement volume rose 12% week over week while exception value stayed below 2.4% of daily settled value.", primaryLabel: "Settled value", comparisonLabel: "Exception value", valuePrefix: "₦", valueSuffix: "m", points: week([[21.4, .42], [24.8, .56], [23.1, .31], [26.6, .64], [31.2, .71], [29.7, .58], [27.8, .62]]) },
    queueTitle: "Financial exceptions", queueSummary: "Ordered by exposure, ageing and payout impact.",
    queue: [
      { id: "REC-014", task: "OPay collection mismatch", detail: "Posted ledger entry differs from provider settlement", status: "₦1.25m", age: "18 min", href: "/back-office/money" },
      { id: "FLT-003", task: "Payout cover review", detail: "One rail is below its channel-specific operating floor", status: "₦875k", age: "42 min", href: "/back-office/payout-float" },
      { id: "ADJ-118", task: "Manual adjustment check", detail: "Maker evidence complete; independent checker required", status: "Approve", age: "51 min", href: "/back-office/overview/approvals" },
    ],
  },
  compliance: {
    title: "Compliance overview",
    description: "Player safety checks, licence deadlines and reporting tasks.",
    decisionPrompt: "Three high-priority welfare reviews and one licence evidence pack are due today.",
    metrics: [
      { label: "Safeguard reviews", value: "23", change: "3 high priority", context: "Open responsible-play cases" },
      { label: "Registry matches", value: "7", change: "All accounts restricted", context: "National exclusion sync since midnight" },
      { label: "Licence actions", value: "4", change: "Next due in 47 days", context: "Evidence and renewal tasks" },
      { label: "Reports due", value: "2", change: "1 due by 4 PM", context: "Regulatory and tax reports" },
    ],
    analytics: { title: "Activity and safeguard interventions", summary: "Player activity increased at the weekend; safeguard interventions increased proportionally with no unexplained divergence.", primaryLabel: "Active players (000s)", comparisonLabel: "Safeguard interventions (×10)", points: week([[18.2, 14], [19.1, 15], [19.6, 16], [21.4, 18], [24.8, 21], [28.1, 24], [26.7, 23]]) },
    queueTitle: "Compliance decisions", queueSummary: "Welfare risk first, followed by statutory deadlines.",
    queue: [
      { id: "RGP-032", task: "Enhanced welfare review", detail: "Session pattern crossed two policy thresholds", status: "High priority", age: "9 min", href: "/back-office/responsible-play" },
      { id: "LIC-EN-26", task: "Enugu licence evidence", detail: "Renewal pack needs checker sign-off", status: "47 days", age: "Today", href: "/back-office/jurisdictions" },
      { id: "RPT-088", task: "NLRC monthly extract", detail: "Variance note required before export", status: "Due 4 PM", age: "Today", href: "/back-office/reports" },
    ],
  },
  "game-ops": {
    title: "Games overview",
    description: "Game activity, releases and incidents that need attention.",
    decisionPrompt: "BlackRed configuration BR-26.08 has passed simulation and awaits publication approval.",
    metrics: [
      { label: "Round success", value: "99.994%", change: "4 failed receipts", context: "2.18m rounds in 24 hours" },
      { label: "Configuration queue", value: "3", change: "1 ready to publish", context: "Maker-checker controlled" },
      { label: "Gross stakes", value: "₦96.4m", change: "Player safety alerts 0.58%", context: "Today’s game activity" },
      { label: "Open incidents", value: "2", change: "No player funds at risk", context: "One degraded telemetry feed" },
    ],
    analytics: { title: "Round volume and safeguard triggers", summary: "Round volume rose 16% over the weekend; safeguard triggers tracked the same pattern and remained below policy tolerance.", primaryLabel: "Rounds (000s)", comparisonLabel: "Safeguard triggers (×10)", points: week([[244, 19], [251, 20], [263, 21], [278, 22], [301, 25], [338, 29], [326, 27]]) },
    queueTitle: "Release and incident queue", queueSummary: "Integrity checks precede commercial scheduling.",
    queue: [
      { id: "CFG-BR-0826", task: "BlackRed prize table", detail: "Simulation passed; independent publication approval required", status: "Ready", age: "16 min", href: "/back-office/games" },
      { id: "INC-204", task: "Heritage telemetry gap", detail: "Round outcomes intact; analytics feed is delayed", status: "Monitor", age: "27 min", href: "/back-office/analytics" },
      { id: "CNT-077", task: "Heritage copy release", detail: "Cultural review complete; schedule production publish", status: "Publish", age: "1 hr", href: "/back-office/content" },
    ],
  },
  "content-editor": {
    title: "Content overview",
    description: "Drafts, reviews and recently published content.",
    decisionPrompt: "Two Heritage entries need source notes before cultural review can begin.",
    metrics: [
      { label: "Drafts in progress", value: "12", change: "4 assigned to you", context: "Across game and help content" },
      { label: "Ready for review", value: "7", change: "Median wait 3h 12m", context: "Submitted packages" },
      { label: "Returned with notes", value: "3", change: "2 need source evidence", context: "Review feedback" },
      { label: "Published this week", value: "24", change: "0 emergency rollbacks", context: "Audited releases" },
    ],
    analytics: { title: "Editorial throughput", summary: "Review completions are keeping pace with submissions; the current backlog is concentrated in source verification.", primaryLabel: "Submitted", comparisonLabel: "Approved", points: week([[5, 4], [7, 6], [6, 6], [9, 7], [8, 8], [4, 5], [3, 3]]) },
    queueTitle: "Editorial work", queueSummary: "Items with review blockers appear first.",
    queue: [
      { id: "HRT-114", task: "Ọ̀bà regalia note", detail: "Add a primary-source citation for the ceremonial staff", status: "Source needed", age: "2 hr", href: "/back-office/content" },
      { id: "HLP-038", task: "BlackRed tax explainer", detail: "Plain-language revision requested by compliance", status: "Edit", age: "4 hr", href: "/back-office/content" },
      { id: "HRT-109", task: "Yoruba diacritics pass", detail: "Copy is ready for cultural review", status: "Submit", age: "Today", href: "/back-office/content" },
    ],
  },
  "cultural-reviewer": {
    title: "Heritage review overview",
    description: "Items waiting for cultural review and source checks.",
    decisionPrompt: "One royal title has conflicting source notes and should not proceed without clarification.",
    metrics: [
      { label: "Awaiting review", value: "9", change: "2 high visibility", context: "Complete source packs" },
      { label: "Source questions", value: "4", change: "1 blocking release", context: "Returned to editorial" },
      { label: "Approved this week", value: "18", change: "Median review 5h 24m", context: "Culturally signed off" },
      { label: "Corrections after publish", value: "0", change: "For 41 consecutive days", context: "Quality signal" },
    ],
    analytics: { title: "Review intake and decisions", summary: "Decisions matched intake on five of seven days; four source questions remain with editorial.", primaryLabel: "Submitted for review", comparisonLabel: "Decisions completed", points: week([[4, 4], [6, 5], [7, 7], [5, 6], [8, 6], [3, 4], [4, 4]]) },
    queueTitle: "Cultural review queue", queueSummary: "Language and provenance conflicts are surfaced before routine checks.",
    queue: [
      { id: "HRT-121", task: "Ọ̀bà Adetoyese title", detail: "Two source notes use different royal-title forms", status: "Clarify", age: "1 hr", href: "/back-office/content" },
      { id: "HRT-119", task: "Ìrùkẹ̀rẹ̀ object note", detail: "Verify regional scope of the interpretation", status: "Review", age: "3 hr", href: "/back-office/content" },
      { id: "HRT-116", task: "Oríkì pronunciation", detail: "Audio transcription and diacritics are aligned", status: "Approve", age: "5 hr", href: "/back-office/content" },
    ],
  },
  "system-admin": {
    title: "Team access overview",
    description: "Staff access, security checks and reviews due today.",
    decisionPrompt: "Two temporary access grants expire before close of business.",
    metrics: [
      { label: "Active operators", value: "84", change: "7 privileged roles", context: "Enabled staff accounts" },
      { label: "MFA coverage", value: "100%", change: "0 recovery bypasses", context: "All active operators" },
      { label: "Access reviews", value: "6", change: "2 due today", context: "Manager attestations" },
      { label: "Security events", value: "3", change: "All investigated", context: "Blocked sign-in attempts in 24h" },
    ],
    analytics: { title: "Privileged access activity", summary: "Privileged sign-ins remain stable; all failed attempts were blocked before a session was issued.", primaryLabel: "Privileged sign-ins", comparisonLabel: "Blocked attempts", points: week([[18, 1], [21, 0], [19, 1], [23, 0], [22, 2], [14, 0], [16, 1]]) },
    queueTitle: "Access administration", queueSummary: "Expiry and least-privilege reviews come before routine requests.",
    queue: [
      { id: "IAM-231", task: "Temporary Finance access", detail: "Grant expires at 5:00 PM WAT", status: "Expires today", age: "2 hr", href: "/back-office/users" },
      { id: "IAM-227", task: "Quarterly role attestation", detail: "Support Lead group needs manager confirmation", status: "Review", age: "Today", href: "/back-office/users" },
      { id: "SEC-091", task: "Blocked sign-in review", detail: "Three attempts from an unapproved network", status: "Investigated", age: "Yesterday", href: "/back-office/audit-log" },
    ],
  },
  "super-admin": {
    title: "All operations",
    description: "A simple view of today’s money, players and important work across Betplus.",
    decisionPrompt: "Nine items need attention today.",
    metrics: [
      { label: "Total stakes", value: "₦96.4m", change: "+8.2% vs yesterday", context: "BlackRed and Heritage" },
      { label: "Payouts", value: "₦53.7m", change: "+4.6% vs yesterday", context: "Completed player payouts" },
      { label: "New players", value: "1,284", change: "86% completed verification", context: "Registered today" },
      { label: "Needs attention", value: "9", change: "3 high priority", context: "Across all teams" },
    ],
    analytics: { title: "Stakes and payouts", summary: "Stakes increased through the weekend while payouts followed the same overall pattern.", primaryLabel: "Stakes", comparisonLabel: "Payouts", valuePrefix: "₦", valueSuffix: "m", points: week([[11.8, 7.1], [12.4, 7.5], [13.1, 7.8], [13.7, 8.2], [15.6, 8.9], [17.2, 9.7], [16.4, 9.3]]) },
    queueTitle: "Cross-platform decisions", queueSummary: "Risk-ranked exceptions from every back-office domain.",
    queue: [
      { id: "REC-014", task: "OPay collection mismatch", detail: "Provider settlement differs from posted ledger entry", status: "₦1.25m", age: "18 min", href: "/back-office/money" },
      { id: "RGP-032", task: "Enhanced welfare review", detail: "Session pattern crossed two policy thresholds", status: "High priority", age: "9 min", href: "/back-office/responsible-play" },
      { id: "CFG-BR-0826", task: "BlackRed prize table", detail: "Simulation passed; publication approval required", status: "Ready", age: "16 min", href: "/back-office/games" },
    ],
  },
};
