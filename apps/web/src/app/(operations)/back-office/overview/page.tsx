import type { Metadata } from "next";
import { OperationsOverview } from "@/components/operations/OperationsOverview/OperationsOverview";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";

export const metadata: Metadata = {
  title: "Operations overview — Betplus back office",
  description: "Actionable Betplus operations exceptions and shift context.",
};

export default function OperationsOverviewPage() {
  return (
    <OperationsShell operatorName="Amina Bello" role="super-admin" requiredCapability="overview.view">
      <OperationsOverview role="super-admin" />
    </OperationsShell>
  );
}
