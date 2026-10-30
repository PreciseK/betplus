import type { Metadata } from "next";
import { GameAccessGate } from "@/components/games/GameAccessGate/GameAccessGate";
import { ConnectedDoors } from "@/app/doors-demo/ConnectedDoors";

export const metadata: Metadata = { title: "Doors — Betplus", description: "Enter Big Man’s vault. Five attempts in ten seconds; your first treasure wins." };
export default function DoorsPage() {
  return <GameAccessGate gameTitle="Doors"><ConnectedDoors /></GameAccessGate>;
}
