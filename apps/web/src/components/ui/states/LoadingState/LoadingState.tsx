import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./LoadingState.module.css";

interface LoadingStateProps {
  label: string;
  description?: string;
}

export function LoadingState({ label, description }: LoadingStateProps) {
  return (
    <div className={styles.state} role="status" aria-live="polite" aria-atomic="true">
      <Icon className={styles.spinner} name="loading" size="navigation" />
      <div><strong>{label}</strong>{description && <p>{description}</p>}</div>
    </div>
  );
}
