import { fireEvent, render, screen } from "@testing-library/react";
import { beforeAll, describe, expect, it, vi } from "vitest";
import { Banner } from "@/components/ui/feedback/Banner/Banner";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { FullPageMessage } from "@/components/ui/feedback/FullPageMessage/FullPageMessage";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { Toast } from "@/components/ui/feedback/Toast/Toast";

beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", {
    value: vi.fn(function showModal(this: HTMLDialogElement) {
      this.setAttribute("open", "");
    }),
    configurable: true,
    writable: true,
  });
  Object.defineProperty(HTMLDialogElement.prototype, "close", {
    value: vi.fn(function close(this: HTMLDialogElement) {
      this.removeAttribute("open");
    }),
    configurable: true,
    writable: true,
  });
});

describe("feedback components", () => {
  it("uses explicit text and alert semantics for errors", () => {
    render(<InlineMessage tone="error" title="Funding not confirmed">Don’t submit it again.</InlineMessage>);
    expect(screen.getByRole("alert")).toHaveTextContent("Funding not confirmed");
    expect(screen.getByRole("alert")).toHaveTextContent("Don’t submit it again.");
  });

  it("labels a persistent banner by its heading", () => {
    render(<Banner tone="warning" title="Play is temporarily unavailable">Withdrawals remain available.</Banner>);
    expect(screen.getByRole("region", { name: "Play is temporarily unavailable" })).toBeInTheDocument();
  });

  it("uses native dialog and exposes cancel and confirmation actions", () => {
    const onClose = vi.fn();
    const onConfirm = vi.fn();
    render(<Dialog open title="Confirm withdrawal" confirmLabel="Withdraw ₦5,000" onClose={onClose} onConfirm={onConfirm}>Funds will go to your OPay wallet.</Dialog>);
    fireEvent.click(screen.getByRole("button", { name: "Withdraw ₦5,000" }));
    expect(onConfirm).toHaveBeenCalledOnce();
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(onClose).toHaveBeenCalledOnce();
  });

  it("uses a single page heading for blocking full-page states", () => {
    render(<FullPageMessage tone="warning" title="Verification needed">Complete NIN verification before playing.</FullPageMessage>);
    expect(screen.getByRole("heading", { level: 1, name: "Verification needed" })).toBeInTheDocument();
  });

  it("allows a toast to be dismissed and never hides its status in colour", () => {
    const onClose = vi.fn();
    render(<Toast open tone="success" title="Preference saved" onClose={onClose} durationMs={0} />);
    expect(screen.getByRole("status")).toHaveTextContent("Preference saved");
    fireEvent.click(screen.getByRole("button", { name: "Dismiss notification" }));
    expect(onClose).toHaveBeenCalledOnce();
  });
});
