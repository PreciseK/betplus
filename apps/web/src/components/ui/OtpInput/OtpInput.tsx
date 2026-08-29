"use client";

import { useState, type ChangeEvent, type InputHTMLAttributes } from "react";
import { FormField } from "@/components/ui/FormField/FormField";
import styles from "./OtpInput.module.css";

export interface OtpInputProps extends Omit<
  InputHTMLAttributes<HTMLInputElement>,
  "id" | "type" | "inputMode" | "value" | "defaultValue" | "onChange" | "maxLength"
> {
  id: string;
  label?: string;
  helperText?: string;
  errorText?: string;
  value?: string;
  defaultValue?: string;
  onValueChange?: (value: string) => void;
}

const OTP_LENGTH = 6;

function cleanOtp(value: string) {
  return value.replace(/\D/g, "").slice(0, OTP_LENGTH);
}

export function OtpInput({
  id,
  label = "Verification code",
  helperText,
  errorText,
  value,
  defaultValue = "",
  onValueChange,
  className,
  ...inputProps
}: OtpInputProps) {
  const [internalValue, setInternalValue] = useState(() => cleanOtp(defaultValue));
  const currentValue = value === undefined ? internalValue : cleanOtp(value);

  const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
    const nextValue = cleanOtp(event.target.value);
    if (value === undefined) setInternalValue(nextValue);
    onValueChange?.(nextValue);
  };

  return (
    <FormField id={id} label={label} helperText={helperText} errorText={errorText} required>
      {(controlProps) => {
        const describedBy = [inputProps["aria-describedby"], controlProps["aria-describedby"]]
          .filter(Boolean)
          .join(" ") || undefined;

        return (
          <input
            {...inputProps}
            {...controlProps}
            className={[styles.input, className].filter(Boolean).join(" ")}
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            enterKeyHint="done"
            pattern="[0-9]{6}"
            maxLength={OTP_LENGTH}
            value={currentValue}
            required
            aria-describedby={describedBy}
            onChange={handleChange}
          />
        );
      }}
    </FormField>
  );
}
