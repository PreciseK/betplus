import type { Metadata } from "next";
import { OperatorSignIn } from "@/components/operations/OperatorSignIn/OperatorSignIn";
import { AuthShell } from "@/components/auth/AuthShell/AuthShell";
import styles from "./page.module.css";

export const metadata: Metadata = {
  title: "Operator sign in — Betplus back office",
  description: "Restricted sign-in for authorised Betplus operators.",
};

export default function BackOfficeSignInPage() {
  return (
    <>
      <a className="skip-link" href="#operator-sign-in">Skip to operator sign in</a>
      <AuthShell brandNote="Never share your password or authenticator codes." brandContent={<>
        <div className={styles.brandCopy}>
          <p className={styles.eyebrow}>BETPLUS · STAFF ACCESS</p>
          <h2>Clarity.<br />{" "}Control.<br />{" "}Accountability.</h2>
          <p className={styles.description}>Your workspace for Betplus operations.</p>
        </div>
        <dl className={styles.accessDetails}>
          <div><dt>Two-step sign in</dt><dd>Your password and authenticator app.</dd></div>
          <div><dt>Access by role</dt><dd>The tools and permissions assigned to you.</dd></div>
          <div><dt>Approved networks</dt><dd>Required for privileged roles.</dd></div>
        </dl>
      </>}>
        <div className={styles.form} id="operator-sign-in"><OperatorSignIn /></div>
      </AuthShell>
    </>
  );
}
