import { ConnectedResponsiblePlayDashboard } from "@/components/account/ResponsiblePlayDashboard/ConnectedResponsiblePlayDashboard";
import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";

export default function AccountPage() {
  return (
    <PlayerPage eyebrow="Account" title="Safety and limits" description="See your true net position, set limits or step away from play. Withdrawal stays available.">
      <ConnectedResponsiblePlayDashboard />
    </PlayerPage>
  );
}
