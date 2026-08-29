import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AuditLog } from "./AuditLog";

const auditLog = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      auditLog: (...args: unknown[]) => auditLog(...args),
    },
  };
});

const EVENT = {
  id: 1, actor_type: "institutionUser", actor_id: 7, action: "game_registry_updated",
  target_table: "gameRegistry", target_id: 3,
  before: { status: "active" }, after: { status: "suspended" },
  reason: null, ip_address: "10.0.0.4", user_agent: "Mozilla/5.0",
  created_at: "2026-08-20T12:00:00Z",
};

beforeEach(() => {
  vi.clearAllMocks();
  auditLog.mockResolvedValue({ events: [EVENT] });
});

describe("AuditLog", () => {
  it("shows real operator events and a real DB-immutability note instead of a hash-chain claim", async () => {
    render(<AuditLog />);

    expect(await screen.findByRole("rowheader", { name: /game_registry_updated/ })).toBeInTheDocument();
    expect(screen.getByText("Immutable at the database level")).toBeInTheDocument();
    expect(screen.getByText(/no hash chain/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Export filtered log" })).not.toBeInTheDocument();
  });

  it("expands a real before/after diff computed from the stored snapshots", async () => {
    render(<AuditLog />);
    await screen.findByRole("rowheader", { name: /game_registry_updated/ });

    fireEvent.click(screen.getByRole("button", { name: /View evidence/ }));

    expect(screen.getByText("status")).toBeInTheDocument();
    expect(screen.getByText('"active"')).toBeInTheDocument();
    expect(screen.getByText('"suspended"')).toBeInTheDocument();
    expect(screen.getByText("10.0.0.4")).toBeInTheDocument();
  });

  it("filters client-side by actor, action or target", async () => {
    render(<AuditLog />);
    await screen.findByRole("rowheader", { name: /game_registry_updated/ });

    fireEvent.change(screen.getByLabelText("Search events"), { target: { value: "no-match-at-all" } });

    expect(await screen.findByText("No matching audit events")).toBeInTheDocument();
  });

  it("re-fetches from the real endpoint with a date range instead of filtering fixtures", async () => {
    render(<AuditLog />);
    await screen.findByRole("rowheader", { name: /game_registry_updated/ });

    auditLog.mockResolvedValueOnce({ events: [] });
    fireEvent.change(screen.getByLabelText("From"), { target: { value: "2026-08-01" } });

    expect(await screen.findByText("No matching audit events")).toBeInTheDocument();
    expect(auditLog).toHaveBeenLastCalledWith(expect.objectContaining({ date_from: "2026-08-01" }));
  });

  it("shows a real load-failed state when the fetch itself fails", async () => {
    auditLog.mockRejectedValueOnce(new Error("network error"));

    render(<AuditLog />);

    expect(await screen.findByText(/Live audit data could not be loaded/)).toBeInTheDocument();
  });
});
