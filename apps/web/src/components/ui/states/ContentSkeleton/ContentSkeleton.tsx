import styles from "./ContentSkeleton.module.css";

interface ContentSkeletonProps {
  label: string;
  lines?: number;
}

/** Use only for known non-financial content structures. Never use for a balance. */
export function ContentSkeleton({ label, lines = 3 }: ContentSkeletonProps) {
  return (
    <div className={styles.skeleton} role="status" aria-label={label}>
      <span className={styles.title} aria-hidden="true" />
      {Array.from({ length: lines }, (_, index) => (
        <span className={index === lines - 1 ? styles.short : styles.line} aria-hidden="true" key={index} />
      ))}
      <span className="sr-only">{label}</span>
    </div>
  );
}
