import type { Metadata } from "next";
import { HomepageConcept } from "./homepage-concept/HomepageConcept";

export const metadata: Metadata = {
  title: "Betplus — BlackRed, Heritage, Caged and Doors",
  description: "Choose your game on Betplus. Play BlackRed, explore Heritage, enter Caged or discover treasure in Doors, with one account and your OPay wallet.",
};

export default function Home() {
  return <HomepageConcept preview={false} />;
}
