import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { ConnectedWalletDashboard } from "@/components/wallet/WalletDashboard/ConnectedWalletDashboard";

export default function WalletPage() {
  return (
    <PlayerPage eyebrow="Wallet" title="Wallet" description="Fund, withdraw and review money movement from one place.">
      <ConnectedWalletDashboard />
    </PlayerPage>
  );
}
