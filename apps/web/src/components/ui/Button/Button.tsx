import type { AnchorHTMLAttributes, ButtonHTMLAttributes, ReactNode } from "react";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./Button.module.css";

export type ButtonVariant = "primary" | "secondary" | "quiet" | "dark" | "danger";
export type ButtonStatus = "idle" | "loading" | "success" | "error";

interface ButtonOwnProps {
  variant?: ButtonVariant;
  status?: ButtonStatus;
  statusLabel?: string;
  leadingIcon?: ReactNode;
  children: ReactNode;
}

type ButtonAsLink = ButtonOwnProps &
  Omit<AnchorHTMLAttributes<HTMLAnchorElement>, "href"> & {
    href: string;
    disabled?: boolean;
  };

type ButtonAsButton = ButtonOwnProps &
  ButtonHTMLAttributes<HTMLButtonElement> & { href?: undefined };

export type ButtonProps = ButtonAsLink | ButtonAsButton;

const DEFAULT_STATUS_LABELS: Record<Exclude<ButtonStatus, "idle">, string> = {
  loading: "Loading…",
  success: "Completed",
  error: "Try again",
};

export function Button({
  variant = "primary",
  status = "idle",
  statusLabel,
  leadingIcon,
  className,
  children,
  ...props
}: ButtonProps) {
  const classes = [styles.button, styles[variant], styles[status], className]
    .filter(Boolean)
    .join(" ");
  const isLoading = status === "loading";
  const visibleLabel = status === "idle" ? children : (statusLabel ?? DEFAULT_STATUS_LABELS[status]);
  const content = (
    <span className={styles.content} aria-live="polite">
      {status === "idle" ? leadingIcon : (
        <Icon
          name={status === "success" ? "check" : status}
          size="control"
          className={status === "loading" ? styles.spinner : undefined}
        />
      )}
      <span>{visibleLabel}</span>
    </span>
  );

  if ("href" in props && props.href !== undefined) {
    const { href, disabled = false, ...anchorProps } = props;
    const isUnavailable = disabled || isLoading;

    return (
      <a
        {...anchorProps}
        href={isUnavailable ? undefined : href}
        className={classes}
        aria-busy={isLoading || undefined}
        aria-disabled={isUnavailable || undefined}
        data-status={status}
        tabIndex={isUnavailable ? -1 : anchorProps.tabIndex}
      >
        {content}
      </a>
    );
  }

  const { type = "button", disabled = false, ...buttonProps } = props as ButtonAsButton;

  return (
    <button
      {...buttonProps}
      type={type}
      className={classes}
      disabled={disabled || isLoading}
      aria-busy={isLoading || undefined}
      data-status={status}
    >
      {content}
    </button>
  );
}
