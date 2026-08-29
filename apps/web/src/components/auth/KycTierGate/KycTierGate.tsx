import { Button } from "@/components/ui/Button/Button";
import { FullPageMessage } from "@/components/ui/feedback/FullPageMessage/FullPageMessage";

export function KycTierGate() {
  return (
    <div data-error-code="KYC_TIER_REQUIRED">
      <FullPageMessage
        tone="warning"
        title="Verify your identity before playing"
        actions={<><Button href="/register">Continue verification</Button><Button href="/games" variant="secondary">Back to games</Button></>}
      >
        Your NIN helps us confirm age and identity and complete required player-protection checks. Your account and withdrawals remain available.
      </FullPageMessage>
    </div>
  );
}
