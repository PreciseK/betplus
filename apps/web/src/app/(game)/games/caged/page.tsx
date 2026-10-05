import type { Metadata } from "next";
import { ConnectedCagedGameFlow } from "@/components/games/CagedGameFlow/ConnectedCagedGameFlow";
import { GameAccessGate } from "@/components/games/GameAccessGate/GameAccessGate";

export const metadata: Metadata = {
  title: "Caged — Betplus",
  description: "Play Caged, Betplus's live multiplayer crash prediction game.",
};

export default function CagedGamePage() {
  return (
    <GameAccessGate gameTitle="Caged">
      <ConnectedCagedGameFlow />
    </GameAccessGate>
  );
}
