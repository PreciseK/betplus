import { get, post } from "./http";

type ApiLimit = {
  key: string;
  label: string;
  scope: string;
  unit: "kobo" | "minutes";
  current_value: number;
  pending_value?: number;
  pending_effective_at?: string;
};

type ApiOption = { id: string; label: string; detail: string; duration_hours: number };

function toLimit(l: ApiLimit) {
  return {
    key: l.key as "deposit-daily" | "deposit-weekly" | "deposit-monthly" | "stake-daily" | "stake-weekly" | "session-time",
    label: l.label,
    scope: l.scope,
    unit: l.unit,
    currentValue: l.current_value,
    pendingValue: l.pending_value,
    pendingEffectiveAt: l.pending_effective_at,
  };
}

function toOption(o: ApiOption) {
  return { id: o.id, label: o.label, detail: o.detail, durationHours: o.duration_hours };
}

/**
 * Real Story 5.1-5.6 implementation of apps/web's ResponsiblePlayGateway
 * (apps/web/src/mocks/responsiblePlay.ts). 'velocity-paused' is a valid status value
 * in the frontend's type but this backend never returns it — Story 5.7's velocity
 * flags surface to a review queue (no UI for that queue exists yet, Epic 6) and never
 * themselves block play.
 */
export const responsiblePlayGateway = {
  async load() {
    const snapshot = await get<{
      status: string; status_ends_at?: string;
      limits: ApiLimit[]; cool_off_options: ApiOption[]; self_exclusion_options: ApiOption[];
      net_position_kobo: { sevenDays: number; thirtyDays: number; ninetyDays: number };
      withdrawal_available: true;
    }>("/responsible-play");

    return {
      status: snapshot.status as "active" | "cool-off" | "self-excluded" | "registry-excluded" | "registry-unavailable" | "velocity-paused",
      statusEndsAt: snapshot.status_ends_at,
      limits: snapshot.limits.map(toLimit),
      coolOffOptions: snapshot.cool_off_options.map(toOption),
      selfExclusionOptions: snapshot.self_exclusion_options.map(toOption),
      netPositionKobo: snapshot.net_position_kobo,
      withdrawalAvailable: snapshot.withdrawal_available,
    };
  },

  async updateLimit(key: string, value: number) {
    const limit = await post<ApiLimit>("/responsible-play/limits", { key, value });

    return toLimit(limit);
  },

  async startCoolOff(optionId: string) {
    const result = await post<{ status: "cool-off"; status_ends_at: string }>("/responsible-play/cool-off", { option_id: optionId });

    return { status: result.status, statusEndsAt: result.status_ends_at };
  },

  async selfExclude(optionId: string) {
    const result = await post<{ status: "self-excluded"; status_ends_at: string }>("/responsible-play/self-exclude", { option_id: optionId });

    return { status: result.status, statusEndsAt: result.status_ends_at };
  },
};
