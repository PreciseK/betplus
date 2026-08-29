import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  HeritageGatewayError,
  HERITAGE_CATALOGUE,
  createHeritageSettlement,
  mockHeritageGateway,
  type HeritageGateway,
  type HeritagePlayInput,
  type HeritageSettlementOptions,
} from "@/mocks/heritage";
import { HeritageGameFlow } from "./HeritageGameFlow";

beforeEach(() => {
  Object.defineProperty(window, "matchMedia", {
    configurable: true,
    value: vi.fn().mockReturnValue({ matches: true }),
  });
});

async function configureGame() {
  await screen.findByRole("heading", { name: "Select five tiles to reveal and dress your royal" });
  fireEvent.change(screen.getByRole("combobox", { name: "Royal tradition" }), { target: { value: "yoruba" } });
  fireEvent.click(screen.getByRole("button", { name: "King" }));
  for (let position = 1; position <= 5; position += 1) {
    fireEvent.click(screen.getByRole("button", { name: `Position ${position}: not selected` }));
  }
}

async function playConfiguredGame() {
  await configureGame();
  fireEvent.click(screen.getByRole("button", { name: /Reveal and dress/ }));
  await screen.findByRole("dialog", { name: "Confirm game" });
  fireEvent.click(screen.getByRole("button", { name: "Yes" }));
}

function gatewayForTier(tier: "jackpot" | "high" | "second-chance" | "loss"): HeritageGateway {
  return {
    ...mockHeritageGateway,
    placeTicket: vi.fn().mockImplementation(async (input: HeritagePlayInput) => createHeritageSettlement(input, tier)),
  };
}

function gatewayForSecondChance(options: HeritageSettlementOptions): HeritageGateway {
  return {
    ...mockHeritageGateway,
    placeTicket: vi.fn().mockImplementation(async (input: HeritagePlayInput) => createHeritageSettlement(input, "second-chance", options)),
  };
}

describe("HeritageGameFlow", () => {
  it("keeps an immutable one-to-one catalogue mapping from 1 to 90", () => {
    expect(HERITAGE_CATALOGUE).toHaveLength(90);
    expect(HERITAGE_CATALOGUE.map((item) => item.number)).toEqual(Array.from({ length: 90 }, (_, index) => index + 1));
    expect(new Set(HERITAGE_CATALOGUE.map((item) => item.number)).size).toBe(90);
    expect(Object.isFrozen(HERITAGE_CATALOGUE)).toBe(true);
    expect(HERITAGE_CATALOGUE.every((item) => Object.isFrozen(item))).toBe(true);
    expect(HERITAGE_CATALOGUE.every((item) => item.advisorSignOffReference === null && item.publicationStatus === "preview-only")).toBe(true);
  });

  it("shows both balances, exact prize odds, limits and session net position before play", async () => {
    render(<HeritageGameFlow gateway={mockHeritageGateway} />);
    await screen.findByRole("heading", { name: "Select five tiles to reveal and dress your royal" }, { timeout: 10_000 });

    expect(screen.getAllByText("Play Balance").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Winnings Balance").length).toBeGreaterThan(0);
    expect(screen.getAllByText(/1 in 50 · 2.00%/).length).toBeGreaterThan(0);
    expect(screen.getByText("₦25,000 · 25× stake")).toBeInTheDocument();
    expect(screen.getByText("₦100–₦20,000")).toBeInTheDocument();
    expect(screen.getByText("Net position")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Reveal and dress/ })).toBeDisabled();
    expect(screen.getAllByRole("button", { name: /Position \d: not selected/ })).toHaveLength(9);
  });

  it("requires exactly five positions and Quick Pick returns five without changing the odds", async () => {
    render(<HeritageGameFlow gateway={mockHeritageGateway} />);
    await screen.findByRole("heading", { name: "Select five tiles to reveal and dress your royal" }, { timeout: 10_000 });

    fireEvent.click(screen.getByRole("button", { name: "Quick Pick five" }));
    await screen.findByText("Five positions selected by Quick Pick. Your odds are unchanged.");
    expect(screen.getAllByRole("button", { name: /Position/, pressed: true })).toHaveLength(5);
    expect(screen.getAllByText(/1 in 50 · 2.00%/).length).toBeGreaterThan(0);

    fireEvent.click(screen.getByRole("button", { name: "Position 2: not selected" }));
    expect(screen.getByText("Five positions are already selected. Remove one before choosing another.")).toBeInTheDocument();
  });

  it("uses the compact confirmation and reveals a coherent nine-position result", async () => {
    render(<HeritageGameFlow gateway={gatewayForTier("high")} />);
    await configureGame();
    const playButton = screen.getByRole("button", { name: /Reveal and dress/ });
    fireEvent.click(playButton);

    const dialog = await screen.findByRole("dialog", { name: "Confirm game" });
    const yesButton = screen.getByRole("button", { name: "Yes" });
    expect(yesButton).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "No" })).toBeInTheDocument();
    await waitFor(() => expect(yesButton).toHaveFocus());
    fireEvent.keyDown(dialog, { key: "Escape" });
    await waitFor(() => expect(playButton).toHaveFocus());
    expect(screen.queryByRole("dialog", { name: "Confirm game" })).not.toBeInTheDocument();

    fireEvent.click(playButton);
    await screen.findByRole("dialog", { name: "Confirm game" });
    fireEvent.click(screen.getByRole("button", { name: "Yes" }));

    await screen.findByRole("heading", { name: "You tried — half stake returned" });
    expect(screen.getByRole("list", { name: "Complete nine-position Heritage result" })).toBeInTheDocument();
    const resultTiles = screen.getAllByRole("button", { name: /Position \d, (picked|not picked), (winning|not winning)/ });
    expect(resultTiles).toHaveLength(9);
    expect(resultTiles[0]).not.toBeDisabled();
    expect(resultTiles[0]).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByRole("region", { name: "Board proof" })).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "Nine revealed catalogue items" }).children).toHaveLength(9);
    expect(screen.getByText("Picked · winning")).toBeInTheDocument();
    expect(screen.getByText("Not picked · winning")).toBeInTheDocument();
    expect(screen.getAllByText("4 of 5 matched").length).toBeGreaterThan(0);

    const gross = screen.getByText("Gross prize");
    const tax = screen.getByText(/Tax \(5% net winnings\)/);
    const net = screen.getByText("Net credited");
    expect(gross.compareDocumentPosition(tax) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(tax.compareDocumentPosition(net) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("shows a verifiable second-chance draw entry without blocking settlement", async () => {
    render(<HeritageGameFlow gateway={gatewayForTier("second-chance")} />);
    await playConfiguredGame();

    await screen.findByRole("heading", { name: "Second-chance entry earned" });
    expect(screen.getByRole("heading", { name: "Entry confirmed" })).toBeInTheDocument();
    expect(screen.getByText("7 · 14 · 23 · 31 · 42")).toBeInTheDocument();
    expect(screen.getAllByText("₦100").length).toBeGreaterThan(0);
    expect(screen.getByText(/NG590-/)).toBeInTheDocument();
    expect(screen.getByText(/SMS receipt is due by/)).toBeInTheDocument();
    expect(screen.getByText(/Result SMS: scheduled/)).toBeInTheDocument();
  });

  it("shows a partner delay and cut-off rollover without blocking the settled game", async () => {
    render(<HeritageGameFlow gateway={gatewayForSecondChance({ secondChanceStatus: "delayed", afterCutOff: true, drawsMissed: 1 })} />);
    await playConfiguredGame();

    await screen.findByRole("heading", { name: "Partner delay" });
    expect(screen.getByText(/has not acknowledged the entry/)).toBeInTheDocument();
    expect(screen.getByText(/current draw cut-off had passed/)).toBeInTheDocument();
    expect(screen.getByText("Not lodged yet")).toBeInTheDocument();
    expect(screen.getByText("1", { selector: "dd" })).toBeInTheDocument();
    expect(screen.getByText(/Result SMS: pending/)).toBeInTheDocument();
  });

  it("states compensation factually after three missed eligible draws", async () => {
    render(<HeritageGameFlow gateway={gatewayForSecondChance({ secondChanceStatus: "compensated", drawsMissed: 3 })} />);
    await playConfiguredGame();

    await screen.findByRole("heading", { name: "Compensation credited" });
    expect(screen.getByText(/Three eligible draws passed/)).toBeInTheDocument();
    expect(screen.getByText("3", { selector: "dd" })).toBeInTheDocument();
    expect(screen.getByText(/Result SMS: sent/)).toBeInTheDocument();
  });

  it("states a loss neutrally and makes exit more prominent than another round", async () => {
    render(<HeritageGameFlow gateway={gatewayForTier("loss")} />);
    await playConfiguredGame();

    await screen.findByRole("heading", { name: "Round settled — no prize" });
    const exit = screen.getByRole("link", { name: "Back to games" });
    const another = screen.getByRole("button", { name: "Start another round" });
    expect(exit.compareDocumentPosition(another) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.queryByText(/almost|so close|one more|go bigger/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/try again/i)).not.toBeInTheDocument();
  });

  it("fails closed and preserves the configured game when play is blocked", async () => {
    const gateway: HeritageGateway = {
      ...mockHeritageGateway,
      placeTicket: vi.fn().mockRejectedValue(new HeritageGatewayError("PLAY_BLOCKED")),
    };
    render(<HeritageGameFlow gateway={gateway} />);
    await playConfiguredGame();

    await screen.findByRole("alert");
    expect(screen.getByText(/Player protection currently blocks new games/)).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "Royal tradition" })).toHaveValue("yoruba");
    expect(screen.getAllByRole("button", { name: /Position/, pressed: true })).toHaveLength(5);
    await waitFor(() => expect(screen.getByRole("button", { name: /Reveal and dress/ })).toBeEnabled());
  });
});
