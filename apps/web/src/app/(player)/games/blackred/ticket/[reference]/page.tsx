import { BLACKRED_TICKET_REFERENCES } from "@/mocks/blackred";
import { BlackRedTicketClient } from "./ticket-client";

export const dynamicParams = false;

export function generateStaticParams() {
  return BLACKRED_TICKET_REFERENCES.map((reference) => ({ reference }));
}

export default async function BlackRedTicketPage({
  params,
}: {
  params: Promise<{ reference: string }>;
}) {
  const { reference } = await params;
  return <BlackRedTicketClient reference={reference} />;
}
