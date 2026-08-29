import { Icon, type IconName } from "@/components/ui/Icon/Icon";

export type FeedbackTone = "info" | "success" | "warning" | "error";

const ICONS: Record<FeedbackTone, IconName> = {
  info: "info",
  success: "check",
  warning: "warning",
  error: "error",
};

export function FeedbackIcon({ tone }: { tone: FeedbackTone }) {
  return <Icon name={ICONS[tone]} size="inline" />;
}
