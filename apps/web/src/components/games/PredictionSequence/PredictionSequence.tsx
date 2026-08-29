import type { BlackRedColor } from "@/mocks/blackred";
import styles from "./PredictionSequence.module.css";

interface PredictionSequenceProps {
  label: string;
  sequence: BlackRedColor[];
  reveal?: boolean;
}

export function PredictionSequence({ label, sequence, reveal = false }: PredictionSequenceProps) {
  return (
    <div className={styles.sequence}>
      <strong>{label}</strong>
      <ol aria-label={`${label}: ${sequence.map(colorName).join(", ")}`}>
        {sequence.map((color, index) => (
          <li key={`${color}-${index}`} data-color={color} data-reveal={reveal ? "true" : undefined}>
            <span aria-hidden="true">{color}</span>
            <small>{colorName(color)}</small>
          </li>
        ))}
      </ol>
    </div>
  );
}

function colorName(color: BlackRedColor) {
  return color === "B" ? "Black" : "Red";
}
