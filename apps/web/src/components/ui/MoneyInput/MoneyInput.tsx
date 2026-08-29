"use client";

import { useState, type ChangeEvent, type InputHTMLAttributes } from "react";
import { FormField } from "@/components/ui/FormField/FormField";
import { formatKoboForInput, parseNairaInputToKobo } from "@/lib/money";
import styles from "./MoneyInput.module.css";

export interface MoneyInputProps extends Omit<
  InputHTMLAttributes<HTMLInputElement>,
  "id" | "type" | "inputMode" | "value" | "defaultValue" | "onChange"
> {
  id: string;
  label: string;
  helperText?: string;
  errorText?: string;
  valueKobo?: number | null;
  defaultValueKobo?: number | null;
  onValueChange?: (valueKobo: number | null) => void;
  showRequiredIndicator?: boolean;
}

function initialDisplayValue(valueKobo?: number | null) {
  return valueKobo === null || valueKobo === undefined ? "" : formatKoboForInput(valueKobo);
}

export function MoneyInput({
  id,
  label,
  helperText,
  errorText,
  valueKobo,
  defaultValueKobo,
  onValueChange,
  showRequiredIndicator,
  required,
  className,
  ...inputProps
}: MoneyInputProps) {
  const [displayValue, setDisplayValue] = useState(() =>
    initialDisplayValue(valueKobo === undefined ? defaultValueKobo : valueKobo),
  );
  const [lastControlledValue, setLastControlledValue] = useState(valueKobo);

  if (valueKobo !== lastControlledValue) {
    setLastControlledValue(valueKobo);
    if (parseNairaInputToKobo(displayValue) !== valueKobo) {
      setDisplayValue(initialDisplayValue(valueKobo));
    }
  }

  const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
    const normalized = event.target.value.replace(/[\s,]/g, "");
    if (normalized && !/^\d*(?:\.\d{0,2})?$/.test(normalized)) return;

    setDisplayValue(normalized);
    onValueChange?.(parseNairaInputToKobo(normalized));
  };

  return (
    <FormField
      id={id}
      label={label}
      helperText={helperText}
      errorText={errorText}
      required={required}
      showRequiredIndicator={showRequiredIndicator}
    >
      {(controlProps) => {
        const describedBy = [inputProps["aria-describedby"], controlProps["aria-describedby"]]
          .filter(Boolean)
          .join(" ") || undefined;

        return (
          <div className={styles.control} data-invalid={errorText ? "true" : undefined}>
            <span className={styles.currency} aria-hidden="true">₦</span>
            <input
              {...inputProps}
              {...controlProps}
              className={[styles.input, className].filter(Boolean).join(" ")}
              type="text"
              inputMode="decimal"
              pattern="[0-9]+([.][0-9]{0,2})?"
              value={displayValue}
              required={required}
              aria-describedby={describedBy}
              onChange={handleChange}
            />
          </div>
        );
      }}
    </FormField>
  );
}
