import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { PlayerNav } from "./PlayerNav";

const { usePathname } = vi.hoisted(() => ({ usePathname: vi.fn() }));

vi.mock("next/navigation", () => ({ usePathname }));

describe("PlayerNav", () => {
  beforeEach(() => usePathname.mockReturnValue("/wallet"));

  it("exposes five stable, labelled destinations", () => {
    render(<PlayerNav variant="desktop" />);
    expect(screen.getAllByRole("link")).toHaveLength(5);
    for (const name of ["Home", "Games", "Wallet", "Activity", "Account"]) {
      expect(screen.getByRole("link", { name })).toBeInTheDocument();
    }
  });

  it("marks the active destination semantically", () => {
    render(<PlayerNav variant="mobile" />);
    expect(screen.getByRole("link", { name: "Wallet" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: "Home" })).not.toHaveAttribute("aria-current");
  });
});
