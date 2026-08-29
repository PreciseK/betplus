import type { ReactNode } from "react";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./FormField.module.css";

export interface FormFieldControlProps {
  id: string;
  "aria-describedby"?: string;
  "aria-invalid"?: true;
}

interface FormFieldProps {
  id: string;
  label: string;
  helperText?: string;
  errorText?: string;
  required?: boolean;
  showRequiredIndicator?: boolean;
  children: (controlProps: FormFieldControlProps) => ReactNode;
}

export function FormField({
  id,
  label,
  helperText,
  errorText,
  required = false,
  showRequiredIndicator = false,
  children,
}: FormFieldProps) {
  const helperId = helperText ? `${id}-helper` : undefined;
  const errorId = errorText ? `${id}-error` : undefined;
  const describedBy = [helperId, errorId].filter(Boolean).join(" ") || undefined;

  return (
    <div className={styles.field} data-invalid={errorText ? "true" : undefined}>
      <div className={styles.labelRow}>
        <label className={styles.label} htmlFor={id}>{label}</label>
        {required && showRequiredIndicator && (
          <span className={styles.required}>
            <span aria-hidden="true">*</span>
            <span className="sr-only">Required</span>
          </span>
        )}
      </div>

      {children({
        id,
        "aria-describedby": describedBy,
        "aria-invalid": errorText ? true : undefined,
      })}

      {helperText && (
        <p className={styles.helper} id={helperId}>
          <Icon name="info" size="inline" />
          <span>{helperText}</span>
        </p>
      )}
      {errorText && (
        <p className={styles.error} id={errorId}>
          <Icon name="error" size="inline" />
          <span>{errorText}</span>
        </p>
      )}
    </div>
  );
}
