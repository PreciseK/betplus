import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { BackOfficeApiError } from "@betplus/api-client";
import { TicketReplayAudit } from "./TicketReplayAudit";

const ticketReplay = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: { ...actual.backOfficeGateway, ticketReplay: (...args: unknown[]) => ticketReplay(...args) },
  };
});

const MATCHING = {
  reference: "BR-982104", seed_hex: "abc123", seed_algorithm: "sha256", engine_version: "blackred-1.0.0",
  prediction: ["B", "R", "B"],
  stored: { result: ["B", "R", "B"], won: true, digest: "digest-1" },
  replayed: { result: ["B", "R", "B"], won: true, digest: "digest-1" },
  matches: true,
};

describe("TicketReplayAudit", () => {
  it.each([{}, null, { ...MATCHING, stored: undefined }, { ...MATCHING, replayed: {} }])("handles an incomplete replay response without crashing: %j", async (response) => {
    ticketReplay.mockResolvedValueOnce(response);
    render(<TicketReplayAudit />);
    fireEvent.change(screen.getByLabelText("Ticket reference"), { target: { value: "BR-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Check ticket" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Could not run that replay");
    expect(screen.queryByText("Verified replay")).not.toBeInTheDocument();
  });

  it("renders the structured Doors result without treating it as a card array", async () => {
    const outcome = { opened: [0, 6], multiplier: 3 };
    ticketReplay.mockResolvedValueOnce({ ...MATCHING, reference: "DOORS-1", engine_version: "doors-1.0.0", prediction: [], stored: { result: outcome, won: true, digest: "digest-doors" }, replayed: { result: outcome, won: true, digest: "digest-doors" } });
    render(<TicketReplayAudit />);
    fireEvent.change(screen.getByLabelText("Ticket reference"), { target: { value: "DOORS-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Check ticket" }));
    expect(await screen.findByRole("heading", { name: "DOORS-1" })).toBeInTheDocument();
    expect(screen.getAllByText("Doors: 1, 7 · 3×")).toHaveLength(2);
  });

  it("does not label an unrecorded outcome as a loss", async () => {
    ticketReplay.mockResolvedValueOnce({ ...MATCHING, stored: { result: null, won: null, digest: null }, matches: false });
    render(<TicketReplayAudit />);
    fireEvent.change(screen.getByLabelText("Ticket reference"), { target: { value: "BR-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Check ticket" }));
    expect(await screen.findByText("Recorded outcome: Not recorded")).toBeInTheDocument();
  });

  it("shows nothing until a reference is searched, then calls the real replay endpoint", async () => {
    ticketReplay.mockResolvedValueOnce(MATCHING);
    render(<TicketReplayAudit />);

    expect(screen.queryByRole("heading", { name: "BR-982104" })).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Ticket reference"), { target: { value: "br-982104" } });
    fireEvent.click(screen.getByRole("button", { name: "Check ticket" }));

    expect(await screen.findByRole("heading", { name: "BR-982104" })).toBeInTheDocument();
    expect(ticketReplay).toHaveBeenCalledWith("BR-982104");
  });

  it("shows the real sealed seed and the real recorded-vs-replayed digest comparison", async () => {
    ticketReplay.mockResolvedValueOnce(MATCHING);
    render(<TicketReplayAudit />);
    fireEvent.change(screen.getByLabelText("Ticket reference"), { target: { value: "BR-982104" } });
    fireEvent.click(screen.getByRole("button", { name: "Check ticket" }));

    await screen.findByRole("heading", { name: "BR-982104" });
    expect(screen.getByText("abc123")).toBeInTheDocument();
    expect(screen.getAllByText("digest-1")).toHaveLength(2);
    expect(screen.getByText("Exact digest match · no divergence")).toBeInTheDocument();
  });

  it("surfaces a real digest mismatch rather than always claiming a verified replay", async () => {
    ticketReplay.mockResolvedValueOnce({
      ...MATCHING,
      replayed: { result: ["R", "R", "B"], won: false, digest: "digest-2" },
      matches: false,
    });
    render(<TicketReplayAudit />);
    fireEvent.change(screen.getByLabelText("Ticket reference"), { target: { value: "BR-982104" } });
    fireEvent.click(screen.getByRole("button", { name: "Check ticket" }));

    expect(await screen.findByRole("heading", { name: "Digest mismatch" })).toBeInTheDocument();
    expect(screen.getByText(/needs investigation/)).toBeInTheDocument();
  });

  it("shows a real not-found state on a 404 instead of a fabricated sample ticket", async () => {
    ticketReplay.mockRejectedValueOnce(new BackOfficeApiError(404, { message: "not found" }));
    render(<TicketReplayAudit />);

    fireEvent.change(screen.getByLabelText("Ticket reference"), { target: { value: "BR-000000" } });
    fireEvent.click(screen.getByRole("button", { name: "Check ticket" }));

    expect(await screen.findByRole("heading", { name: /No replay record for/ })).toBeInTheDocument();
  });

  it("does not offer a compensating-entry proposal — that workflow isn't wired here", async () => {
    ticketReplay.mockResolvedValueOnce(MATCHING);
    render(<TicketReplayAudit />);
    fireEvent.change(screen.getByLabelText("Ticket reference"), { target: { value: "BR-982104" } });
    fireEvent.click(screen.getByRole("button", { name: "Check ticket" }));

    await screen.findByRole("heading", { name: "BR-982104" });
    expect(screen.queryByRole("button", { name: /Propose compensating entry/ })).not.toBeInTheDocument();
  });
});
