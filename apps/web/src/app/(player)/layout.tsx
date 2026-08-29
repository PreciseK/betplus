import type { ReactNode } from "react";
import { PlayerShell } from "@/components/shell/PlayerShell/PlayerShell";

export default function AuthenticatedLayout({ children }: { children: ReactNode }) {
  return <PlayerShell>{children}</PlayerShell>;
}
