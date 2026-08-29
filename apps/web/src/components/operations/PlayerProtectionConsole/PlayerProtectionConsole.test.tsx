import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { PlayerProtectionConsole } from "./PlayerProtectionConsole";

const playerProtectionReviews = vi.fn();
const playerProtectionLimits = vi.fn();
const playerProtectionExclusions = vi.fn();
const resolveVelocityFlag = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      playerProtectionReviews: (...args: unknown[]) => playerProtectionReviews(...args),
      playerProtectionLimits: (...args: unknown[]) => playerProtectionLimits(...args),
      playerProtectionExclusions: (...args: unknown[]) => playerProtectionExclusions(...args),
      resolveVelocityFlag: (...args: unknown[]) => resolveVelocityFlag(...args),
    },
  };
});

beforeEach(() => {
  vi.clearAllMocks();
});

describe("PlayerProtectionConsole", () => {
  it("shows real velocity-flag reviews and resolves one via the real endpoint", async () => {
    playerProtectionReviews.mockResolvedValue({
      reviews: [{
        id: 9, player_id: 4, player_reference: "BP-4", registered_name: "Ada Okafor",
        game_code: "BLACKRED", flag_type: "rapid_stake_escalation", detail: "Stake tripled within one hour.",
        status: "open", created_at: "2026-08-20T12:00:00Z", resolved_at: null,
      }],
    });
    resolveVelocityFlag.mockResolvedValueOnce({ id: 9, status: "resolved" });

    render(<PlayerProtectionConsole view="reviews" />);

    expect(await screen.findByText("Ada Okafor")).toBeInTheDocument();
    expect(screen.getByText("Stake tripled within one hour.")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Mark resolved" }));

    expect(await screen.findByRole("status")).toHaveTextContent("Review #9 marked resolved");
    expect(resolveVelocityFlag).toHaveBeenCalledWith(9);
  });

  it("shows real limit usage figures, not a fabricated progress bar", async () => {
    playerProtectionLimits.mockResolvedValue({
      limits: [{
        player_id: 4, player_reference: "BP-4", registered_name: "Ada Okafor",
        limit_key: "stake-daily", limit_value: 50_000, spent_kobo: 50_000, unit: "kobo", status: "reached",
      }],
    });

    render(<PlayerProtectionConsole view="limits" />);

    expect(await screen.findByText("Reached")).toBeInTheDocument();
    expect(screen.getByText("₦500 of ₦500")).toBeInTheDocument();
  });

  it("shows real active exclusions read from playerProtectionEvent", async () => {
    playerProtectionExclusions.mockResolvedValue({
      exclusions: [{
        id: 2, player_id: 4, player_reference: "BP-4", registered_name: "Ada Okafor",
        type: "self-exclusion", started_at: "2026-08-01T00:00:00Z", ends_at: "2027-02-01T00:00:00Z",
      }],
    });

    render(<PlayerProtectionConsole view="exclusions" />);

    expect(await screen.findByText("Self-exclusion")).toBeInTheDocument();
  });
});
