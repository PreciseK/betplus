import type { Metadata } from "next";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";
import { ReconciliationConsole } from "@/components/operations/ReconciliationConsole/ReconciliationConsole";

export const metadata: Metadata = {
  title: "Reconciliation Exceptions | Betplus Back Office",
  description: "Investigate provider and ledger reconciliation exceptions and propose compensating entries.",
};

export default function MoneyPage() {
  return (
    <OperationsShell operatorName="Chiamaka Obi" role="finance" requiredCapability="money.read">
      <ReconciliationConsole />
    </OperationsShell>
  );
}
