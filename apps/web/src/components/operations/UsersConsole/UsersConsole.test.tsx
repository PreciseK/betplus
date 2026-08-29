import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { UsersConsole } from "./UsersConsole";

const institutionUsers = vi.fn();
const createInstitutionUser = vi.fn();
const updateInstitutionUser = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      institutionUsers: (...args: unknown[]) => institutionUsers(...args),
      createInstitutionUser: (...args: unknown[]) => createInstitutionUser(...args),
      updateInstitutionUser: (...args: unknown[]) => updateInstitutionUser(...args),
    },
  };
});

const USER = {
  id: 5, email: "ada@betplus.test", display_name: "Ada Okafor", role: "support_agent",
  status: "active", mfa_confirmed_at: null, last_login_at: null, created_at: "2026-08-20T00:00:00Z",
};

beforeEach(() => {
  vi.clearAllMocks();
  institutionUsers.mockResolvedValue({ users: [USER] });
});

describe("UsersConsole", () => {
  it("suspends a real user via the real endpoint, applying immediately", async () => {
    updateInstitutionUser.mockResolvedValueOnce({ ...USER, status: "suspended" });
    render(<UsersConsole />);
    await screen.findByText("Ada Okafor");

    fireEvent.click(screen.getByRole("button", { name: "Suspend" }));

    expect(await screen.findByRole("status")).toHaveTextContent("Ada Okafor is now suspended");
    expect(updateInstitutionUser).toHaveBeenCalledWith(5, { status: "suspended" });
  });

  it("creates a real account and surfaces the one-time password exactly once", async () => {
    createInstitutionUser.mockResolvedValueOnce({ ...USER, id: 9, one_time_password: "abc123", totp_secret: "SECRET" });
    render(<UsersConsole />);
    await screen.findByText("Ada Okafor");

    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "new@betplus.test" } });
    fireEvent.change(screen.getByLabelText("Display name"), { target: { value: "New Operator" } });
    fireEvent.click(screen.getByRole("button", { name: "Create account" }));

    expect(await screen.findByRole("status")).toHaveTextContent("One-time password: abc123");
  });
});
