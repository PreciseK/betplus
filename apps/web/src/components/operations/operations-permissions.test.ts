import { describe, expect, it } from "vitest";
import { OPERATIONS_CAPABILITIES, ROLE_CAPABILITIES, roleHasCapability } from "./operations-permissions";

describe("operations permissions", () => {
  it("grants every capability to Super Admin", () => {
    expect(ROLE_CAPABILITIES["super-admin"]).toEqual(OPERATIONS_CAPABILITIES);
  });

  it("limits System Admin to identity, audit and overview capabilities", () => {
    expect(roleHasCapability("system-admin", "users.manage")).toBe(true);
    expect(roleHasCapability("system-admin", "money.read")).toBe(false);
    expect(roleHasCapability("system-admin", "games.manage")).toBe(false);
  });
});
