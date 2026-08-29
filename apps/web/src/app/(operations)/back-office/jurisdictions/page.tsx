import type { Metadata } from "next";
import { JurisdictionConsole } from "@/components/operations/JurisdictionConsole/JurisdictionConsole";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";

export const metadata: Metadata = {
  title: "Jurisdictions and Tax | Betplus Back Office",
  description: "Monitor state licences, registry checks, tax rulesets and remittance obligations.",
};

export default function JurisdictionsPage() {
  return (
    <OperationsShell operatorName="Nkiru Eze" role="compliance" requiredCapability="jurisdictions.read">
      <JurisdictionConsole />
    </OperationsShell>
  );
}
