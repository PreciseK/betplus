import type { Metadata } from "next";
import { GameEconomicsConfigConsole } from "@/components/operations/GameEconomicsConfigConsole/GameEconomicsConfigConsole";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";

export const metadata: Metadata = {
  title: "Game Economics Models | Betplus Back Office",
  description: "Switch which economics model governs bet acceptance for a game.",
};

export default function GameEconomicsPage() {
  return (
    <OperationsShell operatorName="Tobi Akinwale" role="game-ops" requiredCapability="games.manage">
      <GameEconomicsConfigConsole />
    </OperationsShell>
  );
}
