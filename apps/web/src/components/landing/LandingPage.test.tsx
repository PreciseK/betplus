import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { AccessSection } from "./AccessSection";
import { GamesSection } from "./GamesSection";
import { Hero } from "./Hero";
import { HowItWorks } from "./HowItWorks";

describe("Landing Page Components", () => {
  it("renders Hero with 3 games, Caged, and USSD *7006# details", () => {
    render(<Hero />);

    expect(screen.getByText(/Your Move\./i)).toBeInTheDocument();
    expect(screen.getByText(/Your Naira\./i)).toBeInTheDocument();
    expect(screen.getByText(/USSD \*7006#/i)).toBeInTheDocument();
    expect(screen.getByText(/CAGED CRASH/i)).toBeInTheDocument();
    expect(screen.getByText(/USSD PLAY/i)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Play via USSD \(\*7006#\)/i })).toBeInTheDocument();
  });

  it("renders GamesSection with BlackRed, Heritage, and Caged escape count mechanics", () => {
    render(<GamesSection />);

    expect(screen.getByRole("heading", { level: 3, name: "BlackRed" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 3, name: "Heritage" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 3, name: "Caged" })).toBeInTheDocument();

    expect(screen.getByText(/03 \/ Crash & Escape Count/i)).toBeInTheDocument();
    expect(screen.getByText(/Sparrow/i)).toBeInTheDocument();
    expect(screen.getByText(/1\.35x/i)).toBeInTheDocument();
    expect(screen.getByText(/Phoenix/i)).toBeInTheDocument();
    expect(screen.getByText(/20x Win/i)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Discover Caged/i })).toHaveAttribute("href", "/games/caged");
  });

  it("renders AccessSection with prominent USSD *7006# card and direct OPay funding info", () => {
    render(<AccessSection />);

    expect(screen.getAllByText("*7006#").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(/Instant USSD Shortcode/i)).toBeInTheDocument();
    expect(screen.getByText(/Direct OPay Funding/i)).toBeInTheDocument();
    expect(screen.getAllByText(/Guaranteed SMS/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(/MTN · Airtel · Glo · 9mobile/i)).toBeInTheDocument();
    expect(screen.getByText(/USSD \(\*7006#\)/i)).toBeInTheDocument();
  });

  it("renders HowItWorks with USSD and OPay references", () => {
    render(<HowItWorks />);

    expect(screen.getByText(/dial \*7006# for instant gateway authentication/i)).toBeInTheDocument();
    expect(screen.getByText(/direct withdrawal automatically fund your stake/i)).toBeInTheDocument();
    expect(screen.getByText(/Caged bird escape count/i)).toBeInTheDocument();
  });
});
