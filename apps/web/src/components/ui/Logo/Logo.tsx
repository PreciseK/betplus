import styles from "./Logo.module.css";

interface LogoProps {
  withWordmark?: boolean;
  footer?: boolean;
  size?: "small" | "medium" | "large";
  href?: string;
  variant?: "white" | "color";
  height?: number;
  className?: string;
}

export function Logo({
  withWordmark = true,
  footer = false,
  size,
  href = "#top",
  variant = "white",
  height,
  className,
}: LogoProps) {
  const isLarge = footer || size === "large";
  const classes = [styles.logo, isLarge && styles.large, className].filter(Boolean).join(" ");
  const logoSrc = variant === "color"
    ? "/assets/betplus-logo-color.png"
    : "/assets/betplus-logo-white.png";

  return (
    <a className={classes} href={href} aria-label="Betplus home">
      <img
        src={logoSrc}
        alt="Betplus"
        className={styles.logoImg}
        style={height ? { height: `${height}px` } : undefined}
      />
    </a>
  );
}
