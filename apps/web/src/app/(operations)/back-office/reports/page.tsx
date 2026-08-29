import type { Metadata } from "next";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";
import { ReportsConsole } from "@/components/operations/ReportsConsole/ReportsConsole";

export const metadata: Metadata = {
  title: "Reports and Exports | Betplus Back Office",
  description: "Create bounded financial and per-state regulatory reports with asynchronous audited exports.",
};

export default function ReportsPage() {
  return <OperationsShell operatorName="Nkiru Eze" role="compliance" requiredCapability="reports.read"><ReportsConsole /></OperationsShell>;
}
