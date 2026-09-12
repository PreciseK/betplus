import type { Metadata } from "next";
import { ConnectedBirdEscapeGameFlow } from "@/components/games/BirdEscapeGameFlow/ConnectedBirdEscapeGameFlow";
import { GameAccessGate } from "@/components/games/GameAccessGate/GameAccessGate";

export const metadata: Metadata = {
  title: "Caged — Betplus",
  description: "Play Caged, Betplus's live multiplayer crash prediction game.",
};

export default function BirdEscapeGamePage() {
  return (
    <GameAccessGate gameTitle="Caged">
      <ConnectedBirdEscapeGameFlow />
    </GameAccessGate>
  );
}
