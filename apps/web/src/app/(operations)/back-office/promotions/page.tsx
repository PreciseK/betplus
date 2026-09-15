import type { Metadata } from "next";
import { PromotionsConsole } from "@/components/operations/PromotionsConsole/PromotionsConsole";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";

export const metadata: Metadata = {
  title: "Promotions & Boost Controls | Betplus Back Office",
  description: "Manage promotional campaigns, emergency kill-switches, and Maker-Checker proposals for Betplus promotions.",
};

export default function PromotionsPage() {
  return (
    <OperationsShell operatorName="Tobi Akinwale" role="game-ops" requiredCapability="games.manage">
      <PromotionsConsole />
    </OperationsShell>
  );
}
