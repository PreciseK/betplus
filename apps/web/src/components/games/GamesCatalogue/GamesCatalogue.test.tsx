import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { GamesCatalogue } from "./GamesCatalogue";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/games",
}));

describe("GamesCatalogue Component", () => {
  it("renders all 3 games in 3x3 matrix mode by default", () => {
    render(<GamesCatalogue showToolbar initialFormat="matrix" />);

    expect(screen.getByRole("region", { name: "3x3 Games Matrix" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Play Caged/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Play BlackRed/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Play Heritage/i })).toBeInTheDocument();
    expect(screen.getByText("3 games available")).toBeInTheDocument();
  });

  it("switches to list view when list toggle button is clicked", () => {
    render(<GamesCatalogue showToolbar initialFormat="matrix" />);

    const listBtn = screen.getByRole("button", { name: /list/i });
    fireEvent.click(listBtn);

    expect(screen.getByRole("region", { name: "Games List" })).toBeInTheDocument();
    expect(screen.getByText("Watch birds escape the golden cage. Cash out your stake with dual-bet slots before the cage drops!")).toBeInTheDocument();
  });

  it("switches to detailed cards view when cards toggle button is clicked", () => {
    render(<GamesCatalogue showToolbar initialFormat="matrix" />);

    const cardsBtn = screen.getByRole("button", { name: /cards/i });
    fireEvent.click(cardsBtn);

    expect(screen.getByRole("region", { name: "Games Grid" })).toBeInTheDocument();
  });
});
