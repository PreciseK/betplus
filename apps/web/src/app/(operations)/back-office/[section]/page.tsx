import { notFound } from "next/navigation";
import { OPERATIONS_DESTINATIONS } from "@/components/operations/operations-navigation";
import { AdjustmentsConsole } from "@/components/operations/AdjustmentsConsole/AdjustmentsConsole";
import { ConfigurationsRedirectNotice } from "@/components/operations/ConfigurationsRedirectNotice/ConfigurationsRedirectNotice";
import { ContentConsole } from "@/components/operations/ContentConsole/ContentConsole";
import { DailySummaryConsole } from "@/components/operations/DailySummaryConsole/DailySummaryConsole";
import { DepositsConsole } from "@/components/operations/DepositsConsole/DepositsConsole";
import { OperationsShell } from "@/components/operations/OperationsShell/OperationsShell";
import { PayoutsConsole } from "@/components/operations/PayoutsConsole/PayoutsConsole";
import { Player360, type Player360View } from "@/components/operations/Player360/Player360";
import {
  PlayerProtectionConsole,
  type PlayerProtectionView,
} from "@/components/operations/PlayerProtectionConsole/PlayerProtectionConsole";
import { RolesConsole } from "@/components/operations/RolesConsole/RolesConsole";
import { UsersConsole } from "@/components/operations/UsersConsole/UsersConsole";
import type { OperatorRole } from "@/mocks/operator-session";

const implementedDestinations = new Set([
  "/back-office/overview",
  "/back-office/audit-log",
  "/back-office/players",
  "/back-office/tickets",
  "/back-office/money",
  "/back-office/jurisdictions",
  "/back-office/games",
  "/back-office/reports",
  "/back-office/analytics",
]);

const sectionDestinations = OPERATIONS_DESTINATIONS.filter((destination) => !implementedDestinations.has(destination.href));

// "player-ledger" and "player-tax" route to "payments" — the closest real view.
// There's no raw ledger-entry listing or a distinct tax-deductions endpoint on
// Player360's backend today; net_credit_kobo per ticket is the only real tax-
// adjacent figure available, and it's already shown on the tickets view.
const playerDetailViews: Record<string, Player360View> = {
  "player-ledger": "payments",
  "player-tickets": "tickets",
  "player-payments": "payments",
  "player-tax": "payments",
  "player-notifications": "notifications",
  "player-responsible-play": "responsible-play",
};

const playerProtectionViews: Record<string, PlayerProtectionView> = {
  "responsible-play": "reviews",
  "player-limits": "limits",
  exclusions: "exclusions",
};

const managementSectionMeta: Record<string, { role: OperatorRole; operatorName: string }> = {
  deposits: { role: "finance", operatorName: "Chiamaka Obi" },
  "payout-float": { role: "finance", operatorName: "Chiamaka Obi" },
  adjustments: { role: "finance", operatorName: "Chiamaka Obi" },
  "game-configurations": { role: "game-ops", operatorName: "Tobi Akinwale" },
  content: { role: "content-editor", operatorName: "Adesewa Cole" },
  users: { role: "system-admin", operatorName: "Femi Lawal" },
  roles: { role: "system-admin", operatorName: "Femi Lawal" },
};

export function generateStaticParams() {
  return sectionDestinations.map((destination) => ({ section: destination.href.split("/").at(-1) }));
}

export default async function OperationsSectionPage({
  params,
}: {
  params: Promise<{ section: string }>;
}) {
  const { section } = await params;
  const destination = sectionDestinations.find((item) => item.href.endsWith(`/${section}`));
  if (!destination) notFound();

  const playerView = playerDetailViews[section];
  if (playerView) {
    return (
      <OperationsShell operatorName="Amaka Okafor" role="support-lead" requiredCapability="players.read">
        <Player360 view={playerView} />
      </OperationsShell>
    );
  }

  const protectionView = playerProtectionViews[section];
  if (protectionView) {
    return (
      <OperationsShell operatorName="Amina Bello" role="compliance" requiredCapability="responsible-play.read">
        <PlayerProtectionConsole view={protectionView} />
      </OperationsShell>
    );
  }

  if (section === "daily-summary") {
    return (
      <OperationsShell operatorName="Amina Bello" role="compliance" requiredCapability="analytics.read">
        <DailySummaryConsole />
      </OperationsShell>
    );
  }

  const managementMeta = managementSectionMeta[section];
  if (managementMeta) {
    return (
      <OperationsShell
        operatorName={managementMeta.operatorName}
        role={managementMeta.role}
        requiredCapability={destination.capability}
      >
        {section === "deposits" && <DepositsConsole />}
        {section === "payout-float" && <PayoutsConsole />}
        {section === "adjustments" && <AdjustmentsConsole />}
        {section === "game-configurations" && <ConfigurationsRedirectNotice />}
        {section === "content" && <ContentConsole />}
        {section === "users" && <UsersConsole />}
        {section === "roles" && <RolesConsole />}
      </OperationsShell>
    );
  }

  notFound();
}
