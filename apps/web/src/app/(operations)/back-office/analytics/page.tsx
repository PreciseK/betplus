import type { Metadata } from "next";
import { AnalyticsConsole } from "@/components/operations/AnalyticsConsole/AnalyticsConsole";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";

export const metadata: Metadata = {
  title: "First-party Analytics | Betplus Back Office",
  description: "Inspect privacy-safe Betplus funnel rollups with paired responsible-play harm indicators.",
};

export default function AnalyticsPage() {
  return <OperationsShell operatorName="Tobi Akinwale" role="game-ops" requiredCapability="analytics.read"><AnalyticsConsole /></OperationsShell>;
}
