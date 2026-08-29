import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { BackOfficeApiError } from "@betplus/api-client";
import { Player360 } from "./Player360";

const player = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: { ...actual.backOfficeGateway, player: (...args: unknown[]) => player(...args) },
  };
});

const FIXTURE = {
  profile: {
    id: 42, msisdn: "+2348031234567", registered_name: "Adaeze Nwosu", display_name: "Adaeze N.",
    kyc_tier: 1, kyc_status: "verified", account_status: "active", residency_status: "resident",
    registration_channel: "web", created_at: "2026-01-01T00:00:00Z", last_login_at: null,
    has_verified_nin: true, has_verified_bvn: false,
  },
  balances: { play_balance_kobo: 1_250_000, winnings_balance_kobo: 875_000 },
  rg_status: { protection: null, registry_status: "clear" },
  tickets: [{ reference: "BR-1", game_code: "BLACKRED", stake_kobo: 100_000, status: "SETTLED", won: true, net_credit_kobo: 500_000, created_at: "2026-08-01T00:00:00Z" }],
  payments: {
    deposits: [{ reference: "DEP-1", amount_kobo: 500_000, status: "paid", created_at: "2026-08-01T00:00:00Z" }],
    payouts: [],
  },
  kyc_records: [{ id_type: "nin", verification_method: "automated", opay_name_match: true, verified_at: "2026-08-01T00:00:00Z" }],
  notification_history: [],
};

describe("Player360", () => {
  it("shows nothing until a player is searched, then calls the real gateway with the query", async () => {
    player.mockResolvedValueOnce(FIXTURE);
    render(<Player360 />);

    expect(screen.queryByRole("heading", { name: "Adaeze N." })).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Player ID"), { target: { value: "42" } });
    fireEvent.click(screen.getByRole("button", { name: "Open player" }));

    expect(await screen.findByRole("heading", { name: "Adaeze N." })).toBeInTheDocument();
    expect(player).toHaveBeenCalledWith("42");
  });

  it("shows real balances, not fabricated ones", async () => {
    player.mockResolvedValueOnce(FIXTURE);
    render(<Player360 />);
    fireEvent.change(screen.getByLabelText("Player ID"), { target: { value: "42" } });
    fireEvent.click(screen.getByRole("button", { name: "Open player" }));

    const balances = await screen.findByLabelText("Player balances");
    expect(balances).toHaveTextContent(/₦12,500/);
    expect(balances).toHaveTextContent(/₦8,750/);
  });

  it("shows a real not-found state on a 404, not a fabricated fallback player", async () => {
    player.mockRejectedValueOnce(new BackOfficeApiError(404, { message: "not found" }));
    render(<Player360 />);

    fireEvent.change(screen.getByLabelText("Player ID"), { target: { value: "999" } });
    fireEvent.click(screen.getByRole("button", { name: "Open player" }));

    expect(await screen.findByRole("heading", { name: /No player found/ })).toBeInTheDocument();
  });

  it("shows only real ticket data on the tickets view, never a support-case narrative that has no backend", async () => {
    player.mockResolvedValueOnce(FIXTURE);
    render(<Player360 view="tickets" />);
    fireEvent.change(screen.getByLabelText("Player ID"), { target: { value: "42" } });
    fireEvent.click(screen.getByRole("button", { name: "Open player" }));

    expect(await screen.findByRole("heading", { name: "Ticket history" })).toBeInTheDocument();
    expect(screen.getByText("BR-1")).toBeInTheDocument();
    expect(screen.queryByText(/paid but got nothing/i)).not.toBeInTheDocument();
  });
});
