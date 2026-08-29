import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { ConnectedWalletDashboard } from "@/components/wallet/WalletDashboard/ConnectedWalletDashboard";

export default function WithdrawPage() {
  return (
    <PlayerPage eyebrow="Wallet" title="Withdraw Funds" description="Submit payout request to your verified payment destination under responsible play rules.">
      <ConnectedWalletDashboard initialMode="withdrawal" />
    </PlayerPage>
  );
}
