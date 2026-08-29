import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { SettingsDashboard } from "@/components/settings/SettingsDashboard/SettingsDashboard";

export const metadata = {
  title: "Settings — Betplus",
  description: "Manage your profile, notifications, security and play limits.",
};

export default function SettingsPage() {
  return (
    <PlayerPage hideHeader={true}>
      <SettingsDashboard />
    </PlayerPage>
  );
}
