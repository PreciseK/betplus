import { Logo } from "@/components/ui/Logo/Logo";

const LINK_GROUPS = [
  ["Games", [["/games/blackred", "BlackRed"], ["/games/heritage", "Heritage"], ["/games/caged", "Caged"]]],
  ["Account", [["/login", "Sign in"], ["/register", "Create account"], ["/wallet", "Wallet"]]],
  ["Getting started", [["#how-it-works", "How it works"], ["#access", "Ways to play"], ["#safe-play", "Play responsibly"]]],
] as const;

export function SiteFooter() {
  return (
    <footer className="site-footer" id="help">
      <div className="footer-brand"><Logo href="#top" height={40} /><p>BlackRed, Heritage and Caged.</p></div>
      <nav className="footer-links" aria-label="Footer navigation">
        {LINK_GROUPS.map(([title, links]) => <div key={title}><h2>{title}</h2>{links.map(([href, label]) => <a href={href} key={href}>{label}</a>)}</div>)}
      </nav>
      <div className="footer-bottom"><p>© {new Date().getFullYear()} Betplus.</p><p>18+ only · Nigeria · Please play responsibly</p></div>
    </footer>
  );
}
