import type { Metadata } from "next";
import DoorsDemo from "./DoorsDemo";

export const metadata: Metadata = {
  title: "Doors — The Vault | Betplus Demo",
  description: "Find a treasure within five attempts in ten seconds. Gold bars pay 3×, a chest 5×, and a card 2× your naira stake. Simulated balance.",
};

export default function Page() {
  return <DoorsDemo />;
}
