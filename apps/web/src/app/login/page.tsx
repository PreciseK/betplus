import type { Metadata } from "next";
import { ConnectedSignInFlow } from "@/components/auth/SignInFlow/ConnectedSignInFlow";

export const metadata: Metadata = {
  title: "Sign in — Betplus",
  description: "Sign in securely to your Betplus account.",
};

export default function LoginPage() {
  return <ConnectedSignInFlow />;
}
