import type { Metadata } from "next";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";
import { TicketReplayAudit } from "@/components/operations/TicketReplayAudit/TicketReplayAudit";

export const metadata: Metadata = {
  title: "Ticket Replay Audit | Betplus Back Office",
  description: "Reconstruct a settled BlackRed or Heritage outcome from its sealed seed.",
};

export default function TicketsPage() {
  return (
    <OperationsShell operatorName="Nkiru Eze" role="compliance" requiredCapability="tickets.read">
      <TicketReplayAudit />
    </OperationsShell>
  );
}
