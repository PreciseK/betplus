import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { OperationsNav } from "./OperationsNav";

const { usePathname } = vi.hoisted(() => ({ usePathname: vi.fn() }));

vi.mock("next/navigation", () => ({ usePathname }));

describe("OperationsNav", () => {
  beforeEach(() => usePathname.mockReturnValue("/back-office/players"));

  it("removes destinations outside a support agent's permission scope", () => {
    render(<OperationsNav role="support-agent" variant="desktop" />);

    expect(screen.getByRole("link", { name: "Players" })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Ledger" })).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Support tickets" })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Reconciliation" })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Team access" })).not.toBeInTheDocument();
  });

  it("shows the complete task navigation to a super administrator", () => {
    render(<OperationsNav role="super-admin" variant="desktop" />);
    expect(screen.getAllByRole("link")).toHaveLength(22);
    expect(screen.getByRole("link", { name: "Team access" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Promotions" })).toBeInTheDocument();
    expect(screen.getByText("Customers")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Notifications" })).not.toBeInTheDocument();
  });

  it("keeps system administration separate from business operations", () => {
    render(<OperationsNav role="system-admin" variant="desktop" />);
    expect(screen.getAllByRole("link")).toHaveLength(4);
    expect(screen.getByRole("link", { name: "Team access" })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Reconciliation" })).not.toBeInTheDocument();
  });

  it("organises tasks into a small set of plain-language groups", () => {
    render(<OperationsNav role="super-admin" variant="desktop" />);
    expect(screen.getByText("Customers")).toBeInTheDocument();
    expect(screen.getByText("Finance")).toBeInTheDocument();
    expect(screen.getByText("Games")).toBeInTheDocument();
    expect(screen.getByText("Compliance")).toBeInTheDocument();
    expect(screen.getAllByText("Reports").length).toBeGreaterThan(0);
  });

  it("marks the current task semantically", () => {
    render(<OperationsNav role="support-lead" variant="desktop" />);
    expect(screen.getByRole("link", { name: "Players" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: "Dashboard" })).not.toHaveAttribute("aria-current");
  });
});
