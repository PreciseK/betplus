import { fireEvent, render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { HomepageConcept } from "./HomepageConcept";

vi.mock("next/dynamic", () => ({ default: () => () => null }));

describe("Doors homepage feature", () => {
  it("links to the game and stops the demonstration on the first treasure", () => {
    render(<HomepageConcept preview={false} />);
    fireEvent.click(screen.getByRole("button", { name: "Pause automatic game switching" }));
    fireEvent.click(screen.getByRole("button", { name: /Doors treasure hunt/i }));
    expect(screen.getByRole("link", { name: "Play Doors" })).toHaveAttribute("href", "/games/doors");
    const board = screen.getByRole("group", { name: "Doors demonstration board" });
    expect(within(board).getAllByRole("button")).toHaveLength(20);
    fireEvent.click(screen.getByRole("button", { name: "Open preview door 7" }));
    expect(screen.getByRole("status")).toHaveTextContent("3× discovery");
    expect(within(board).getAllByRole("button").every(button => button.hasAttribute("disabled"))).toBe(true);
    fireEvent.click(screen.getByRole("button", { name: /Reset vault preview/ }));
    for (let door = 1; door <= 5; door++) {
      fireEvent.click(screen.getByRole("button", { name: `Open preview door ${door}` }));
    }
    expect(screen.getByRole("status")).toHaveTextContent("Five doors explored");
    expect(within(board).getAllByRole("button").every(button => button.hasAttribute("disabled"))).toBe(true);
  });
});
