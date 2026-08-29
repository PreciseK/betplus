import { Logo } from "@/components/ui/Logo/Logo";

const LINK_GROUPS = [
  ["Play", [["#games", "Games"], ["#how-it-works", "How it works"], ["#access", "Ways to play"]]],
  ["Support", [["/help", "Help centre"], ["/help/payments", "Payment help"], ["/help/contact", "Contact support"]]],
  ["Trust", [["/safe-play", "Safe play"], ["/rules", "Rules and odds"], ["/privacy", "Privacy"]]],
  ["Legal", [["/terms", "Terms"], ["/licensing", "Licensing"], ["/accessibility", "Accessibility"]]],
] as const;

export function SiteFooter() {
  return (
    <footer className="site-footer" id="help">
      <div className="footer-brand">
        <Logo footer />
        <p>One identity, one wallet, and a clear record across every game.</p>
      </div>
      <div className="footer-links">
        {LINK_GROUPS.map(([title, links]) => (
          <div key={title}>
            <h2>{title}</h2>
            {links.map(([href, label]) => <a href={href} key={href}>{label}</a>)}
          </div>
        ))}
      </div>
      <div className="footer-bottom">
        <p>© {new Date().getFullYear()} Betplus. All rights reserved.</p>
        <p>18+ only · Nigeria · Please play responsibly</p>
      </div>
    </footer>
  );
}
