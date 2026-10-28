import type { ReactNode } from "react";
import Link from "next/link";
import { SiteHeader } from "@/components/landing/SiteHeader";
import { SiteFooter } from "@/components/landing/SiteFooter";
import styles from "./LegalPage.module.css";

export type LegalSection = { id: string; title: string; content: ReactNode };

export function LegalPage({ title, intro, sections, kind }: {
  title: string;
  intro: string;
  sections: LegalSection[];
  kind: "privacy" | "terms";
}) {
  return (
    <div className={`landing-page ${styles.page}`} id="top">
      <a className="skip-link" href="#main">Skip to main content</a>
      <SiteHeader />
      <main id="main" className={styles.main}>
        <header className={styles.intro}>
          <Link href="/" className={styles.back}>← Back to Betplus</Link>
          <p className={styles.eyebrow}>BETPLUS / LEGAL</p>
          <h1>{title}</h1>
          <p className={styles.description}>{intro}</p>
          <p className={styles.updated}>Last updated <time dateTime="2026-10-28">28 October 2026</time> · Version 1.0</p>
          <nav className={styles.tabs} aria-label="Legal documents">
            <a href="/privacy-policy" aria-current={kind === "privacy" ? "page" : undefined}>Privacy Policy</a>
            <a href="/terms-and-conditions" aria-current={kind === "terms" ? "page" : undefined}>Terms &amp; Conditions</a>
          </nav>
        </header>
        <div className={styles.layout}>
          <nav className={styles.contents} aria-label="On this page">
            <h2>On this page</h2>
            <ol>{sections.map((section) => <li key={section.id}><a href={`#${section.id}`}>{section.title}</a></li>)}</ol>
          </nav>
          <article className={styles.article} aria-label={title}>
            {sections.map((section, index) => (
              <section id={section.id} key={section.id} className={styles.section} aria-labelledby={`${section.id}-title`}>
                <span className={styles.number} aria-hidden="true">{String(index + 1).padStart(2, "0")}</span>
                <div><h2 id={`${section.id}-title`}>{section.title}</h2>{section.content}</div>
              </section>
            ))}
            <a href="#top" className={styles.back}>Back to top ↑</a>
          </article>
        </div>
      </main>
      <SiteFooter homeLinks />
    </div>
  );
}
