import { AccessSection } from "@/components/landing/AccessSection";
import { FinalCta } from "@/components/landing/FinalCta";
import { GamesSection } from "@/components/landing/GamesSection";
import { Hero } from "@/components/landing/Hero";
import { HowItWorks } from "@/components/landing/HowItWorks";
import { SafePlaySection } from "@/components/landing/SafePlaySection";
import { SiteFooter } from "@/components/landing/SiteFooter";
import { SiteHeader } from "@/components/landing/SiteHeader";
import { WalletSection } from "@/components/landing/WalletSection";
import { LandingMotion } from "@/components/landing/LandingMotion";

export default function Home() {
  return (
    <>
      <a className="skip-link" href="#main">
        Skip to main content
      </a>
      <div className="scroll-progress" aria-hidden="true" />
      <LandingMotion />
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
    </>
  );
}
