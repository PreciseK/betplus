import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Button } from "./Button";

describe("Button", () => {
  it("announces and prevents activation while loading", () => {
    render(<Button status="loading" statusLabel="Creating account…">Create account</Button>);

    const button = screen.getByRole("button", { name: "Creating account…" });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute("aria-busy", "true");
  });

  it("removes a disabled link from navigation and activation", () => {
    render(<Button href="/register" disabled>Create account</Button>);

    const link = screen.getByText("Create account").closest("a");
    expect(link).not.toHaveAttribute("href");
    expect(link).toHaveAttribute("aria-disabled", "true");
    expect(link).toHaveAttribute("tabindex", "-1");
  });

  it("pairs success with an explicit text label", () => {
    render(<Button status="success" statusLabel="Account created">Create account</Button>);
    expect(screen.getByRole("button", { name: "Account created" })).toHaveAttribute("data-status", "success");
  });
});
