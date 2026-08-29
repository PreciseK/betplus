import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { SignInFlow } from "./SignInFlow";
import type { SessionGateway } from "@/mocks/session";

function gateway(): SessionGateway {
  return {
    requestSignInCode: vi.fn().mockResolvedValue({ challengeId: "challenge-1" }),
    verifySignInCode: vi.fn().mockResolvedValue({
      player: { displayName: "Adaeze" },
      sessionExpiresAt: "2026-08-14T12:30:00.000Z",
    }),
  };
}

describe("SignInFlow", () => {
  it("focuses a useful summary when the phone number is invalid", async () => {
    render(<SignInFlow gateway={gateway()} />);
    fireEvent.click(screen.getByRole("button", { name: "Send sign-in code" }));
    const summary = await screen.findByRole("alert");
    expect(summary).toHaveFocus();
    expect(screen.getAllByText(/11-digit Nigerian phone number/i)).toHaveLength(2);
  });

  it("signs in without exposing a token to browser storage", async () => {
    const sessionGateway = gateway();
    const localStorageSpy = vi.spyOn(Storage.prototype, "setItem");
    render(<SignInFlow gateway={sessionGateway} />);

    fireEvent.change(screen.getByLabelText("Nigerian phone number"), { target: { value: "08012345678" } });
    fireEvent.click(screen.getByRole("button", { name: "Send sign-in code" }));
    await screen.findByRole("heading", { name: "Enter your sign-in code" });
    fireEvent.change(screen.getByLabelText("Verification code"), { target: { value: "123456" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));

    await screen.findByRole("heading", { name: "Welcome back, Adaeze." });
    expect(sessionGateway.verifySignInCode).toHaveBeenCalledWith("challenge-1", "123456");
    expect(localStorageSpy).not.toHaveBeenCalled();
    localStorageSpy.mockRestore();
  });

  it("explains a forced session end and lets the player restart", () => {
    render(<SignInFlow gateway={gateway()} initialState="session-ended" />);
    expect(screen.getByRole("heading", { name: "Your session has ended." })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Sign in again" }));
    expect(screen.getByRole("heading", { name: "Sign in to Betplus" })).toBeInTheDocument();
  });
});
