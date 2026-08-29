import type { Metadata } from "next";
import { ConnectedRegistrationFlow } from "@/components/auth/RegistrationFlow/ConnectedRegistrationFlow";

export const metadata: Metadata = {
  title: "Create your account — Betplus",
  description: "Create and verify your Betplus account with fast OPay settlement.",
};

export default function RegisterPage() {
  return <ConnectedRegistrationFlow />;
}
