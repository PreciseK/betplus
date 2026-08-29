import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { RegistrationFlow } from "./RegistrationFlow";
import { maskNigerianPhone, normalizeNigerianPhone } from "@/lib/phone";
import { createMockRegistrationGateway } from "@/mocks/registration";
import { parseNigerianDateInput } from "@/lib/date";
import { KycTierGate } from "@/components/auth/KycTierGate/KycTierGate";

describe("Story 1.7 registration flow", () => {
  it("normalizes accepted Nigerian phone formats to E.164", () => {
    expect(normalizeNigerianPhone("0801 234 5678")).toBe("+2348012345678");
    expect(normalizeNigerianPhone("+234 801 234 5678")).toBe("+2348012345678");
    expect(normalizeNigerianPhone("12345")).toBeNull();
    expect(maskNigerianPhone("+2348012345678")).toContain("•••");
  });

  it("parses a plausible DD/MM/YYYY date without three small controls", () => {
    expect(parseNigerianDateInput("13/08/1990")).toBe("1990-08-13");
    expect(parseNigerianDateInput("31/02/1990")).toBeNull();
  });

  it("validates on blur and links the summary to the phone field", () => {
    render(<RegistrationFlow />);
    const phone = screen.getByRole("textbox", { name: "Nigerian phone number" });
    fireEvent.change(phone, { target: { value: "123" } });
    fireEvent.blur(phone);
    expect(screen.getByRole("alert")).toHaveTextContent("Enter an 11-digit Nigerian phone number");
    expect(screen.getByRole("link", { name: /Enter an 11-digit/ })).toHaveAttribute("href", "#phone");
  });

  it("moves from phone to one-input OTP and confirms the phone", async () => {
    render(<RegistrationFlow />);
    fireEvent.change(screen.getByRole("textbox", { name: "Nigerian phone number" }), { target: { value: "08012345678" } });
    fireEvent.click(screen.getByRole("button", { name: "Send verification code" }));
    const otp = await screen.findByRole("textbox", { name: "Verification code" });
    fireEvent.change(otp, { target: { value: "123456" } });
    fireEvent.click(screen.getByRole("button", { name: "Verify phone number" }));
    await waitFor(() => expect(screen.getByRole("heading", { name: "Your phone is verified." })).toBeInTheDocument());
    expect(screen.getByText(/OPay validation confirms your wallet and name/i)).toBeInTheDocument();
  });

  it("shows the OPay-returned name as authoritative and not editable", async () => {
    render(<RegistrationFlow />);
    fireEvent.change(screen.getByRole("textbox", { name: "Nigerian phone number" }), { target: { value: "08012345678" } });
    fireEvent.click(screen.getByRole("button", { name: "Send verification code" }));
    fireEvent.change(await screen.findByRole("textbox", { name: "Verification code" }), { target: { value: "123456" } });
    fireEvent.click(screen.getByRole("button", { name: "Verify phone number" }));
    fireEvent.click(await screen.findByRole("button", { name: "Confirm OPay wallet" }));
    expect(await screen.findByText("Adaeze Okafor")).toBeInTheDocument();
    expect(screen.queryByRole("textbox", { name: /name/i })).not.toBeInTheDocument();
    expect(screen.getByText(/cannot be edited in Betplus/i)).toBeInTheDocument();
  });

  it("preserves progress when OPay is unavailable", async () => {
    render(<RegistrationFlow gateway={createMockRegistrationGateway("unavailable")} />);
    fireEvent.change(screen.getByRole("textbox", { name: "Nigerian phone number" }), { target: { value: "08012345678" } });
    fireEvent.click(screen.getByRole("button", { name: "Send verification code" }));
    fireEvent.change(await screen.findByRole("textbox", { name: "Verification code" }), { target: { value: "123456" } });
    fireEvent.click(screen.getByRole("button", { name: "Verify phone number" }));
    fireEvent.click(await screen.findByRole("button", { name: "Confirm OPay wallet" }));
    expect(await screen.findByText(/verified phone number is saved/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Confirm OPay wallet" })).toBeInTheDocument();
  });

  it("verifies NIN and date of birth into Tier 1 using server-returned limits", async () => {
    render(<RegistrationFlow />);
    fireEvent.change(screen.getByRole("textbox", { name: "Nigerian phone number" }), { target: { value: "08012345678" } });
    fireEvent.click(screen.getByRole("button", { name: "Send verification code" }));
    fireEvent.change(await screen.findByRole("textbox", { name: "Verification code" }), { target: { value: "123456" } });
    fireEvent.click(screen.getByRole("button", { name: "Verify phone number" }));
    fireEvent.click(await screen.findByRole("button", { name: "Confirm OPay wallet" }));
    fireEvent.click(await screen.findByRole("button", { name: "Yes, confirm name" }));
    fireEvent.click(screen.getByRole("button", { name: "Continue to identity verification" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Date of birth" }), { target: { value: "13/08/1990" } });
    fireEvent.change(screen.getByRole("textbox", { name: "National Identification Number (NIN)" }), { target: { value: "12345678901" } });
    fireEvent.click(screen.getByRole("button", { name: "Verify identity" }));
    expect(await screen.findByRole("heading", { name: "Your identity is verified." })).toBeInTheDocument();
    expect(screen.getByText(/₦200,000/)).toBeInTheDocument();
    expect(screen.queryByText("12345678901")).not.toBeInTheDocument();
  });

  it("provides the KYC_TIER_REQUIRED explanation without exposing the code as copy", () => {
    const { container } = render(<KycTierGate />);
    expect(container.querySelector('[data-error-code="KYC_TIER_REQUIRED"]')).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Verify your identity before playing" })).toBeInTheDocument();
    expect(screen.queryByText("KYC_TIER_REQUIRED")).not.toBeInTheDocument();
  });

  it("uses a non-disclosing OTP failure message", async () => {
    render(<RegistrationFlow />);
    fireEvent.change(screen.getByRole("textbox", { name: "Nigerian phone number" }), { target: { value: "08012345678" } });
    fireEvent.submit(screen.getByRole("button", { name: "Send verification code" }).closest("form")!);
    const otp = await screen.findByRole("textbox", { name: "Verification code" });
    fireEvent.change(otp, { target: { value: "000000" } });
    fireEvent.click(screen.getByRole("button", { name: "Verify phone number" }));
    await waitFor(() => expect(screen.getAllByText(/incorrect or has expired/i).length).toBeGreaterThan(0));
  });
});
