import { afterEach, describe, expect, it, vi } from "vitest";
import { registrationGateway } from "@betplus/api-client";

afterEach(() => vi.unstubAllGlobals());

describe("registration network transport", () => {
  it("sends the selected network to the registration endpoint", async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({ status: "otp_sent" }) });
    vi.stubGlobal("fetch", fetchMock);
    await registrationGateway.requestOtp("+2348031234567", "glo");
    const [url, options] = fetchMock.mock.calls[0];
    expect(url).toMatch(/\/auth\/register$/);
    expect(JSON.parse(options.body)).toEqual({ msisdn: "+2348031234567", mobile_network: "glo" });
  });
});
