import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ContentConsole } from "./ContentConsole";

const heritageCatalogue = vi.fn();
const publishHeritageCatalogueItem = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      heritageCatalogue: (...args: unknown[]) => heritageCatalogue(...args),
      publishHeritageCatalogueItem: (...args: unknown[]) => publishHeritageCatalogueItem(...args),
    },
  };
});

const ITEM = {
  number: 1, canonical_name: "Ade", local_name: "Ade", origin: "Yoruba", context: "Crown",
  slot: "head", layer_priority: 1, depiction_constraints: "none",
  advisor_sign_off_reference: null, published_at: null, publication_status: "preview-only" as const,
};

beforeEach(() => {
  vi.clearAllMocks();
  heritageCatalogue.mockResolvedValue({ items: [ITEM] });
});

describe("ContentConsole", () => {
  it("shows real catalogue items and refuses publish without a real sign-off, via the real gate", async () => {
    publishHeritageCatalogueItem.mockResolvedValueOnce({ ...ITEM, gate_errors: ["Item 1 has no recorded cultural-advisor sign-off reference (REQ-HG-064)."] });
    render(<ContentConsole />);

    expect(await screen.findByText("Ade")).toBeInTheDocument();
    expect(screen.getByText("Preview only")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Publish" }));

    expect(await screen.findByText(/no recorded cultural-advisor sign-off/)).toBeInTheDocument();
    expect(publishHeritageCatalogueItem).toHaveBeenCalledWith(1);
  });
});
