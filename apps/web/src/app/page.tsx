import type { Metadata } from "next";
import { AccessSection } from "@/components/landing/AccessSection";
import { FinalCta } from "@/components/landing/FinalCta";
import { GamesSection } from "@/components/landing/GamesSection";
import { Hero } from "@/components/landing/Hero";
import { HowItWorks } from "@/components/landing/HowItWorks";
import { SafePlaySection } from "@/components/landing/SafePlaySection";
import { SiteFooter } from "@/components/landing/SiteFooter";
import { SiteHeader } from "@/components/landing/SiteHeader";
import { WalletSection } from "@/components/landing/WalletSection";

export const metadata: Metadata = {
  title: "Betplus — BlackRed, Heritage and Caged",
  description: "Explore BlackRed, Heritage and Caged. Manage your wallet, view your transactions and set your play limits on Betplus.",
};

export default function Home() {
  return (
    <div className="landing-page">
      <a className="skip-link" href="#main">Skip to main content</a>
      <SiteHeader />
      <main id="main">
        <Hero />
        <GamesSection />
        <HowItWorks />
        <WalletSection />
        <AccessSection />
        <SafePlaySection />
        <FinalCta />
      </main>
      <SiteFooter />
    </div>
  );
}
