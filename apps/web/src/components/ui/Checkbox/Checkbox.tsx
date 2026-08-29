import type { InputHTMLAttributes } from "react";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./Checkbox.module.css";

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "id" | "type"> {
  id: string;
  label: string;
  description?: string;
  errorText?: string;
}

export function Checkbox({
  id,
  label,
  description,
  errorText,
  className,
  ...inputProps
}: CheckboxProps) {
  const descriptionId = description ? `${id}-description` : undefined;
  const errorId = errorText ? `${id}-error` : undefined;
  const describedBy = [inputProps["aria-describedby"], descriptionId, errorId]
    .filter(Boolean)
    .join(" ") || undefined;

  return (
    <div className={styles.field} data-invalid={errorText ? "true" : undefined}>
      <label className={styles.control} htmlFor={id}>
        <input
          {...inputProps}
          className={[styles.input, className].filter(Boolean).join(" ")}
          id={id}
          type="checkbox"
          aria-describedby={describedBy}
          aria-invalid={errorText ? true : undefined}
        />
        <span className={styles.copy}>
          <span className={styles.label}>{label}</span>
          {description && <span className={styles.description} id={descriptionId}>{description}</span>}
        </span>
      </label>
      {errorText && (
        <p className={styles.error} id={errorId} role="alert">
          <Icon name="error" size="inline" />
          <span>{errorText}</span>
        </p>
      )}
    </div>
  );
}
