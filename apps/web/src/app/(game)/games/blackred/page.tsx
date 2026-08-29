import type { Metadata } from "next";
import { ConnectedBlackRedGameFlow } from "@/components/games/BlackRedGameFlow/ConnectedBlackRedGameFlow";
import { GameAccessGate } from "@/components/games/GameAccessGate/GameAccessGate";

export const metadata: Metadata = {
  title: "BlackRed — Betplus",
  description: "Play BlackRed, Betplus's transparent fixed-odds colour prediction game.",
};

export default function BlackRedPage() {
  return (
    <GameAccessGate gameTitle="BlackRed">
      <ConnectedBlackRedGameFlow />
    </GameAccessGate>
  );
}
