import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { ConnectedActivityDashboard } from "@/components/wallet/ActivityDashboard/ConnectedActivityDashboard";

export default function ActivityPage() {
  return (
    <PlayerPage hideHeader={true}>
      <ConnectedActivityDashboard />
    </PlayerPage>
  );
}
