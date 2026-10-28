import type { Metadata } from "next";
import InformationPage from "@/components/landing/InformationPage";

export const metadata: Metadata = {
  title: "About Betplus — Games, payments and ways to play",
  description: "Learn about BlackRed, Heritage and Caged, OPay payments, USSD access and responsible play on Betplus.",
};

export default function AboutPage() {
  return <InformationPage />;
}
