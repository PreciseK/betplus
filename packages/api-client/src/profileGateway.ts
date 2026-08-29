import { get, post } from "./http";

/**
 * Real implementation backing the player-facing "who am I" read
 * (App\Http\Controllers\Api\V1\PlayerProfileController::show, GET /v1/me).
 */
export const profileGateway = {
  async loadProfile() {
    const profile = await get<{
      registered_name: string;
      msisdn: string;
      kyc_tier: number;
      account_status: string;
    }>("/me");

    return {
      registeredName: profile.registered_name,
      msisdn: profile.msisdn,
      kycTier: profile.kyc_tier,
      accountStatus: profile.account_status,
    };
  },

  async deactivateAccount(reason?: string) {
    const response = await post<{
      status: string;
      account_status: string;
      deactivated_at: string;
      cooling_off_ends_at: string;
      message: string;
    }>("/account/deactivate", { reason });

    return {
      status: response.status,
      accountStatus: response.account_status,
      deactivatedAt: response.deactivated_at,
      coolingOffEndsAt: response.cooling_off_ends_at,
      message: response.message,
    };
  },
};
