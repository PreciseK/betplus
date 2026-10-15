"use client";

import { useEffect, useRef, useState } from "react";
import { Logo } from "@/components/ui/Logo/Logo";

const NAV_LINKS = [
  { href: "#games", label: "Games" },
  { href: "#how-it-works", label: "How it works" },
  { href: "#access", label: "Ways to play" },
  { href: "#safe-play", label: "Play responsibly" },
];

export function SiteHeader() {
  const [menuOpen, setMenuOpen] = useState(false);
  const headerRef = useRef<HTMLElement>(null);
  const toggleRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape" && menuOpen) {
        setMenuOpen(false);
        toggleRef.current?.focus();
      }
    };
    const onPointerDown = (event: PointerEvent) => {
      if (event.target instanceof Node && !headerRef.current?.contains(event.target)) setMenuOpen(false);
    };
    const desktop = window.matchMedia("(min-width: 1001px)");
    const onDesktop = () => { if (desktop.matches) setMenuOpen(false); };
    document.addEventListener("keydown", onKeyDown);
    document.addEventListener("pointerdown", onPointerDown);
    desktop.addEventListener("change", onDesktop);
    return () => {
      document.removeEventListener("keydown", onKeyDown);
      document.removeEventListener("pointerdown", onPointerDown);
      desktop.removeEventListener("change", onDesktop);
    };
  }, [menuOpen]);

  return (
    <header className="site-header" ref={headerRef}>
      <Logo href="/" height={38} />
      <nav className="site-nav" id="site-nav" aria-label="Main navigation" data-open={menuOpen} onClick={() => setMenuOpen(false)}>
        {NAV_LINKS.map((link) => <a key={link.href} href={link.href}>{link.label}</a>)}
        <a className="mobile-sign-in" href="/login">Sign in</a>
      </nav>
      <div className="header-actions">
        <a className="header-sign-in" href="/login">Sign in</a>
        <a className="button button-small button-primary" href="/register">Create account</a>
        <button ref={toggleRef} className="nav-toggle" type="button" aria-expanded={menuOpen} aria-controls="site-nav" aria-label={menuOpen ? "Close menu" : "Open menu"} onClick={() => setMenuOpen((open) => !open)}>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" aria-hidden="true"><path d={menuOpen ? "m6 6 12 12M6 18 18 6" : "M4 8h16M4 16h16"} /></svg>
        </button>
      </div>
    </header>
  );
}
