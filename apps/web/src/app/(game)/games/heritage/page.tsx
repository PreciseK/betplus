import type { Metadata } from "next";
import { ConnectedHeritageGameFlow } from "@/components/games/HeritageGameFlow/ConnectedHeritageGameFlow";
import { GameAccessGate } from "@/components/games/GameAccessGate/GameAccessGate";

export const metadata: Metadata = {
  title: "Heritage — Betplus",
  description: "Play Heritage, Betplus's transparent 5-of-9 culture-themed instant-win game.",
};

export default function HeritagePage() {
  return (
    <GameAccessGate gameTitle="Heritage">
      <ConnectedHeritageGameFlow />
    </GameAccessGate>
  );
}
