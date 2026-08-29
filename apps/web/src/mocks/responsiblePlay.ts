export type ResponsiblePlayStatus =
  | "active"
  | "cool-off"
  | "self-excluded"
  | "registry-excluded"
  | "registry-unavailable"
  | "velocity-paused";

export type LimitKey =
  | "deposit-daily"
  | "deposit-weekly"
  | "deposit-monthly"
  | "stake-daily"
  | "stake-weekly"
  | "session-time";

export interface PlayerLimit {
  key: LimitKey;
  label: string;
  scope: string;
  unit: "kobo" | "minutes";
  currentValue: number;
  pendingValue?: number;
  pendingEffectiveAt?: string;
}

export interface DurationOption {
  id: string;
  label: string;
  detail: string;
  durationHours: number;
}

export interface ResponsiblePlaySnapshot {
  status: ResponsiblePlayStatus;
  statusEndsAt?: string;
  limits: PlayerLimit[];
  coolOffOptions: DurationOption[];
  selfExclusionOptions: DurationOption[];
  netPositionKobo: {
    sevenDays: number;
    thirtyDays: number;
    ninetyDays: number;
  };
  withdrawalAvailable: true;
}

export interface ResponsiblePlayGateway {
  load(): Promise<ResponsiblePlaySnapshot>;
  updateLimit(key: LimitKey, value: number): Promise<PlayerLimit>;
  startCoolOff(optionId: string): Promise<{ status: "cool-off"; statusEndsAt: string }>;
  selfExclude(optionId: string): Promise<{ status: "self-excluded"; statusEndsAt: string }>;
  deactivateAccount?(reason?: string): Promise<{ status: string; message: string }>;
}

const snapshot: ResponsiblePlaySnapshot = {
  status: "active",
  limits: [
    { key: "deposit-daily", label: "Daily deposit", scope: "Across every game", unit: "kobo", currentValue: 2_000_000 },
    { key: "deposit-weekly", label: "Weekly deposit", scope: "Across every game", unit: "kobo", currentValue: 7_500_000 },
    { key: "deposit-monthly", label: "Monthly deposit", scope: "Across every game", unit: "kobo", currentValue: 20_000_000 },
    { key: "stake-daily", label: "Daily stake", scope: "BlackRed and Heritage combined", unit: "kobo", currentValue: 1_500_000 },
    { key: "stake-weekly", label: "Weekly stake", scope: "BlackRed and Heritage combined", unit: "kobo", currentValue: 5_000_000 },
    { key: "session-time", label: "Session time", scope: "Web and app", unit: "minutes", currentValue: 60 },
  ],
  coolOffOptions: [
    { id: "cool-off-24h", label: "24 hours", detail: "Until this time tomorrow", durationHours: 24 },
    { id: "cool-off-7d", label: "7 days", detail: "One full week", durationHours: 24 * 7 },
    { id: "cool-off-30d", label: "30 days", detail: "Thirty full days", durationHours: 24 * 30 },
  ],
  selfExclusionOptions: [
    { id: "exclude-6m", label: "6 months", detail: "Minimum exclusion period", durationHours: 24 * 183 },
    { id: "exclude-1y", label: "1 year", detail: "Twelve months", durationHours: 24 * 365 },
    { id: "exclude-5y", label: "5 years", detail: "Long-term exclusion", durationHours: 24 * 365 * 5 },
  ],
  netPositionKobo: {
    sevenDays: -390_000,
    thirtyDays: 125_000,
    ninetyDays: -1_840_000,
  },
  withdrawalAvailable: true,
};

function copySnapshot(): ResponsiblePlaySnapshot {
  return {
    ...snapshot,
    limits: snapshot.limits.map((limit) => ({ ...limit })),
    coolOffOptions: snapshot.coolOffOptions.map((option) => ({ ...option })),
    selfExclusionOptions: snapshot.selfExclusionOptions.map((option) => ({ ...option })),
    netPositionKobo: { ...snapshot.netPositionKobo },
  };
}

/** Temporary Epic 5 adapter. Replace when api-types publishes responsible-play contracts. */
export const mockResponsiblePlayGateway: ResponsiblePlayGateway = {
  async load() {
    await Promise.resolve();
    return copySnapshot();
  },
  async updateLimit(key, value) {
    await Promise.resolve();
    const limit = snapshot.limits.find((candidate) => candidate.key === key);
    if (!limit || !Number.isSafeInteger(value) || value <= 0) throw new Error("LIMIT_INVALID");

    if (value <= limit.currentValue) {
      limit.currentValue = value;
      delete limit.pendingValue;
      delete limit.pendingEffectiveAt;
    } else {
      limit.pendingValue = value;
      limit.pendingEffectiveAt = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString();
    }
    return { ...limit };
  },
  async startCoolOff(optionId) {
    await Promise.resolve();
    const option = snapshot.coolOffOptions.find((candidate) => candidate.id === optionId);
    if (!option) throw new Error("DURATION_INVALID");
    const statusEndsAt = new Date(Date.now() + option.durationHours * 60 * 60 * 1000).toISOString();
    snapshot.status = "cool-off";
    snapshot.statusEndsAt = statusEndsAt;
    return { status: "cool-off", statusEndsAt };
  },
  async selfExclude(optionId) {
    await Promise.resolve();
    const option = snapshot.selfExclusionOptions.find((candidate) => candidate.id === optionId);
    if (!option) throw new Error("DURATION_INVALID");
    const statusEndsAt = new Date(Date.now() + option.durationHours * 60 * 60 * 1000).toISOString();
    snapshot.status = "self-excluded";
    snapshot.statusEndsAt = statusEndsAt;
    return { status: "self-excluded", statusEndsAt };
  },
  async deactivateAccount(reason) {
    await Promise.resolve();
    return {
      status: "deactivated",
      message: "Account deactivated. Data is retained for 7 years per statutory NLRC compliance.",
    };
  },
};
