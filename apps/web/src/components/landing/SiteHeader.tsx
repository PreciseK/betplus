import { Logo } from "@/components/ui/Logo/Logo";

const NAV_LINKS = [
  { href: "#games", label: "Games" },
  { href: "#how-it-works", label: "How it works" },
  { href: "#access", label: "Ways to play" },
  { href: "#safe-play", label: "Safe play" },
  { href: "#help", label: "Help" },
];

export function SiteHeader() {
  return (
    <header className="site-header" data-header>
      <Logo size="large" />

      <button
        className="nav-toggle"
        type="button"
        aria-expanded="false"
        aria-controls="site-nav"
        data-nav-toggle
      >
        <span className="sr-only">Open menu</span>
        <span aria-hidden="true" />
        <span aria-hidden="true" />
      </button>

      <nav
        className="site-nav t-dropdown"
        id="site-nav"
        aria-label="Primary"
        data-origin="top-right"
        data-nav
      >
        {NAV_LINKS.map((link) => (
          <a key={link.href} href={link.href}>
            {link.label}
          </a>
        ))}
      </nav>

      <div className="header-actions">
        <a className="text-link" href="/login">Log in</a>
        <a className="button button-small button-light" href="/register">Create account</a>
      </div>
    </header>
  );
}
