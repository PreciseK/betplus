import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { ConnectedWalletDashboard } from "@/components/wallet/WalletDashboard/ConnectedWalletDashboard";

export default function WalletPage() {
  return (
    <PlayerPage title="Wallet">
      <ConnectedWalletDashboard />
    </PlayerPage>
  );
}
