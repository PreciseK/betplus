export type AnalyticsDimension = "channel" | "game" | "appVersion" | "state" | "locale";

export interface FunnelStage {
  id: string;
  name: string;
  count: number;
  conversion: number;
  source: "Server event" | "UX telemetry";
}

export interface AnalyticsFunnel {
  id: string;
  name: string;
  purpose: string;
  summary: string;
  stages: readonly FunnelStage[];
}

export interface PairedMetric {
  id: string;
  commercialLabel: string;
  commercialValue: string;
  harmLabel: string;
  harmValue: string;
  reading: string;
}

export const ANALYTICS_DEFAULT_RANGE = { from: "2026-08-01", to: "2026-08-18" };

export const ANALYTICS_FUNNELS: readonly AnalyticsFunnel[] = [
  { id: "acquisition", name: "Acquisition to first paid play", purpose: "Registration, NIN verification and first paid play", summary: "32.8% of started registrations reached first paid play; the largest absolute drop is before NIN submission.", stages: [
    { id: "registration-started", name: "Registration started", count: 184_220, conversion: 100, source: "UX telemetry" },
    { id: "nin-submitted", name: "NIN submitted", count: 129_440, conversion: 70.3, source: "Server event" },
    { id: "nin-verified", name: "NIN verified", count: 113_090, conversion: 61.4, source: "Server event" },
    { id: "first-paid-play", name: "First paid play", count: 60_380, conversion: 32.8, source: "Server event" },
  ] },
  { id: "ussd", name: "USSD play", purpose: "Session start through confirmed ticket", summary: "68.1% of USSD sessions produced a confirmed paid ticket; menu expiry accounts for most abandonment.", stages: [
    { id: "ussd-session", name: "USSD session started", count: 98_410, conversion: 100, source: "Server event" },
    { id: "game-selected", name: "Game selected", count: 82_300, conversion: 83.6, source: "Server event" },
    { id: "ticket-confirmed", name: "Ticket confirmed", count: 67_020, conversion: 68.1, source: "Server event" },
  ] },
  { id: "funding", name: "Funding", purpose: "Funding intent through Play Balance credit", summary: "84.7% of funding intents were credited; provider timeouts remain visible beside completion.", stages: [
    { id: "funding-intent", name: "Funding intent created", count: 144_820, conversion: 100, source: "Server event" },
    { id: "provider-confirmed", name: "Provider confirmed", count: 125_110, conversion: 86.4, source: "Server event" },
    { id: "balance-credited", name: "Play Balance credited", count: 122_720, conversion: 84.7, source: "Server event" },
  ] },
  { id: "payout", name: "Payout", purpose: "Withdrawal request through delivery", summary: "91.2% of withdrawal requests were delivered in the selected range; declined requests are included, not discarded.", stages: [
    { id: "withdrawal-requested", name: "Withdrawal requested", count: 62_480, conversion: 100, source: "Server event" },
    { id: "withdrawal-approved", name: "Withdrawal approved", count: 58_290, conversion: 93.3, source: "Server event" },
    { id: "withdrawal-delivered", name: "Provider delivered", count: 56_980, conversion: 91.2, source: "Server event" },
  ] },
  { id: "cross-game", name: "Cross-game", purpose: "First game through a paid play in the second game", summary: "14.6% of single-game players tried the other game without an incentive prompt.", stages: [
    { id: "single-game", name: "Active in one game", count: 204_330, conversion: 100, source: "Server event" },
    { id: "second-game-view", name: "Second game viewed", count: 48_910, conversion: 23.9, source: "UX telemetry" },
    { id: "second-game-paid", name: "Second game paid play", count: 29_820, conversion: 14.6, source: "Server event" },
  ] },
  { id: "geo", name: "Geo attribution", purpose: "Location consent through attributed paid activity", summary: "88.4% of paid activity has a state attribution; coarse state code is retained, not fine-grained coordinates.", stages: [
    { id: "eligible-activity", name: "Eligible paid activity", count: 1_284_220, conversion: 100, source: "Server event" },
    { id: "consent-resolved", name: "Location consent resolved", count: 1_198_440, conversion: 93.3, source: "Server event" },
    { id: "state-attributed", name: "State attributed", count: 1_135_420, conversion: 88.4, source: "Server event" },
  ] },
];

export const PAIRED_METRICS: readonly PairedMetric[] = [
  { id: "paid-play", commercialLabel: "Paid-play conversion", commercialValue: "32.8%", harmLabel: "First-day loss-limit contacts", harmValue: "0.7%", reading: "Conversion is shown with early-session harm contact rate for the same acquired cohort." },
  { id: "ggr", commercialLabel: "GGR", commercialValue: "₦96.4m", harmLabel: "Active deposit-limit hits", harmValue: "12,840", reading: "Revenue and deposit-limit friction share the identical date and player scope." },
  { id: "cross-game", commercialLabel: "Cross-game adoption", commercialValue: "14.6%", harmLabel: "Reality checks acknowledged", harmValue: "38,112", reading: "Adoption is paired with the session safeguards triggered across both games." },
  { id: "funding", commercialLabel: "Funding completion", commercialValue: "84.7%", harmLabel: "Repeated failed funding attempts", harmValue: "3.1%", reading: "Completion does not hide repeated provider failures or potentially risky retry behaviour." },
];

export const ANALYTICS_EVENT_FIELDS = [
  "event_id", "event_name", "occurred_at_utc", "pseudonymised_player_id", "session_id", "channel", "app_version", "game_code", "state_code",
] as const;

export const ANALYTICS_EXCLUDED_FIELDS = [
  "Raw MSISDN", "NIN", "BVN", "Date of birth", "Fine-grained coordinates",
] as const;
