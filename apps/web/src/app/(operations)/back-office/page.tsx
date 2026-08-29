import type { Metadata } from "next";
import { OperatorSignIn } from "@/components/operations/OperatorSignIn/OperatorSignIn";
import { Logo } from "@/components/ui/Logo/Logo";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "./page.module.css";

export const metadata: Metadata = {
  title: "Operator sign in — Betplus back office",
  description: "Restricted sign-in for authorised Betplus operators.",
};

export default function BackOfficeSignInPage() {
  return (
    <div className={styles.page}>
      <a className="skip-link" href="#operator-sign-in">Skip to operator sign in</a>
      <header className={styles.header}>
        <div className={styles.brand}>
          <Logo href="/" />
          <span>Back office</span>
        </div>
        <span className={styles.restricted}><Icon name="lock" /><span>Restricted operator surface</span></span>
      </header>
      <main className={styles.main} id="operator-sign-in">
        <OperatorSignIn />
        <aside className={styles.security} aria-labelledby="security-title">
          <p className={styles.securityLabel}>Access controls</p>
          <h2 id="security-title">Operator access is individually attributable</h2>
          <dl>
            <div><dt>MFA</dt><dd>Required for every account</dd></div>
            <div><dt>Network</dt><dd>Privileged roles require an approved IP</dd></div>
            <div><dt>Permissions</dt><dd>Least privilege by assigned role</dd></div>
            <div><dt>Audit</dt><dd>Every completed action records its operator</dd></div>
          </dl>
          <p>Never share credentials or authenticator codes. Betplus support will not ask for either.</p>
        </aside>
      </main>
    </div>
  );
}
