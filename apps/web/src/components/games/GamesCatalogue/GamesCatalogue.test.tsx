import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { GamesCatalogue } from "./GamesCatalogue";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/games",
}));

describe("GamesCatalogue Component", () => {
  it("renders the current catalogue as game tiles", () => {
    render(<GamesCatalogue showToolbar initialFormat="matrix" />);

    expect(screen.getByRole("region", { name: "Game tiles" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Play Caged/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Play BlackRed/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Play Heritage/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Play Doors/i })).toHaveAttribute("href", "/games/doors");
    expect(screen.getByText("4 games")).toBeInTheDocument();
  });

  it("switches to list view when list toggle button is clicked", () => {
    render(<GamesCatalogue showToolbar initialFormat="matrix" />);

    const listBtn = screen.getByRole("button", { name: /list/i });
    fireEvent.click(listBtn);

    expect(screen.getByRole("region", { name: "Games List" })).toBeInTheDocument();
    expect(screen.getByText("Cash out before the cage drops. Place up to two bets per round.")).toBeInTheDocument();
  });

  it("switches to detailed cards view when cards toggle button is clicked", () => {
    render(<GamesCatalogue showToolbar initialFormat="matrix" />);

    const cardsBtn = screen.getByRole("button", { name: /cards/i });
    fireEvent.click(cardsBtn);

    expect(screen.getByRole("region", { name: "Games Grid" })).toBeInTheDocument();
  });
});
