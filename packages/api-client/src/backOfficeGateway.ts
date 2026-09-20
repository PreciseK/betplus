const PLAYER_API_BASE = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/v1";
const BACK_OFFICE_API_BASE = process.env.NEXT_PUBLIC_BACKOFFICE_API_BASE_URL
  ?? PLAYER_API_BASE.replace(/\/v1\/?$/, "/backoffice/v1");
const TOKEN_KEY = "betplus.operator.access-token";

export class BackOfficeApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly body: Record<string, unknown>,
  ) {
    super(typeof body.message === "string" ? body.message : `Back-office request failed (${status})`);
  }
}

export interface BackOfficeRollupRow {
  day: string;
  event_name: string;
  channel: string;
  game_code: string | null;
  state_code: string | null;
  event_count: number;
  distinct_player_count: number;
}

export interface BackOfficeChange {
  id: number;
  change_type: string;
  status: string;
  payload: Record<string, unknown>;
  before_snapshot: Record<string, unknown> | null;
  maker_id: number;
  maker_justification: string;
  submitted_at: string | null;
  checker_id: number | null;
  checker_decision_at: string | null;
  rejection_reason: string | null;
  applied_at: string | null;
}

export interface BackOfficeReconciliationException {
  id: number;
  check_type: string;
  subject_id: string;
  expected_kobo: number;
  actual_kobo: number;
  difference_kobo: number;
  severity: string;
  status: string;
  detected_at: string;
  resolved_at: string | null;
}

export interface BackOfficeGame {
  game_code: string;
  engine_version: string;
  status: string;
  min_stake_kobo: number;
  max_stake_kobo: number;
  enabled_channels: string[];
  enabled_states: string[];
}

export interface BackOfficeJurisdiction {
  state_code: string;
  licence_number: string;
  issued_at: string;
  expires_at: string;
  is_expired: boolean;
  expiry_alert: boolean;
  ruleset_version: string;
  remittance_status: string;
  activity_volume: number;
  resident_wht_rate_basis_points: number;
  non_resident_wht_rate_basis_points: number;
}

export interface BackOfficeAuditEvent {
  id: number;
  actor_type: string;
  actor_id: number | null;
  action: string;
  target_table: string | null;
  target_id: number | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  reason: string | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
}

export interface BackOfficeDailySummary {
  date: string;
  game_code: string | null;
  gross_stakes_kobo: number;
  gross_wins_kobo: number;
  net_gaming_revenue_kobo: number;
  deposits_kobo: number;
  payouts_kobo: number;
  new_players: number;
  active_players: number;
  verified_players_total: number;
  reconciliation_exceptions: number;
  payout_holds: number;
  safer_play_reviews_opened: number;
}

export interface BackOfficeVelocityReview {
  id: number;
  player_id: number;
  player_reference: string;
  registered_name: string | null;
  game_code: string;
  flag_type: string;
  detail: string;
  status: string;
  created_at: string;
  resolved_at: string | null;
}

export interface BackOfficeLimitUsage {
  player_id: number;
  player_reference: string;
  registered_name: string | null;
  limit_key: string;
  limit_value: number;
  spent_kobo: number;
  unit: string;
  status: "near_threshold" | "reached";
}

export interface BackOfficeProtectionEvent {
  id: number;
  player_id: number;
  player_reference: string;
  registered_name: string | null;
  type: string;
  started_at: string;
  ends_at: string;
}

export interface BackOfficeDeposit {
  id: number;
  reference: string;
  player_id: number;
  player_reference: string;
  registered_name: string | null;
  amount_kobo: number;
  fee_kobo: number;
  provider_collection_id: string | null;
  status: string;
  paid_at: string | null;
  created_at: string;
}

export interface BackOfficePayout {
  id: number;
  reference: string;
  player_id: number;
  player_reference: string;
  registered_name: string | null;
  kind: string;
  amount_kobo: number;
  destination_label: string;
  provider_status: string;
  manual_review_required: boolean;
  dispatched_at: string | null;
  confirmed_at: string | null;
  created_at: string;
}

export interface BackOfficeHeritageCatalogueItem {
  number: number;
  canonical_name: string;
  local_name: string;
  origin: string;
  context: string;
  slot: string;
  layer_priority: number;
  depiction_constraints: string;
  advisor_sign_off_reference: string | null;
  published_at: string | null;
  publication_status: "approved" | "preview-only";
}

export interface BackOfficeInstitutionUser {
  id: number;
  email: string;
  display_name: string;
  role: string;
  status: string;
  mfa_confirmed_at: string | null;
  last_login_at: string | null;
  created_at: string;
}

/** One step's raw event volume within a funnel window — App\Domain\Analytics\FunnelService. */
export interface BackOfficeFunnelStep {
  step: string;
  eventCount: number;
}

export type BackOfficeFunnel =
  | { measurable: true; steps: BackOfficeFunnelStep[]; conversionRate: number | null }
  | { measurable: false; reason: string };

type QueryValue = string | number | boolean | null | undefined;

function readToken() {
  if (typeof window === "undefined") return null;
  return window.sessionStorage.getItem(TOKEN_KEY);
}

function writeToken(token: string) {
  if (typeof window !== "undefined") window.sessionStorage.setItem(TOKEN_KEY, token);
}

function toQuery(query?: Record<string, QueryValue>) {
  if (!query) return "";
  const params = new URLSearchParams();
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") params.set(key, String(value));
  });
  const value = params.toString();
  return value ? `?${value}` : "";
}

export interface BackOfficePrizeTableTier {
  positions: number;
  multiplier_hundredths: number;
  probability_numerator: number;
  probability_denominator: number;
}

export interface BackOfficePrizeTable {
  id: number;
  game_code: string;
  state_code: string | null;
  version: string;
  status: string;
  effective_at: string;
  actuarial_cert_ref: string | null;
  published_at: string | null;
  tiers: BackOfficePrizeTableTier[];
}

export interface BackOfficePrizeTablePreset {
  key: "fair" | "good" | "best";
  label: string;
  tiers: Array<BackOfficePrizeTableTier & { gross_rtp_basis_points: number }>;
}

export type EconomicsModel = "FIXED_RTP" | "BALANCED_HYBRID" | "DAILY_LOSS_STOP" | "PARI_MUTUEL_POOL";

export interface BackOfficeGameEconomicsConfig {
  id: number;
  game_code: string;
  version: string;
  status: string;
  active_model: EconomicsModel;
  params: Record<string, unknown>;
  effective_at: string;
  published_at: string | null;
}

export interface BackOfficePromotion {
  campaign_key: string;
  name: string;
  description: string;
  status: "ENABLED" | "DISABLED";
  version: number;
  rules: Record<string, unknown>;
  stats: Record<string, unknown>;
}

export interface BackOfficeMonthlyDrawPool {
  id: number;
  month_period: string;
  status: string;
  total_turnover_kobo: number;
  allocated_prize_pool_kobo: number;
  total_tickets_issued: number;
  qualifying_players_count: number;
  drawn_at: string | null;
  winners: Array<{
    rank: number;
    player_id: number;
    percentage: number;
    amount_kobo: number;
    ticket_number?: number;
  }> | null;
}

async function request<T>(
  path: string,
  options: { method?: "GET" | "POST" | "PATCH" | "DELETE"; body?: unknown; query?: Record<string, QueryValue>; authenticated?: boolean } = {},
): Promise<T> {
  const token = options.authenticated === false ? null : readToken();
  const headers: Record<string, string> = { Accept: "application/json" };
  if (options.body !== undefined) headers["Content-Type"] = "application/json";
  if (token) headers.Authorization = `Bearer ${token}`;

  const response = await fetch(`${BACK_OFFICE_API_BASE}${path}${toQuery(options.query)}`, {
    method: options.method ?? "GET",
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });
  const body = await response.json().catch(() => ({})) as Record<string, unknown>;
  if (!response.ok) {
    if (response.status === 401 && options.authenticated !== false) {
      backOfficeGateway.clearSession();
      if (typeof window !== "undefined" && !window.location.pathname.endsWith("/back-office")) {
        window.location.replace("/back-office?session_expired=1");
      }
    }
    throw new BackOfficeApiError(response.status, body);
  }
  return body as T;
}

export const backOfficeGateway = {
  hasSession: () => Boolean(readToken()),

  clearSession() {
    if (typeof window !== "undefined") {
      window.sessionStorage.removeItem(TOKEN_KEY);
      window.sessionStorage.removeItem("betplus.operator.session");
    }
  },

  async beginMfa(email: string, password: string) {
    return request<{ status: "mfa_required" | "mfa_setup_required"; challenge_id: string; secret?: string }>(
      "/auth/sign-in",
      { method: "POST", body: { email, password }, authenticated: false },
    );
  },

  async verifyMfa(challengeId: string, code: string) {
    const result = await request<{ status: "ok"; access_token: string; expires_in: number; role: string }>(
      "/auth/mfa",
      { method: "POST", body: { challenge_id: challengeId, code }, authenticated: false },
    );
    writeToken(result.access_token);
    return result;
  },

  rollups(query: { from: string; to: string; game_code?: string; event_name?: string; state_code?: string; channel?: string }) {
    return request<{ window: { from: string; to: string }; rows: BackOfficeRollupRow[] }>("/analytics/rollups", { query });
  },

  funnels(query: { from: string; to: string }) {
    return request<{ window: { from: string; to: string }; funnels: Record<string, BackOfficeFunnel> }>("/analytics/funnels", { query });
  },

  changes(status?: string, changeType?: string) {
    return request<{ changes: BackOfficeChange[] }>("/changes", { query: { status, change_type: changeType } });
  },

  proposeChange(payload: { change_type: string; payload: Record<string, unknown>; before_snapshot?: Record<string, unknown> | null; justification: string }) {
    return request<BackOfficeChange>("/changes", { method: "POST", body: payload });
  },

  approveChange(id: number) {
    return request<BackOfficeChange>(`/changes/${id}/approve`, { method: "POST" });
  },

  rejectChange(id: number, reason: string) {
    return request<BackOfficeChange>(`/changes/${id}/reject`, { method: "POST", body: { reason } });
  },

  reconciliation(status = "open") {
    return request<{ exceptions: BackOfficeReconciliationException[] }>("/reconciliation/exceptions", { query: { status } });
  },

  resolveReconciliation(id: number) {
    return request<{ id: number; status: string }>(`/reconciliation/exceptions/${id}/resolve`, { method: "POST" });
  },

  games() {
    return request<{ games: BackOfficeGame[] }>("/games");
  },

  updateGame(gameCode: string, payload: Record<string, unknown>) {
    return request<{ game_code: string; status: string }>(`/games/${gameCode}`, { method: "PATCH", body: payload });
  },

  prizeTables(gameCode?: string) {
    return request<{ prize_tables: BackOfficePrizeTable[] }>("/prize-tables", { query: { game_code: gameCode } });
  },

  prizeTablePresets(gameCode: string) {
    return request<{ presets: BackOfficePrizeTablePreset[] }>("/prize-tables/presets", { query: { game_code: gameCode } });
  },

  prizeTable(id: number) {
    return request<BackOfficePrizeTable>(`/prize-tables/${id}`);
  },

  createPrizeTable(payload: {
    game_code: string;
    state_code?: string | null;
    version: string;
    effective_at: string;
    actuarial_cert_ref?: string | null;
    tiers: BackOfficePrizeTableTier[];
  }) {
    return request<BackOfficePrizeTable & { gate_errors: string[] }>("/prize-tables", { method: "POST", body: payload });
  },

  updatePrizeTable(id: number, payload: {
    version: string;
    effective_at: string;
    actuarial_cert_ref?: string | null;
    tiers: BackOfficePrizeTableTier[];
  }) {
    return request<BackOfficePrizeTable & { gate_errors: string[] }>(`/prize-tables/${id}`, { method: "PATCH", body: payload });
  },

  deletePrizeTable(id: number) {
    return request<{ id: number; deleted: true }>(`/prize-tables/${id}`, { method: "DELETE" });
  },

  gameEconomicsConfigs(gameCode?: string) {
    return request<{ game_economics_configs: BackOfficeGameEconomicsConfig[] }>("/game-economics-configs", { query: { game_code: gameCode } });
  },

  createGameEconomicsConfig(payload: {
    game_code: string;
    version: string;
    active_model: EconomicsModel;
    params?: Record<string, unknown>;
    effective_at: string;
  }) {
    return request<BackOfficeGameEconomicsConfig & { gate_errors: string[] }>("/game-economics-configs", { method: "POST", body: payload });
  },

  updateGameEconomicsConfig(id: number, payload: {
    version: string;
    active_model: EconomicsModel;
    params?: Record<string, unknown>;
    effective_at: string;
  }) {
    return request<BackOfficeGameEconomicsConfig & { gate_errors: string[] }>(`/game-economics-configs/${id}`, { method: "PATCH", body: payload });
  },

  deleteGameEconomicsConfig(id: number) {
    return request<{ id: number; deleted: true }>(`/game-economics-configs/${id}`, { method: "DELETE" });
  },

  jurisdictions() {
    return request<{ states: BackOfficeJurisdiction[] }>("/jurisdictions");
  },

  auditLog(filters?: { actor_id?: number; action?: string; date_from?: string; date_to?: string }) {
    return request<{ events: BackOfficeAuditEvent[] }>("/audit-log", { query: filters });
  },

  dailySummary(date: string, gameCode?: string) {
    return request<BackOfficeDailySummary>("/daily-summary", { query: { date, game_code: gameCode } });
  },

  playerProtectionReviews(status = "open") {
    return request<{ reviews: BackOfficeVelocityReview[] }>("/player-protection/reviews", { query: { status } });
  },

  playerProtectionLimits() {
    return request<{ limits: BackOfficeLimitUsage[] }>("/player-protection/limits");
  },

  playerProtectionExclusions() {
    return request<{ exclusions: BackOfficeProtectionEvent[] }>("/player-protection/exclusions");
  },

  resolveVelocityFlag(id: number) {
    return request<{ id: number; status: string }>(`/velocity-flags/${id}/resolve`, { method: "POST" });
  },

  deposits(status?: string) {
    return request<{ deposits: BackOfficeDeposit[] }>("/deposits", { query: { status } });
  },

  /** A pending_review deposit was flagged by FundingService's own threshold, not
   *  proposed by an institutionUser — this applies immediately, no maker-checker. */
  approveDeposit(id: number) {
    return request<BackOfficeDeposit>(`/deposits/${id}/approve`, { method: "POST" });
  },

  rejectDeposit(id: number, reason: string) {
    return request<BackOfficeDeposit>(`/deposits/${id}/reject`, { method: "POST", body: { reason } });
  },

  payouts(filters?: { provider_status?: string; manual_review_required?: boolean }) {
    return request<{ payouts: BackOfficePayout[] }>("/payouts", { query: filters as Record<string, QueryValue> });
  },

  heritageCatalogue() {
    return request<{ items: BackOfficeHeritageCatalogueItem[] }>("/heritage-catalogue");
  },

  updateHeritageCatalogueItem(itemNumber: number, changes: Record<string, unknown>) {
    return request<BackOfficeHeritageCatalogueItem>(`/heritage-catalogue/${itemNumber}`, { method: "PATCH", body: changes });
  },

  publishHeritageCatalogueItem(itemNumber: number) {
    return request<BackOfficeHeritageCatalogueItem & { gate_errors: string[] }>(`/heritage-catalogue/${itemNumber}/publish`, { method: "POST" });
  },

  institutionUsers() {
    return request<{ users: BackOfficeInstitutionUser[] }>("/institution-users");
  },

  createInstitutionUser(payload: { email: string; display_name: string; role: string }) {
    return request<BackOfficeInstitutionUser & { one_time_password: string; totp_secret: string }>("/institution-users", { method: "POST", body: payload });
  },

  updateInstitutionUser(id: number, changes: { status?: string; role?: string }) {
    return request<BackOfficeInstitutionUser>(`/institution-users/${id}`, { method: "PATCH", body: changes });
  },

  upsertJurisdiction(payload: {
    state_code: string;
    licence_number: string;
    issued_at: string;
    expires_at: string;
    ruleset_version: string;
    remittance_status: string;
  }) {
    return request<{ state_code: string; expires_at: string }>("/jurisdictions", { method: "POST", body: payload });
  },

  player(id: string | number) {
    return request<{
      profile: {
        id: number; msisdn: string; registered_name: string; display_name: string | null;
        kyc_tier: number; kyc_status: string | null; account_status: string; residency_status: string | null;
        registration_channel: string; created_at: string; last_login_at: string | null;
        has_verified_nin: boolean; has_verified_bvn: boolean;
      };
      balances: { play_balance_kobo: number; winnings_balance_kobo: number; bonus_balance_kobo?: number };
      rg_status: { protection: { type: string; ends_at: string } | null; registry_status: string };
      tickets: Array<{ reference: string; game_code: string; stake_kobo: number; status: string; won: boolean | null; net_credit_kobo: number | null; created_at: string }>;
      payments: {
        deposits: Array<{ reference: string; amount_kobo: number; status: string; created_at: string }>;
        payouts: Array<{ reference: string; kind: string; amount_kobo: number; provider_status: string; created_at: string }>;
      };
      kyc_records: Array<{ id_type: string; verification_method: string; opay_name_match: boolean | null; verified_at: string | null }>;
      notification_history: Array<{ category: string; status: string; queued_at: string }>;
    }>(`/players/${encodeURIComponent(String(id))}`);
  },

  ticketReplay(reference: string) {
    return request<{
      reference: string;
      seed_hex: string;
      seed_algorithm: string;
      engine_version: string;
      prediction: string[];
      stored: { result: string[] | null; won: boolean | null; digest: string | null };
      replayed: { result: string[]; won: boolean; digest: string };
      matches: boolean;
    }>(`/tickets/${encodeURIComponent(reference)}/replay`);
  },

  requestFinancialReport(payload: { from: string; to: string; game_code?: string; state_code?: string }) {
    return request<{ id: number; status: string }>("/reports/financial", { method: "POST", body: payload });
  },

  reportStatus(id: number) {
    return request<{ id: number; report_type: string; status: string; row_count: number | null; failure_reason: string | null; download_url: string | null }>(`/reports/exports/${id}`);
  },

  promotions() {
    return request<{ promotions: BackOfficePromotion[] }>("/promotions");
  },

  promotion(key: string) {
    return request<BackOfficePromotion>(`/promotions/${encodeURIComponent(key)}`);
  },

  emergencyKillPromotion(key: string, payload?: { justification?: string }) {
    return request<{ message: string; campaign_key: string; status: "DISABLED" }>(
      `/promotions/${encodeURIComponent(key)}/emergency-kill`,
      { method: "POST", body: payload ?? {} },
    );
  },

  monthlyDraws() {
    return request<{ draw_pools: BackOfficeMonthlyDrawPool[] }>("/promotions/monthly-draws");
  },

  triggerMonthlyDraw(payload?: { month_period?: string }) {
    return request<{ message: string }>("/promotions/monthly-draws/trigger", {
      method: "POST",
      body: payload ?? {},
    });
  },
};
