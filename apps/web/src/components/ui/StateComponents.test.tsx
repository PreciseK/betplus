import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Button } from "@/components/ui/Button/Button";
import { ContentSkeleton } from "@/components/ui/states/ContentSkeleton/ContentSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState/EmptyState";
import { LoadingState } from "@/components/ui/states/LoadingState/LoadingState";

describe("empty and loading states", () => {
  it("explains an empty section and offers one useful action", () => {
    render(<EmptyState title="No activity yet" description="Deposits, withdrawals and game receipts will appear here." action={<Button href="/games">Explore games</Button>} />);
    expect(screen.getByRole("region", { name: "No activity yet" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Explore games" })).toBeInTheDocument();
  });

  it("names what is loading", () => {
    render(<LoadingState label="Loading activity…" description="Your balance is shown separately." />);
    expect(screen.getByRole("status")).toHaveTextContent("Loading activity…");
  });

  it("labels non-financial skeleton content for assistive technology", () => {
    render(<ContentSkeleton label="Loading game rules…" />);
    expect(screen.getByRole("status", { name: "Loading game rules…" })).toBeInTheDocument();
  });
});
