import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import Home from "../../app/page";
import { GamesSection } from "./GamesSection";
import { Hero } from "./Hero";
import { SiteHeader } from "./SiteHeader";

beforeEach(() => {
  vi.stubGlobal("matchMedia", vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })));
});
afterEach(() => vi.unstubAllGlobals());

describe("Public homepage", () => {
  it("introduces Betplus and provides account creation and game navigation", () => {
    render(<Hero />);
    expect(screen.getByRole("heading", { level: 1, name: "Your Move. Your Moment. Your Win." })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Create account" })).toHaveAttribute("href", "/register");
    expect(screen.getByRole("link", { name: "See the games" })).toHaveAttribute("href", "#games");
    expect(screen.getByText(/18\+ only/)).toBeInTheDocument();
  });

  it("explains Caged as a cash-out game and links each game to its own route", () => {
    render(<GamesSection />);
    for (const [title, slug] of [["BlackRed", "blackred"], ["Heritage", "heritage"], ["Caged", "caged"]]) {
      expect(screen.getByRole("heading", { level: 3, name: title })).toBeInTheDocument();
      expect(screen.getByRole("link", { name: `Play ${title}` })).toHaveAttribute("href", `/games/${slug}`);
    }
    expect(screen.getByText(/cash out before the cage drops/)).toBeInTheDocument();
  });

  it("keeps homepage section links connected to real sections", () => {
    const { container } = render(<Home />);
    const anchors = container.querySelectorAll<HTMLAnchorElement>('a[href^="#"]');
    for (const anchor of anchors) {
      expect(document.getElementById(anchor.hash.slice(1)), anchor.href).not.toBeNull();
    }
    expect(screen.getByRole("link", { name: "Dial *7006#" })).toHaveAttribute("href", "tel:*7006%23");
    expect(container.querySelectorAll(".t-tilt,.hero-glow,.orbit")).toHaveLength(0);
  });

  it("closes the mobile menu on Escape and returns focus to its toggle", () => {
    render(<SiteHeader />);
    fireEvent.click(screen.getByRole("button", { name: "Open menu" }));
    expect(screen.getByRole("button", { name: "Close menu" })).toHaveAttribute("aria-expanded", "true");
    fireEvent.keyDown(document, { key: "Escape" });
    expect(screen.getByRole("button", { name: "Open menu" })).toHaveAttribute("aria-expanded", "false");
    expect(screen.getByRole("button", { name: "Open menu" })).toHaveFocus();
    fireEvent.click(screen.getByRole("button", { name: "Open menu" }));
    fireEvent.click(screen.getByRole("link", { name: "Games" }));
    expect(screen.getByRole("button", { name: "Open menu" })).toHaveAttribute("aria-expanded", "false");
  });
});
