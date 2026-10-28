import type { Metadata } from "next";
import { HomepageConcept } from "./homepage-concept/HomepageConcept";

export const metadata: Metadata = {
  title: "Betplus — BlackRed, Heritage and Caged",
  description: "Choose your game on Betplus. Play BlackRed, explore Heritage or enter Caged, with one account and your OPay wallet.",
};

export default function Home() {
  return <HomepageConcept preview={false} />;
}
