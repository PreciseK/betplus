import type { Metadata } from "next";
import { GameRegistryConsole } from "@/components/operations/GameRegistryConsole/GameRegistryConsole";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";

export const metadata: Metadata = {
  title: "Games and Prize Tables | Betplus Back Office",
  description: "Manage game runtime configuration and validate prize-table RTP before publication.",
};

export default function GamesPage() {
  return (
    <OperationsShell operatorName="Tobi Akinwale" role="game-ops" requiredCapability="games.manage">
      <GameRegistryConsole />
    </OperationsShell>
  );
}
