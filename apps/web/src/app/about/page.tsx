import type { Metadata } from "next";
import InformationPage from "@/components/landing/InformationPage";

export const metadata: Metadata = {
  title: "About Betplus — Games, payments and ways to play",
  description: "Learn about BlackRed, Heritage, Caged and Doors, OPay payments, available USSD games and responsible play on Betplus.",
};

export default function AboutPage() {
  return <InformationPage />;
}
