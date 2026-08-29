import type { Metadata } from "next";
import { ConnectedSignInFlow } from "@/components/auth/SignInFlow/ConnectedSignInFlow";

export const metadata: Metadata = {
  title: "Sign in — Buzzycash",
  description: "Sign in securely to your Buzzycash account.",
};

export default function LoginPage() {
  return <ConnectedSignInFlow />;
}
