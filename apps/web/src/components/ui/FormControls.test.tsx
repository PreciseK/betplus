import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { Checkbox } from "@/components/ui/Checkbox/Checkbox";
import { MoneyInput } from "@/components/ui/MoneyInput/MoneyInput";
import { OtpInput } from "@/components/ui/OtpInput/OtpInput";
import { TextField } from "@/components/ui/TextField/TextField";

describe("form controls", () => {
  it("links a text field to actionable error copy", () => {
    render(
      <TextField
        id="phone"
        label="Phone number"
        errorText="Enter an 11-digit Nigerian phone number, for example 0801 234 5678."
      />,
    );

    const input = screen.getByRole("textbox", { name: "Phone number" });
    const error = screen.getByText("Enter an 11-digit Nigerian phone number, for example 0801 234 5678.");
    expect(input).toHaveAttribute("aria-invalid", "true");
    expect(input).toHaveAttribute("aria-describedby", expect.stringContaining(error.id));
  });

  it("emits money as integer kobo without editing the Naira symbol", () => {
    const onValueChange = vi.fn();
    render(<MoneyInput id="stake" label="Stake" onValueChange={onValueChange} />);

    const input = screen.getByRole("textbox", { name: "Stake" });
    fireEvent.change(input, { target: { value: "1,234.50" } });

    expect(input).toHaveValue("1234.50");
    expect(onValueChange).toHaveBeenLastCalledWith(123450);
    expect(screen.getByText("₦")).toHaveAttribute("aria-hidden", "true");
  });

  it("keeps OTP as one logical input and accepts pasted digits", () => {
    const onValueChange = vi.fn();
    render(<OtpInput id="otp" onValueChange={onValueChange} />);

    const input = screen.getByRole("textbox", { name: "Verification code" });
    fireEvent.change(input, { target: { value: "12 34a56" } });

    expect(input).toHaveValue("123456");
    expect(input).toHaveAttribute("autocomplete", "one-time-code");
    expect(onValueChange).toHaveBeenLastCalledWith("123456");
    expect(screen.getAllByRole("textbox")).toHaveLength(1);
  });

  it("does not rely on colour for a checkbox error", () => {
    render(
      <Checkbox
        id="terms"
        label="I accept the terms"
        errorText="Accept the terms to create your account."
      />,
    );

    const checkbox = screen.getByRole("checkbox", { name: "I accept the terms" });
    expect(checkbox).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByRole("alert")).toHaveTextContent("Accept the terms to create your account.");
  });
});
