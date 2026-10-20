import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
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
import { RegaliaArtwork } from "./RegaliaArtwork";

beforeEach(() => {
  Object.defineProperty(window, "matchMedia", {
    configurable: true,
    value: vi.fn().mockReturnValue({ matches: true }),
  });
});

async function configureGame() {
  await screen.findByRole("heading", { name: "Heritage" });
  fireEvent.change(screen.getByRole("combobox", { name: "Royal tradition" }), {
    target: { value: "yoruba" },
  });
  fireEvent.click(screen.getByRole("button", { name: "King" }));
  for (let position = 1; position <= 5; position += 1) {
    fireEvent.click(
      screen.getByRole("button", {
        name: `Position ${position}: not selected`,
      }),
    );
  }
}

async function playConfiguredGame() {
  await configureGame();
  fireEvent.click(screen.getByRole("button", { name: /Review round/ }));
  await screen.findByRole("dialog", { name: "Review your round" });
  fireEvent.click(screen.getByRole("button", { name: /Confirm stake/ }));
}

function gatewayForTier(
  tier: "jackpot" | "high" | "second-chance" | "loss",
): HeritageGateway {
  return {
    ...mockHeritageGateway,
    placeTicket: vi
      .fn()
      .mockImplementation(async (input: HeritagePlayInput) =>
        createHeritageSettlement(input, tier),
      ),
  };
}

function gatewayForSecondChance(
  options: HeritageSettlementOptions,
): HeritageGateway {
  return {
    ...mockHeritageGateway,
    placeTicket: vi
      .fn()
      .mockImplementation(async (input: HeritagePlayInput) =>
        createHeritageSettlement(input, "second-chance", options),
      ),
  };
}

describe("HeritageGameFlow", () => {
  it("uses supplied catalogue artwork in place of a concept illustration", () => {
    render(
      <RegaliaArtwork
        item={{
          number: 7,
          slot: "head",
          canonicalName: "Reviewed crown",
          imageUrl: "/games/heritage/reviewed-crown.png",
        }}
      />,
    );
    expect(screen.getByRole("img", { name: "Reviewed crown" })).toHaveAttribute(
      "src",
      "/games/heritage/reviewed-crown.png",
    );
    expect(
      screen.queryByRole("img", { name: /concept illustration/ }),
    ).not.toBeInTheDocument();
  });

  it("keeps an immutable one-to-one catalogue mapping from 1 to 90", () => {
    expect(HERITAGE_CATALOGUE).toHaveLength(90);
    expect(HERITAGE_CATALOGUE.map((item) => item.number)).toEqual(
      Array.from({ length: 90 }, (_, index) => index + 1),
    );
    expect(new Set(HERITAGE_CATALOGUE.map((item) => item.number)).size).toBe(
      90,
    );
    expect(Object.isFrozen(HERITAGE_CATALOGUE)).toBe(true);
    expect(HERITAGE_CATALOGUE.every((item) => Object.isFrozen(item))).toBe(
      true,
    );
    expect(
      HERITAGE_CATALOGUE.every(
        (item) =>
          item.advisorSignOffReference === null &&
          item.publicationStatus === "preview-only",
      ),
    ).toBe(true);
  });

  it("shows both balances, exact prize odds, limits and session net position before play", async () => {
    render(<HeritageGameFlow gateway={mockHeritageGateway} />);
    await screen.findByRole(
      "heading",
      { name: "Heritage" },
      { timeout: 10_000 },
    );

    expect(screen.getAllByText("Play Balance").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Winnings Balance").length).toBeGreaterThan(0);
    expect(screen.getAllByText(/1 in 50 · 2.00%/).length).toBeGreaterThan(0);
    expect(screen.getByText("₦25,000 · 25× stake")).toBeInTheDocument();
    expect(screen.getByText("₦100–₦20,000")).toBeInTheDocument();
    expect(screen.getByText("Net position")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Review round/ })).toBeDisabled();
    expect(
      screen.getAllByRole("button", { name: /Position \d: not selected/ }),
    ).toHaveLength(9);
  });

  it("requires exactly five positions and Quick Pick returns five without changing the odds", async () => {
    render(<HeritageGameFlow gateway={mockHeritageGateway} />);
    await screen.findByRole(
      "heading",
      { name: "Heritage" },
      { timeout: 10_000 },
    );

    fireEvent.click(screen.getByRole("button", { name: "Quick Pick five" }));
    await screen.findByText(
      "Five positions selected by Quick Pick. Your odds are unchanged.",
    );
    expect(
      screen.getAllByRole("button", { name: /Position/, pressed: true }),
    ).toHaveLength(5);
    expect(screen.getAllByText(/1 in 50 · 2.00%/).length).toBeGreaterThan(0);

    fireEvent.click(
      screen.getByRole("button", { name: "Position 2: not selected" }),
    );
    expect(
      screen.getByText(
        "Five positions are already selected. Remove one before choosing another.",
      ),
    ).toBeInTheDocument();
  });

  it("uses the compact confirmation and reveals a coherent nine-position result", async () => {
    render(<HeritageGameFlow gateway={gatewayForTier("high")} />);
    await configureGame();
    const playButton = screen.getByRole("button", { name: /Review round/ });
    fireEvent.click(playButton);

    const dialog = await screen.findByRole("dialog", {
      name: "Review your round",
    });
    const yesButton = screen.getByRole("button", { name: /Confirm stake/ });
    expect(yesButton).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Edit round" }),
    ).toBeInTheDocument();
    await waitFor(() => expect(yesButton).toHaveFocus());
    fireEvent.keyDown(dialog, { key: "Escape" });
    await waitFor(() => expect(playButton).toHaveFocus());
    expect(
      screen.queryByRole("dialog", { name: "Review your round" }),
    ).not.toBeInTheDocument();

    fireEvent.click(playButton);
    await screen.findByRole("dialog", { name: "Review your round" });
    fireEvent.click(screen.getByRole("button", { name: /Confirm stake/ }));

    await screen.findByRole("heading", { name: "Half stake returned" });
    expect(
      screen.getByRole("list", {
        name: "Complete nine-position Heritage result",
      }),
    ).toBeInTheDocument();
    const resultTiles = screen.getAllByRole("button", {
      name: /Position \d, (picked|not picked), (winning|not winning)/,
    });
    expect(resultTiles).toHaveLength(9);
    expect(resultTiles[0]).not.toBeDisabled();
    expect(resultTiles[0]).toHaveAttribute("aria-disabled", "false");
    expect(resultTiles[0]).toHaveAttribute("aria-haspopup", "dialog");
    fireEvent.click(screen.getByText("Board details", { selector: "summary" }));
    expect(
      screen.getByRole("region", { name: "Board proof" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("list", { name: "Nine revealed catalogue items" })
        .children,
    ).toHaveLength(9);
    expect(screen.getByText("Picked · winning")).toBeInTheDocument();
    expect(screen.getByText("Not picked · winning")).toBeInTheDocument();
    expect(screen.getAllByText("4 of 5 matched").length).toBeGreaterThan(0);

    const gross = screen.getByText("Gross prize");
    const tax = screen.getByText(/Tax \(5% net winnings\)/);
    const net = screen.getByText("Net credited");
    expect(
      gross.compareDocumentPosition(tax) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      tax.compareDocumentPosition(net) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it("opens revealed regalia for inspection without purchasing another round and restores focus", async () => {
    const gateway = gatewayForTier("high");
    render(<HeritageGameFlow gateway={gateway} />);
    await playConfiguredGame();
    await screen.findByRole("heading", { name: "Half stake returned" });
    const tile = screen.getAllByRole("button", {
      name: /Position \d, (picked|not picked), (winning|not winning)/,
    })[0];
    tile.focus();
    fireEvent.click(tile);
    const dialog = await screen.findByRole("dialog");
    // The board now shows real tribe regalia art (5 authentic pieces for the
    // chosen tradition/leader plus 4 random decoys), so every tile has an
    // image rather than the abstract-placeholder concept illustration.
    expect(within(dialog).getByRole("img")).toBeInTheDocument();
    expect(
      within(dialog).getByText("Catalogue artwork"),
    ).toBeInTheDocument();
    const closeButton = within(dialog).getByRole("button", {
      name: "Close regalia review",
    });
    await waitFor(() => expect(closeButton).toHaveFocus());
    expect(document.body.style.overflow).toBe("hidden");
    fireEvent.keyDown(dialog, { key: "Tab", shiftKey: true });
    expect(
      within(dialog).getByRole("button", { name: "Back to the arena" }),
    ).toHaveFocus();
    fireEvent.keyDown(dialog, { key: "Escape" });
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(tile).toHaveFocus();
    expect(document.body.style.overflow).not.toBe("hidden");
    const equipment = screen.getAllByRole("button", {
      name: /Inspect matched/,
    });
    expect(equipment.length).toBeGreaterThan(0);
    fireEvent.click(equipment[0]);
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Back to the arena" }));
    expect(equipment[0]).toHaveFocus();
    expect(gateway.placeTicket).toHaveBeenCalledTimes(1);
  });

  it("shows a verifiable second-chance draw entry without blocking settlement", async () => {
    render(<HeritageGameFlow gateway={gatewayForTier("second-chance")} />);
    await playConfiguredGame();

    await screen.findByRole("heading", { name: "Second-chance entry earned" });
    expect(
      screen.getByRole("heading", { name: "Entry confirmed" }),
    ).toBeInTheDocument();
    expect(screen.getByText("7 · 14 · 23 · 31 · 42")).toBeInTheDocument();
    expect(screen.getAllByText("₦100").length).toBeGreaterThan(0);
    expect(screen.getByText(/NG590-/)).toBeInTheDocument();
    expect(screen.getByText(/SMS receipt is due by/)).toBeInTheDocument();
    expect(screen.getByText(/Result SMS: scheduled/)).toBeInTheDocument();
  });

  it("shows a partner delay and cut-off rollover without blocking the settled game", async () => {
    render(
      <HeritageGameFlow
        gateway={gatewayForSecondChance({
          secondChanceStatus: "delayed",
          afterCutOff: true,
          drawsMissed: 1,
        })}
      />,
    );
    await playConfiguredGame();

    await screen.findByRole("heading", { name: "Partner delay" });
    expect(
      screen.getByText(/has not acknowledged the entry/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/current draw cut-off had passed/),
    ).toBeInTheDocument();
    expect(screen.getByText("Not lodged yet")).toBeInTheDocument();
    expect(screen.getByText("1", { selector: "dd" })).toBeInTheDocument();
    expect(screen.getByText(/Result SMS: pending/)).toBeInTheDocument();
  });

  it("states compensation factually after three missed eligible draws", async () => {
    render(
      <HeritageGameFlow
        gateway={gatewayForSecondChance({
          secondChanceStatus: "compensated",
          drawsMissed: 3,
        })}
      />,
    );
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
    expect(
      exit.compareDocumentPosition(another) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      screen.queryByText(/almost|so close|one more|go bigger/i),
    ).not.toBeInTheDocument();
    expect(screen.queryByText(/try again/i)).not.toBeInTheDocument();
  });

  it("fails closed and preserves the configured game when play is blocked", async () => {
    const gateway: HeritageGateway = {
      ...mockHeritageGateway,
      placeTicket: vi
        .fn()
        .mockRejectedValue(new HeritageGatewayError("PLAY_BLOCKED")),
    };
    render(<HeritageGameFlow gateway={gateway} />);
    await playConfiguredGame();

    await screen.findByRole("alert");
    expect(
      screen.getByText(/Player protection currently blocks new games/),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("combobox", { name: "Royal tradition" }),
    ).toHaveValue("yoruba");
    expect(
      screen.getAllByRole("button", { name: /Position/, pressed: true }),
    ).toHaveLength(5);
    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: /Review round/ }),
      ).toBeEnabled(),
    );
  });
  it("reviews the exact choices before charging and locks them during submission", async () => {
    let resolveTicket!: (
      result: ReturnType<typeof createHeritageSettlement>,
    ) => void;
    const placeTicket = vi.fn().mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveTicket = resolve;
        }),
    );
    render(
      <HeritageGameFlow gateway={{ ...mockHeritageGateway, placeTicket }} />,
    );
    await configureGame();
    fireEvent.click(screen.getByRole("button", { name: /Review round/ }));
    const dialog = await screen.findByRole("dialog", {
      name: "Review your round",
    });
    expect(dialog).toHaveTextContent("1 · 2 · 3 · 4 · 5");
    expect(dialog).toHaveTextContent("Betplus wallet");
    expect(placeTicket).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: /Confirm stake/ }));
    expect(placeTicket).toHaveBeenCalledTimes(1);
    expect(placeTicket).toHaveBeenCalledWith({
      selectedPositions: [1, 2, 3, 4, 5],
      traditionId: "yoruba",
      leader: "king",
      stakeKobo: 100_000,
    });
    expect(
      screen.getByRole("combobox", { name: "Royal tradition" }),
    ).toBeDisabled();
    fireEvent.click(
      screen.getByRole("button", { name: "Position 1: selected" }),
    );
    expect(
      screen.getByRole("button", { name: "Position 1: selected" }),
    ).toHaveAttribute("aria-pressed", "true");
    resolveTicket(
      createHeritageSettlement(placeTicket.mock.calls[0][0], "high"),
    );
    await screen.findByRole("heading", { name: "Half stake returned" });
    expect(
      screen.queryByRole("dialog", { name: /Arena/ }),
    ).not.toBeInTheDocument();
  });

  it("retains a settled result when balance refresh fails and lets the player retry", async () => {
    const initial = await mockHeritageGateway.load();
    const load = vi
      .fn()
      .mockResolvedValueOnce(initial)
      .mockRejectedValueOnce(new Error("offline"))
      .mockResolvedValue({
        ...initial,
        playBalanceKobo: 1_150_000,
        winningsBalanceKobo: 925_000,
      });
    render(<HeritageGameFlow gateway={{ ...gatewayForTier("high"), load }} />);
    await playConfiguredGame();
    await screen.findByRole("heading", { name: "Half stake returned" });
    expect(screen.getByLabelText("Account balances")).toHaveTextContent(
      "Unavailable",
    );
    fireEvent.click(screen.getByRole("button", { name: "Refresh balances" }));
    await waitFor(() =>
      expect(screen.getByLabelText("Account balances")).toHaveTextContent(
        "₦11,500",
      ),
    );
    expect(screen.getByLabelText("Account balances")).toHaveTextContent(
      "₦9,250",
    );
    expect(
      screen.getByRole("heading", { name: "Half stake returned" }),
    ).toBeInTheDocument();
  });

  it("renders a pending draw entry before the partner has assigned a date or numbers", async () => {
    const gateway: HeritageGateway = {
      ...mockHeritageGateway,
      placeTicket: async (input) => {
        const result = createHeritageSettlement(input, "second-chance", {
          secondChanceStatus: "pending",
        });
        return {
          ...result,
          secondChance: { ...result.secondChance!, numbers: [], drawAt: "" },
        };
      },
    };
    render(<HeritageGameFlow gateway={gateway} />);
    await playConfiguredGame();
    await screen.findByRole("heading", { name: "Entry queued" });
    expect(screen.getByText("Assigned when lodged")).toBeInTheDocument();
    expect(screen.getByText(/Awaiting draw confirmation/)).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Second-chance entry earned" }),
    ).toBeInTheDocument();
  });

  it("keeps session totals across consecutive rounds and resets only the selection", async () => {
    render(<HeritageGameFlow gateway={gatewayForTier("loss")} />);
    await playConfiguredGame();
    await screen.findByRole("heading", { name: "Round settled — no prize" });
    fireEvent.click(
      screen.getByRole("button", { name: "Start another round" }),
    );
    expect(
      screen.getAllByRole("button", { name: /Position \d: not selected/ }),
    ).toHaveLength(9);
    await playConfiguredGame();
    await screen.findByRole("heading", { name: "Round settled — no prize" });
    fireEvent.click(
      screen.getByText("Your session & limits", { selector: "summary" }),
    );
    const session = screen.getByLabelText("Current play session");
    expect(session).toHaveTextContent("Rounds9");
    expect(session).toHaveTextContent("Total staked₦9,500");
  });
});
