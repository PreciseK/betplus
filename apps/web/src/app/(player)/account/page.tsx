import { ConnectedResponsiblePlayDashboard } from "@/components/account/ResponsiblePlayDashboard/ConnectedResponsiblePlayDashboard";
import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";

export default function AccountPage() {
  return (
    <PlayerPage title="Account" description="Manage your play limits and account access.">
      <ConnectedResponsiblePlayDashboard />
    </PlayerPage>
  );
}
