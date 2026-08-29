import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { OperationsNav } from "./OperationsNav";

const { usePathname } = vi.hoisted(() => ({ usePathname: vi.fn() }));

vi.mock("next/navigation", () => ({ usePathname }));

describe("OperationsNav", () => {
  beforeEach(() => usePathname.mockReturnValue("/back-office/players"));

  it("removes destinations outside a support agent's permission scope", () => {
    render(<OperationsNav role="support-agent" variant="desktop" />);

    expect(screen.getByRole("link", { name: "Current case" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Ledger" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Support tickets" })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Reconciliation" })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Team access" })).not.toBeInTheDocument();
  });

  it("shows the complete task navigation to a super administrator", () => {
    render(<OperationsNav role="super-admin" variant="desktop" />);
    expect(screen.getAllByRole("link")).toHaveLength(27);
    expect(screen.getByRole("link", { name: "Team access" })).toBeInTheDocument();
    expect(screen.getByText("Customers")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Notifications" })).toBeInTheDocument();
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
    expect(screen.getByText("Product")).toBeInTheDocument();
    expect(screen.getByText("Compliance")).toBeInTheDocument();
    expect(screen.getByText("Insights")).toBeInTheDocument();
  });

  it("marks the current task semantically", () => {
    render(<OperationsNav role="support-lead" variant="desktop" />);
    expect(screen.getByRole("link", { name: "Current case" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: "Dashboard" })).not.toHaveAttribute("aria-current");
  });
});
