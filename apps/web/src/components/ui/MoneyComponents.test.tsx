import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Amount } from "@/components/ui/Amount/Amount";
import { BalanceCard } from "@/components/ui/BalanceCard/BalanceCard";
import { TransactionRow } from "@/components/ui/TransactionRow/TransactionRow";
import { formatKobo, parseNairaInputToKobo } from "@/lib/money";

describe("money components", () => {
  it("formats integer kobo without floating-point arithmetic", () => {
    expect(formatKobo(123450)).toBe("₦1,234.50");
    expect(formatKobo(-10000)).toBe("−₦100");
    expect(parseNairaInputToKobo("1,234.50")).toBe(123450);
    expect(() => formatKobo(1.25)).toThrow(/integer number of kobo/i);
  });

  it("renders hidden, loading, and unavailable balances as distinct states", () => {
    const { rerender } = render(<BalanceCard kind="play" state="hidden" />);
    expect(screen.getByLabelText("Play Balance hidden")).toHaveTextContent("••••");

    rerender(<BalanceCard kind="play" state="loading" />);
    expect(screen.getByText("Loading balance…")).toBeInTheDocument();
    expect(screen.queryByText("••••")).not.toBeInTheDocument();

    rerender(<BalanceCard kind="play" state="unavailable" />);
    expect(screen.getByText("Balance unavailable")).toBeInTheDocument();
  });

  it("always names the balance rather than relying on colour", () => {
    render(<BalanceCard kind="winnings" amountKobo={765000} />);
    expect(screen.getByRole("heading", { name: "Winnings Balance" })).toBeInTheDocument();
    expect(screen.getByText("₦7,650")).toBeInTheDocument();
  });

  it("includes type, source, WAT timestamp, amount and explicit status", () => {
    render(
      <TransactionRow
        type="BlackRed win"
        source="BlackRed"
        timestamp="2026-08-13T10:30:00+01:00"
        timestampLabel="13 Aug 2026, 10:30 WAT"
        amountKobo={765000}
        status="paid"
      />,
    );

    expect(screen.getByText("BlackRed win")).toBeInTheDocument();
    expect(screen.getByText("13 Aug 2026, 10:30 WAT")).toBeInTheDocument();
    expect(screen.getByText("+₦7,650")).toBeInTheDocument();
    expect(screen.getByText("Paid")).toBeInTheDocument();
  });

  it("renders explicit zero without treating it as loading", () => {
    render(<Amount amountKobo={0} />);
    expect(screen.getByText("₦0")).toBeInTheDocument();
  });
});
