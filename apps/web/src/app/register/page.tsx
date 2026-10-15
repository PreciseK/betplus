import type { Metadata } from "next";
import { ConnectedRegistrationFlow } from "@/components/auth/RegistrationFlow/ConnectedRegistrationFlow";

export const metadata: Metadata = {
  title: "Create your account — Betplus",
  description: "Create your Betplus account and verify your phone, OPay wallet and identity.",
};

export default function RegisterPage() {
  return <ConnectedRegistrationFlow />;
}
