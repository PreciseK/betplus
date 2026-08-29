import type { Metadata } from "next";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";
import { Player360 } from "@/components/operations/Player360/Player360";

export const metadata: Metadata = {
  title: "Current player case | Betplus Back Office",
  description: "Focused player support case evidence with consistent account context.",
};

export default function PlayersPage() {
  return (
    <OperationsShell operatorName="Amaka Okafor" role="support-lead" requiredCapability="players.read">
      <Player360 view="case-resolution" />
    </OperationsShell>
  );
}
