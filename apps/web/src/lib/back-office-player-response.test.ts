import { afterEach, describe, expect, it, vi } from "vitest";
import { backOfficeGateway, BackOfficeApiError } from "@betplus/api-client";

afterEach(() => { vi.unstubAllGlobals(); sessionStorage.clear(); });

describe("Record requests with a preview session", () => {
  it.each(["player", "ticket"])("preserves a missing %s response instead of returning generic preview data", async (kind) => {
    sessionStorage.setItem("betplus.operator.access-token", "dev-mock-test");
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: "Player not found" }), { status: 404 })));
    await expect(kind === "player" ? backOfficeGateway.player(1) : backOfficeGateway.ticketReplay("BR-1")).rejects.toMatchObject({ status: 404 });
  });

  it.each(["player", "ticket"])("preserves a connection failure instead of returning a fake %s record", async (kind) => {
    sessionStorage.setItem("betplus.operator.access-token", "dev-mock-test");
    const failure = new TypeError("Failed to fetch");
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(failure));
    await expect(kind === "player" ? backOfficeGateway.player(1) : backOfficeGateway.ticketReplay("BR-1")).rejects.toBe(failure);
    expect(failure).not.toBeInstanceOf(BackOfficeApiError);
  });
});
