import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { TurnoverProgress } from "./TurnoverProgress";

describe("TurnoverProgress", () => {
  it("shows exact stake progress and does not make it depend on game outcomes", () => {
    render(<TurnoverProgress turnover={{ depositAmountKobo: 1_000_000, stakedKobo: 420_000, requiredStakeKobo: 1_000_000, releasedPlayBalanceKobo: 0 }} />);

    expect(screen.getByText("₦4,200 of ₦10,000 staked")).toBeInTheDocument();
    expect(screen.getByText(/Game outcomes do not change this progress/i)).toBeInTheDocument();
    const progress = screen.getByRole("progressbar");
    expect(progress).toHaveAttribute("value", "420000");
    expect(progress).toHaveAttribute("max", "1000000");
  });
});
