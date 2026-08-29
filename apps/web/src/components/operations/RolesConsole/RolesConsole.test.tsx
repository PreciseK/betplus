import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { RolesConsole } from "./RolesConsole";

const institutionUsers = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: { ...actual.backOfficeGateway, hasSession: () => true, institutionUsers: (...args: unknown[]) => institutionUsers(...args) },
  };
});

beforeEach(() => {
  vi.clearAllMocks();
  institutionUsers.mockResolvedValue({
    users: [{ id: 1, email: "a@betplus.test", display_name: "Ada Okafor", role: "support_agent", status: "active", mfa_confirmed_at: null, last_login_at: null, created_at: "2026-08-20T00:00:00Z" }],
  });
});

describe("RolesConsole", () => {
  it("groups real users by their real role and stays read-only", async () => {
    render(<RolesConsole />);

    const row = (await screen.findByText("support agent")).closest("tr");
    expect(row).not.toBeNull();
    expect(row!.textContent).toContain("Ada Okafor");
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
    expect(screen.getByText(/no capability\/permissions table/)).toBeInTheDocument();
  });
});
