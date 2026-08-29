import type { Metadata } from "next";
import { MakerCheckerWorkspace } from "@/components/operations/MakerCheckerWorkspace/MakerCheckerWorkspace";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";

export const metadata: Metadata = {
  title: "Change approvals | Betplus Back Office",
  description: "Shared maker-checker workflow for sensitive operator changes.",
};

export default function ChangeApprovalsPage() {
  return (
    <OperationsShell operatorName="Ifeoma Nwosu" role="compliance" requiredCapability="approvals.review">
      <MakerCheckerWorkspace />
    </OperationsShell>
  );
}
