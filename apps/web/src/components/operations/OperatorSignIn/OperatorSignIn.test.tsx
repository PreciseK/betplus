import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import type { OperatorSessionGateway } from "@/mocks/operator-session";
import { OperatorSignIn } from "./OperatorSignIn";

function gateway(): OperatorSessionGateway {
  return {
    beginMfa: vi.fn().mockResolvedValue({ challengeId: "challenge-ops-1", maskedEmail: "a•••@betplus.ng" }),
    verifyMfa: vi.fn().mockResolvedValue({
      operator: {
        id: "op-1",
        displayName: "Amaka Okafor",
        email: "amaka.okafor@betplus.ng",
        role: "support-lead",
      },
      mfaVerifiedAt: "2026-08-18T13:15:00.000Z",
      expiresAt: "2026-08-18T13:45:00.000Z",
      approvedNetwork: "Lagos operations VPN",
    }),
  };
}

describe("OperatorSignIn", () => {
  it("requires valid operator credentials before presenting MFA", async () => {
    const sessionGateway = gateway();
    render(<OperatorSignIn gateway={sessionGateway} />);

    fireEvent.click(screen.getByRole("button", { name: "Continue to MFA" }));

    const summary = await screen.findByRole("alert");
    expect(summary).toHaveFocus();
    expect(sessionGateway.beginMfa).not.toHaveBeenCalled();
    expect(screen.getByRole("heading", { name: "Operator sign in" })).toBeInTheDocument();
  });

  it("does not authorize a session until MFA succeeds", async () => {
    const sessionGateway = gateway();
    const localStorageSpy = vi.spyOn(Storage.prototype, "setItem");
    render(<OperatorSignIn gateway={sessionGateway} />);

    fireEvent.change(screen.getByLabelText("Work email"), { target: { value: "amaka.okafor@betplus.ng" } });
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "secure-passphrase" } });
    fireEvent.click(screen.getByRole("button", { name: "Continue to MFA" }));

    await screen.findByRole("heading", { name: "Verify with MFA" });
    expect(screen.queryByRole("link", { name: "Open operations overview" })).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Authenticator code"), { target: { value: "123456" } });
    fireEvent.click(screen.getByRole("button", { name: "Verify and sign in" }));

    expect(await screen.findByRole("heading", { name: "Access confirmed" })).toBeInTheDocument();
    expect(sessionGateway.verifyMfa).toHaveBeenCalledWith("challenge-ops-1", "123456");
    expect(screen.getByText(/Support Lead · Lagos operations VPN/)).toBeInTheDocument();
    expect(localStorageSpy).not.toHaveBeenCalled();
    localStorageSpy.mockRestore();
  });
});
