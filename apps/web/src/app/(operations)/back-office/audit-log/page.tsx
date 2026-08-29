import type { Metadata } from "next";
import { AuditLog } from "@/components/operations/AuditLog/AuditLog";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";

export const metadata: Metadata = {
  title: "Audit log | Betplus Back Office",
  description: "Immutable operator activity and change evidence.",
};

export default function AuditLogPage() {
  return (
    <OperationsShell operatorName="Amaka Okafor" role="support-lead" requiredCapability="audit.read">
      <AuditLog />
    </OperationsShell>
  );
}
