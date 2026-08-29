import { notFound } from "next/navigation";
import { Button } from "@/components/ui/Button/Button";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { PlayerPage } from "@/components/shell/PlayerPage/PlayerPage";
import { getMockReceipt, MOCK_TRANSACTION_REFERENCES } from "@/mocks/wallet";
import { BLACKRED_TICKET_REFERENCES, getMockBlackRedTicket } from "@/mocks/blackred";
import { getMockPayout, PAYOUT_REFERENCES } from "@/mocks/payout";

export const dynamicParams = false;

export function generateStaticParams() {
  return [...MOCK_TRANSACTION_REFERENCES, ...BLACKRED_TICKET_REFERENCES, ...PAYOUT_REFERENCES].map((reference) => ({ reference }));
}

export default async function TransactionHelpPage({ params }: { params: Promise<{ reference: string }> }) {
  const { reference } = await params;
  const transaction = getMockReceipt(reference);
  const ticket = getMockBlackRedTicket(reference);
  const payout = getMockPayout(reference);
  if (!transaction && !ticket && !payout) notFound();
  const item = transaction ?? ticket ?? payout;
  if (!item) notFound();
  const receiptHref = transaction
    ? `/activity/receipt/${item.reference}`
    : ticket
      ? `/games/blackred/ticket/${item.reference}`
      : `/activity/payout/${item.reference}`;
  const itemType = transaction ? "transaction" : ticket ? "ticket" : "payout";

  return (
    <PlayerPage eyebrow={`${itemType[0].toUpperCase()}${itemType.slice(1)} help`} title="Get help without retyping details" description={`Reference ${item.reference} is already attached to this help route.`}>
      <InlineMessage tone="info" title={`Your ${itemType} is linked`}>
        Support can use the reference to review the durable record. Never include a verification code, NIN or full wallet number in a support message.
      </InlineMessage>
      <div><Button href={receiptHref} variant="secondary">Return to receipt</Button></div>
    </PlayerPage>
  );
}
