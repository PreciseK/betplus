import type { InputHTMLAttributes, Ref } from "react";
import { FormField } from "@/components/ui/FormField/FormField";
import { Icon, type IconName } from "@/components/ui/Icon/Icon";
import styles from "./TextField.module.css";

export interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "id"> {
  id: string;
  label: string;
  helperText?: string;
  errorText?: string;
  leadingIcon?: IconName;
  showRequiredIndicator?: boolean;
  ref?: Ref<HTMLInputElement>;
}

export function TextField({
  id,
  label,
  helperText,
  errorText,
  leadingIcon,
  showRequiredIndicator,
  required,
  className,
  ref,
  ...inputProps
}: TextFieldProps) {
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
            {leadingIcon && <Icon className={styles.leadingIcon} name={leadingIcon} size="control" />}
            <input
              {...inputProps}
              {...controlProps}
              ref={ref}
              className={[styles.input, leadingIcon && styles.withIcon, className].filter(Boolean).join(" ")}
              required={required}
              aria-describedby={describedBy}
            />
          </div>
        );
      }}
    </FormField>
  );
}
