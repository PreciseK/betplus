import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { GameRegistryConsole } from "./GameRegistryConsole";

const games = vi.fn();
const updateGame = vi.fn();
const createPrizeTable = vi.fn();
const proposeChange = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      games: (...args: unknown[]) => games(...args),
      updateGame: (...args: unknown[]) => updateGame(...args),
      createPrizeTable: (...args: unknown[]) => createPrizeTable(...args),
      proposeChange: (...args: unknown[]) => proposeChange(...args),
    },
  };
});

beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", { configurable: true, value() { this.setAttribute("open", ""); } });
  Object.defineProperty(HTMLDialogElement.prototype, "close", { configurable: true, value() { this.removeAttribute("open"); } });
});

beforeEach(() => {
  vi.clearAllMocks();
  games.mockResolvedValue({
    games: [
      { game_code: "BLACKRED", engine_version: "blackred-1.0.0", status: "active", min_stake_kobo: 10_000, max_stake_kobo: 500_000, enabled_channels: ["web"], enabled_states: ["LAG"] },
      { game_code: "HERITAGE", engine_version: "heritage-1.0.0", status: "active", min_stake_kobo: 10_000, max_stake_kobo: 500_000, enabled_channels: ["web"], enabled_states: ["LAG"] },
    ],
  });
});

describe("GameRegistryConsole", () => {
  it("shows the real runtime registry for both games", async () => {
    render(<GameRegistryConsole />);
    const table = await screen.findByRole("table", { name: "Configured Betplus game registry" });
    expect(within(table).getByText("BLACKRED")).toBeInTheDocument();
    expect(within(table).getByText("HERITAGE")).toBeInTheDocument();
  });

  it("applies a status change immediately via the real endpoint, with no maker-checker framing", async () => {
    updateGame.mockResolvedValueOnce({ game_code: "BLACKRED", status: "suspended" });
    render(<GameRegistryConsole />);
    await screen.findByText("BLACKRED");

    fireEvent.click(screen.getAllByRole("button", { name: "Inspect" })[0]);
    fireEvent.click(screen.getByRole("button", { name: "Configure status" }));
    const dialog = screen.getByRole("dialog", { name: /Update BLACKRED status/ });
    fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: "Suspending for maintenance." } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Apply status change" }));

    expect(await screen.findByRole("status")).toHaveTextContent("applies immediately");
    expect(updateGame).toHaveBeenCalledWith("BLACKRED", { status: "suspended" });
  });

  it("shows the real publication-gate result on a drafted prize table, including a real block", async () => {
    createPrizeTable.mockResolvedValueOnce({
      id: 9, game_code: "BLACKRED", state_code: null, version: "2026.2", status: "draft",
      effective_at: "2026-09-01T00:00:00Z", actuarial_cert_ref: null, published_at: null,
      tiers: [], gate_errors: ["No actuarial certification reference recorded (REQ-GEC-025)."],
    });
    render(<GameRegistryConsole />);
    await screen.findByText("BLACKRED");

    fireEvent.change(screen.getByLabelText(/Version/), { target: { value: "2026.2" } });
    fireEvent.change(screen.getByLabelText(/Effective at/), { target: { value: "2026-09-01T00:00" } });
    fireEvent.click(screen.getByRole("button", { name: /Create draft/ }));

    expect(await screen.findByText(/No actuarial certification reference recorded/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Propose publication" })).toBeDisabled();
  });

  it("proposes a passing draft for real maker-checker approval", async () => {
    createPrizeTable.mockResolvedValueOnce({
      id: 10, game_code: "BLACKRED", state_code: null, version: "2026.3", status: "draft",
      effective_at: "2026-09-01T00:00:00Z", actuarial_cert_ref: "ACT-1", published_at: null,
      tiers: [], gate_errors: [],
    });
    proposeChange.mockResolvedValueOnce({ id: 55, change_type: "prize_table_publish", status: "AWAITING_APPROVAL", payload: { prize_table_id: 10 }, before_snapshot: null, maker_id: 1, maker_justification: "", submitted_at: null, checker_id: null, checker_decision_at: null, rejection_reason: null, applied_at: null });
    render(<GameRegistryConsole />);
    await screen.findByText("BLACKRED");

    fireEvent.change(screen.getByLabelText(/Version/), { target: { value: "2026.3" } });
    fireEvent.change(screen.getByLabelText(/Effective at/), { target: { value: "2026-09-01T00:00" } });
    fireEvent.click(screen.getByRole("button", { name: /Create draft/ }));
    await screen.findByText(/Passes the publication gate/);

    fireEvent.click(screen.getByRole("button", { name: "Propose publication" }));

    expect(await screen.findByRole("status")).toHaveTextContent("proposed for approval");
    expect(proposeChange).toHaveBeenCalledWith(expect.objectContaining({ change_type: "prize_table_publish", payload: { prize_table_id: 10 } }));
  });
});
