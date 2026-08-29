import type { Metadata } from "next";
// Self-hosted via @fontsource rather than next/font/google: this environment's
// network cannot reach fonts.googleapis.com at build time (corporate TLS
// interception). Self-hosting also matches design.md §5.1 directly.
import "@fontsource/outfit/latin-600.css";
import "@fontsource/outfit/latin-700.css";
import "@fontsource/outfit/latin-800.css";
import "@fontsource/outfit/latin-ext-600.css";
import "@fontsource/outfit/latin-ext-700.css";
import "@fontsource/outfit/latin-ext-800.css";
import "@fontsource/sora/latin-400.css";
import "@fontsource/sora/latin-500.css";
import "@fontsource/sora/latin-600.css";
import "@fontsource/sora/latin-700.css";
import "@fontsource/sora/latin-ext-400.css";
import "@fontsource/sora/latin-ext-500.css";
import "@fontsource/sora/latin-ext-600.css";
import "@fontsource/sora/latin-ext-700.css";
import "@fontsource/poppins/latin-400.css";
import "@fontsource/poppins/latin-500.css";
import "@fontsource/poppins/latin-600.css";
import "@fontsource/poppins/latin-700.css";
import "@fontsource/poppins/latin-800.css";
import "@fontsource/poppins/latin-900.css";
import "./globals.css";

export const metadata: Metadata = {
  title: "Betplus — Two games. One wallet.",
  description:
    "Betplus is one account and one wallet for BlackRed and Heritage—built for clear play, transparent results, and OPay settlement in Nigeria.",
  icons: {
    icon: [
      { url: "/assets/betplus-favicon.png" },
      { url: "/favicon.ico" },
    ],
    apple: "/assets/betplus-favicon.png",
  },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en-NG">
      <body>{children}</body>
    </html>
  );
}
