import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { PromotionsConsole } from "./PromotionsConsole";

const promotions = vi.fn();
const monthlyDraws = vi.fn();
const emergencyKillPromotion = vi.fn();
const proposeChange = vi.fn();
const triggerMonthlyDraw = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      promotions: (...args: unknown[]) => promotions(...args),
      monthlyDraws: (...args: unknown[]) => monthlyDraws(...args),
      emergencyKillPromotion: (...args: unknown[]) => emergencyKillPromotion(...args),
      proposeChange: (...args: unknown[]) => proposeChange(...args),
      triggerMonthlyDraw: (...args: unknown[]) => triggerMonthlyDraw(...args),
    },
  };
});

beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", {
    configurable: true,
    value() {
      this.setAttribute("open", "");
    },
  });
  Object.defineProperty(HTMLDialogElement.prototype, "close", {
    configurable: true,
    value() {
      this.removeAttribute("open");
    },
  });
});

const MOCK_PROMOTIONS = [
  {
    campaign_key: "weekend_double_odds",
    name: "Weekend Double Odds Boost",
    description: "Marketing-subsidized double winnings boost on 1st winning bet under ₦100.",
    status: "ENABLED" as const,
    version: 1,
    rules: {
      maxStakeKobo: 100_00,
      maxBonusKobo: 1000_00,
      totalBudgetKobo: 500000_00,
    },
    stats: {
      claims_count: 14,
      total_boost_awarded_kobo: 14000_00,
    },
  },
  {
    campaign_key: "monthly_vip_draw",
    name: "Monthly VIP Draw Pool",
    description: "Promotional pool funded by 1% turnover rake.",
    status: "ENABLED" as const,
    version: 1,
    rules: {
      qualifyingStakeKobo: 20000_00,
      rakeBasisPoints: 100,
      prizeDistribution: [50, 30, 20],
    },
    stats: {
      latest_pool_tickets: 42,
      total_pools_completed: 3,
    },
  },
  {
    campaign_key: "velocity_bonus",
    name: "Velocity Milestone Bonus Wallet",
    description: "Awards ₦500 non-withdrawable bonus play credit after 30 rounds played.",
    status: "ENABLED" as const,
    version: 1,
    rules: {
      targetRounds: 30,
      bonusAmountKobo: 500_00,
      expiryDays: 7,
    },
    stats: {
      milestones_achieved: 8,
      total_bonus_credited_kobo: 4000_00,
    },
  },
];

const MOCK_DRAWS = [
  {
    id: 1,
    month_period: "2026-08",
    status: "DISBURSED",
    total_turnover_kobo: 1000000_00,
    allocated_prize_pool_kobo: 10000_00,
    total_tickets_issued: 50,
    qualifying_players_count: 5,
    drawn_at: "2026-09-01T00:10:00Z",
    winners: [{ rank: 1, player_id: 101, percentage: 50, amount_kobo: 5000_00 }],
  },
];

beforeEach(() => {
  vi.clearAllMocks();
  promotions.mockResolvedValue({ promotions: MOCK_PROMOTIONS });
  monthlyDraws.mockResolvedValue({ draw_pools: MOCK_DRAWS });
});

describe("PromotionsConsole", () => {
  it("renders all three promotional campaigns and monthly draws history", async () => {
    render(<PromotionsConsole />);

    expect(await screen.findByText("Weekend Double Odds Boost")).toBeInTheDocument();
    expect(screen.getByText("Monthly VIP Draw Pool")).toBeInTheDocument();
    expect(screen.getByText("Velocity Milestone Bonus Wallet")).toBeInTheDocument();

    expect(screen.getByText("2026-08")).toBeInTheDocument();
    expect(screen.getByText("DISBURSED")).toBeInTheDocument();
  });

  it("triggers emergency kill switch with mandatory justification", async () => {
    emergencyKillPromotion.mockResolvedValueOnce({
      message: "Promotion disabled",
      campaign_key: "weekend_double_odds",
      status: "DISABLED",
    });

    render(<PromotionsConsole />);
    await screen.findByText("Weekend Double Odds Boost");

    const killButtons = screen.getAllByRole("button", { name: "Kill Switch" });
    fireEvent.click(killButtons[0]);

    expect(screen.getByText(/Emergency Kill: Weekend Double Odds Boost/)).toBeInTheDocument();

    const submitBtn = screen.getByRole("button", { name: "Confirm Emergency Kill" });
    fireEvent.click(submitBtn);

    expect(
      await screen.findByText("An emergency justification is mandatory under LSLGA governance.")
    ).toBeInTheDocument();

    const textarea = screen.getByLabelText(/Emergency Justification/);
    fireEvent.change(textarea, { target: { value: "Exceeded marketing weekend threshold." } });
    fireEvent.click(submitBtn);

    expect(emergencyKillPromotion).toHaveBeenCalledWith("weekend_double_odds", {
      justification: "Exceeded marketing weekend threshold.",
    });
  });

  it("submits a Maker-Checker proposal for campaign rule adjustments", async () => {
    proposeChange.mockResolvedValueOnce({
      id: 99,
      change_type: "promotional_config_publish",
      status: "pending",
    });

    render(<PromotionsConsole />);
    await screen.findByText("Weekend Double Odds Boost");

    const proposeButtons = screen.getAllByRole("button", { name: "Propose Changes" });
    fireEvent.click(proposeButtons[0]);

    expect(screen.getByText(/Propose Changes: Weekend Double Odds Boost/)).toBeInTheDocument();

    const justificationArea = screen.getByLabelText(/Maker Justification/);
    fireEvent.change(justificationArea, { target: { value: "Increase weekend budget for holiday." } });

    const submitBtn = screen.getByRole("button", { name: "Submit Proposal" });
    fireEvent.click(submitBtn);

    expect(proposeChange).toHaveBeenCalledWith(
      expect.objectContaining({
        change_type: "promotional_config_publish",
        justification: "Increase weekend budget for holiday.",
      })
    );
  });

  it("dispatches monthly draw execution", async () => {
    triggerMonthlyDraw.mockResolvedValueOnce({
      message: "Monthly draw execution dispatched for period: 2026-08",
    });

    render(<PromotionsConsole />);
    await screen.findByText("Monthly VIP Draw Pool");

    const triggerBtn = screen.getByRole("button", { name: "Trigger Draw" });
    fireEvent.click(triggerBtn);

    expect(triggerMonthlyDraw).toHaveBeenCalled();
  });
});
