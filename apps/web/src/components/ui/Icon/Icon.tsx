import type { ReactNode, SVGAttributes } from "react";
import styles from "./Icon.module.css";

export type IconName =
  | "account"
  | "activity"
  | "arrow-left"
  | "arrow-right"
  | "calendar"
  | "check"
  | "chevron-right"
  | "close"
  | "error"
  | "eye"
  | "eye-off"
  | "games"
  | "home"
  | "info"
  | "limits"
  | "loading"
  | "lock"
  | "money"
  | "phone"
  | "support"
  | "ticket"
  | "wallet"
  | "warning";

export type IconSize = "control" | "inline" | "navigation" | "empty";

export interface IconProps extends Omit<SVGAttributes<SVGSVGElement>, "children"> {
  name: IconName;
  size?: IconSize;
  label?: string;
}

const ICON_PATHS: Record<IconName, ReactNode> = {
  account: <><circle cx="12" cy="8" r="3.25" /><path d="M5.5 20c.55-3.55 2.75-5.5 6.5-5.5s5.95 1.95 6.5 5.5" /></>,
  activity: <><path d="M4 12h3l2-5 4 10 2-5h5" /><path d="M20 7v5h-5" /></>,
  "arrow-left": <path d="M19 12H5m6-6-6 6 6 6" />,
  "arrow-right": <path d="M5 12h14m-6-6 6 6-6 6" />,
  calendar: <><rect x="3.5" y="5" width="17" height="15.5" rx="2" /><path d="M8 3v4m8-4v4M3.5 10h17" /></>,
  check: <path d="m5 12 4 4 10-10" />,
  "chevron-right": <path d="m9 18 6-6-6-6" />,
  close: <path d="m6 6 12 12M18 6 6 18" />,
  error: <><circle cx="12" cy="12" r="9" /><path d="M12 7v6m0 4h.01" /></>,
  eye: <><path d="M2.5 12s3.25-5 9.5-5 9.5 5 9.5 5-3.25 5-9.5 5-9.5-5-9.5-5Z" /><circle cx="12" cy="12" r="2.25" /></>,
  "eye-off": <><path d="m4 4 16 16M9.6 7.35A10.8 10.8 0 0 1 12 7c6.25 0 9.5 5 9.5 5a16 16 0 0 1-2.2 2.6M6.2 6.7C3.75 8.25 2.5 12 2.5 12s3.25 5 9.5 5c.85 0 1.65-.1 2.4-.28" /></>,
  games: <><rect x="3.5" y="4.5" width="17" height="15" rx="3" /><path d="M8 9v6m-3-3h6m4.5-2.5h.01m3 5h.01" /></>,
  home: <><path d="m3 10 9-7 9 7" /><path d="M5.5 9v11h13V9M9.5 20v-6h5v6" /></>,
  info: <><circle cx="12" cy="12" r="9" /><path d="M12 11v6m0-10h.01" /></>,
  limits: <><path d="M4 18V8m6 10V4m6 14v-7m4 7H2" /><path d="M2.5 8h3m3-4h3m3 7h3" /></>,
  loading: <><circle className={styles.loadingTrack} cx="12" cy="12" r="9" /><path d="M12 3a9 9 0 0 1 9 9" /></>,
  lock: <><rect x="4.5" y="10" width="15" height="10.5" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3" /></>,
  money: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M7.5 12h9M12 8.5v7" /></>,
  phone: <path d="M8.2 3.5h7.6a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H8.2a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2Zm1.8 3h4m-3 10.5h2" />,
  support: <><circle cx="12" cy="12" r="9" /><path d="M8 13v-2a4 4 0 0 1 8 0v2m-8 0H6.5a1.5 1.5 0 0 0 0 3H8v-3Zm8 0h1.5a1.5 1.5 0 0 1 0 3H16v-3Zm0 3c0 2-1.5 3-4 3" /></>,
  ticket: <path d="M4 5h16v4a3 3 0 0 0 0 6v4H4v-4a3 3 0 0 0 0-6V5Zm8 2v2m0 2v2m0 2v2" />,
  wallet: <><path d="M4 6.5h13a2 2 0 0 1 2 2V19H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h11" /><path d="M15 11h6v5h-6a2.5 2.5 0 0 1 0-5Z" /></>,
  warning: <><path d="M12 3 2.75 20h18.5L12 3Z" /><path d="M12 9v5m0 3h.01" /></>,
};

export function Icon({ name, size = "inline", label, className, ...props }: IconProps) {
  const classes = [styles.icon, styles[size], className].filter(Boolean).join(" ");

  return (
    <svg
      {...props}
      className={classes}
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden={label ? undefined : true}
      aria-label={label}
      role={label ? "img" : undefined}
    >
      {ICON_PATHS[name]}
    </svg>
  );
}
