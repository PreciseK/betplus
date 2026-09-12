"use client";

import { useState, useEffect, type FormEvent } from "react";
import { ErrorSummary } from "@/components/auth/ErrorSummary/ErrorSummary";
import { Button } from "@/components/ui/Button/Button";
import { OtpInput } from "@/components/ui/OtpInput/OtpInput";
import { TextField } from "@/components/ui/TextField/TextField";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { maskNigerianPhone, normalizeNigerianPhone } from "@/lib/phone";
import { mockSessionGateway, type SessionGateway } from "@/mocks/session";
import styles from "./SignInFlow.module.css";

type SignInStep = "phone" | "code" | "signed-in" | "session-ended";

const PHONE_ERROR = "Enter an 11-digit Nigerian phone number, for example 0801 234 5678.";

interface SignInFlowProps {
  gateway?: SessionGateway;
  initialState?: "ready" | "session-ended";
}

export function SignInFlow({
  gateway = mockSessionGateway,
  initialState = "ready",
}: SignInFlowProps) {
  const [step, setStep] = useState<SignInStep>(initialState === "session-ended" ? "session-ended" : "phone");
  const [phone, setPhone] = useState("");
  const [phoneE164, setPhoneE164] = useState("");
  const [phoneError, setPhoneError] = useState<string>();
  const [code, setCode] = useState("");
  const [codeError, setCodeError] = useState<string>();
  const [challengeId, setChallengeId] = useState("");
  const [pending, setPending] = useState(false);
  const [providerError, setProviderError] = useState<string>();
  const [displayName, setDisplayName] = useState("");
  const [rememberMe, setRememberMe] = useState(true);
  const [savedPhone, setSavedPhone] = useState<string | null>(null);

  // Forgot PIN Modal state
  const [forgotOpen, setForgotOpen] = useState(false);
  const [forgotPhone, setForgotPhone] = useState("");
  const [forgotStep, setForgotStep] = useState<"phone" | "reset">("phone");
  const [forgotCode, setForgotCode] = useState("");
  const [forgotNewPin, setForgotNewPin] = useState("");
  const [forgotSuccess, setForgotSuccess] = useState(false);

  useEffect(() => {
    try {
      const stored = window.sessionStorage.getItem("bzc_remember_phone");
      if (stored) {
        setSavedPhone(stored);
      }
    } catch {
      // Ignore sessionStorage restrictions
    }
  }, []);

  const validatePhone = () => {
    const normalized = normalizeNigerianPhone(phone);
    setPhoneError(normalized ? undefined : PHONE_ERROR);
    return normalized;
  };

  const requestCode = async (event: FormEvent) => {
    event.preventDefault();
    const normalized = validatePhone();
    if (!normalized) return;
    setPending(true);
    setProviderError(undefined);
    try {
      const response = await gateway.requestSignInCode(normalized);
      setPhoneE164(normalized);
      setChallengeId(response.challengeId);
      setStep("code");
    } catch {
      setProviderError("We couldn’t send a sign-in code. Check your connection and try again.");
    } finally {
      setPending(false);
    }
  };

  const handleQuickDemoSignIn = async () => {
    const demoPhone = "+2348000000000";
    setPhone("08000000000");
    setPending(true);
    setProviderError(undefined);
    setCodeError(undefined);
    try {
      const response = await gateway.requestSignInCode(demoPhone);
      setPhoneE164(demoPhone);
      setChallengeId(response.challengeId);
      const verifyRes = await gateway.verifySignInCode(response.challengeId, "123456");
      if (typeof document !== "undefined") {
        document.cookie = "betplus_signed_in=1; path=/; max-age=2592000; SameSite=Lax";
      }
      setDisplayName(verifyRes.player.displayName || "Demo Player");
      setStep("signed-in");
    } catch {
      // Fallback: populate the form so user can submit with OTP 123456
      setPhone("08000000000");
      setPhoneE164(demoPhone);
      setChallengeId(demoPhone);
      setCode("123456");
      setStep("code");
    } finally {
      setPending(false);
    }
  };

  const verifyCode = async (event: FormEvent) => {
    event.preventDefault();
    if (code.length !== 6) {
      setCodeError("Enter the six-digit code sent to your phone.");
      return;
    }
    setPending(true);
    setCodeError(undefined);
    setProviderError(undefined);
    try {
      const response = await gateway.verifySignInCode(challengeId, code);
      if (typeof document !== "undefined") {
        document.cookie = "betplus_signed_in=1; path=/; max-age=2592000; SameSite=Lax";
      }
      setDisplayName(response.player.displayName);
      setStep("signed-in");
    } catch {
      setCodeError("That code is incorrect or has expired. Request a new code and try again.");
    } finally {
      setPending(false);
    }
  };

  const reset = () => {
    setStep("phone");
    setCode("");
    setCodeError(undefined);
    setProviderError(undefined);
  };

  const errors = step === "phone"
    ? (phoneError ? [{ fieldId: "sign-in-phone", message: phoneError }] : [])
    : (codeError ? [{ fieldId: "sign-in-code", message: codeError }] : []);

  return (
    <div className={styles.shell}>
      {/* LEFT BRAND PANEL */}
      <aside className={styles.brandPanel}>
        <div className={styles.brandPanelInner}>
          <a href="/" className={styles.brandLogo} aria-label="Betplus home">
            <img src="/assets/betplus-logo-white.png" alt="Betplus" className={styles.brandLogoImg} />
          </a>

          <div className={styles.brandBadge}>
            <span className={styles.dot}></span>
            <span>Two games · One wallet</span>
          </div>

          <h2 className={styles.brandHeadline}>
            Instant play.<br />
            <em>Direct payout.</em>
          </h2>

          <p className={styles.brandSub}>
            Sign in to access BlackRed and Heritage. One account, shared balances, and verified OPay transactions across Nigeria.
          </p>
        </div>

        <div className={styles.brandTrust}>
          <div className={styles.brandTrustItem}>
            <div className={styles.label}>Regulated</div>
            <div className={styles.val}>NLRC <span className={styles.brandAccent}>Licensed</span></div>
          </div>
          <div className={styles.brandTrustItem}>
            <div className={styles.label}>Settlement</div>
            <div className={styles.val}>OPay <span className={styles.brandAccent}>Direct</span></div>
          </div>
          <div className={styles.brandTrustItem}>
            <div className={styles.label}>Min. Stake</div>
            <div className={styles.val}>₦100 <span className={styles.brandAccent}>Fast</span></div>
          </div>
        </div>
      </aside>

      {/* RIGHT FORM PANEL */}
      <main className={styles.formPanel}>
        <div className={styles.formWrap}>
          {step === "signed-in" ? (
            <section aria-labelledby="sign-in-title">
              <div className={styles.topLabel}>Sign In Complete</div>
              <h1 id="sign-in-title" className={styles.stepH}>
                Welcome back{displayName ? `, ${displayName}` : ""}.
              </h1>
              <p className={styles.stepSub}>Your verified player session is active and ready.</p>
              <InlineMessage tone="success" title="Sign-in confirmed">
                Your secure web session is ready. Betplus never stores sign-in tokens in browser storage.
              </InlineMessage>
              <div style={{ marginTop: "24px" }}>
                <Button href="/games">Continue to Games</Button>
              </div>
            </section>
          ) : step === "session-ended" ? (
            <section aria-labelledby="sign-in-title">
              <div className={styles.topLabel}>Session Notice</div>
              <h1 id="sign-in-title" className={styles.stepH}>
                Your session has ended.
              </h1>
              <p className={styles.stepSub}>We ended this session to protect your account and balance.</p>
              <InlineMessage tone="warning" title="Sign in again to continue">
                We ended this session to protect your account. Any unfinished action was not submitted.
              </InlineMessage>
              <div style={{ marginTop: "24px" }}>
                <Button onClick={reset}>Sign in again</Button>
              </div>
            </section>
          ) : (
            <section aria-labelledby="sign-in-title">
              <div className={styles.topLabel}>Sign In</div>
              <h1 id="sign-in-title" className={styles.stepH}>
                {step === "phone" ? "Sign in to Betplus" : "Enter your sign-in code"}
              </h1>
              <p className={styles.stepSub}>
                {step === "phone"
                  ? "Enter your phone number to sign in and play."
                  : `We sent a six-digit code to ${maskNigerianPhone(phoneE164)}. It expires after five minutes.`}
              </p>

              {savedPhone && step === "phone" && (
                <div className={styles.welcomeBack}>
                  <div className={styles.welcomeBackIcon}>
                    <svg viewBox="0 0 24 24" fill="none">
                      <path d="M5 12l5 5L20 7" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  </div>
                  <div className={styles.welcomeBackText}>
                    Last session on this device: <strong>{maskNigerianPhone(savedPhone)}</strong>
                  </div>
                </div>
              )}

              {providerError && (
                <div style={{ marginBottom: "20px" }}>
                  <InlineMessage tone="error" title="Sign-in is delayed">{providerError}</InlineMessage>
                </div>
              )}

              {step === "phone" && (
                <div className={styles.demoAccountCard}>
                  <div className={styles.demoAccountHeader}>
                    <span className={styles.demoBadge}>⚡ Demo Account</span>
                    <span className={styles.demoBalance}>₦1,000,000.00</span>
                  </div>
                  <p className={styles.demoAccountDesc}>
                    Phone: <strong>0800 000 0000</strong> · OTP: <strong>123456</strong>
                  </p>
                  <button
                    type="button"
                    className={styles.demoSignBtn}
                    onClick={handleQuickDemoSignIn}
                    disabled={pending}
                  >
                    <span>{pending ? "Signing in to Demo…" : "🚀 1-Click Sign In (₦1,000,000)"}</span>
                  </button>
                </div>
              )}

              {step === "phone" ? (
                <form noValidate onSubmit={requestCode}>
                  <ErrorSummary errors={errors} />

                  <div className={styles.field}>
                    <div className={styles.fieldLabelRow}>
                      <label className={styles.fieldLabel} htmlFor="sign-in-phone">
                        Nigerian phone number
                      </label>
                    </div>

                    <div className={`${styles.phoneInput} ${phoneError ? styles.inputError : ""}`}>
                      <span className={styles.phonePrefix}>+234</span>
                      <input
                        id="sign-in-phone"
                        type="tel"
                        inputMode="tel"
                        autoComplete="tel"
                        placeholder="0801 234 5678"
                        className={styles.phoneField}
                        value={phone}
                        onChange={(e) => setPhone(e.target.value)}
                        onBlur={validatePhone}
                        required
                      />
                    </div>
                    <div className={`${styles.fieldHint} ${phoneError ? styles.error : ""}`}>
                      {phoneError || "Enter the 11-digit phone number registered to your account."}
                    </div>
                  </div>

                  <div className={styles.rememberRow}>
                    <label className={`${styles.check} ${rememberMe ? styles.checked : ""}`}>
                      <input
                        type="checkbox"
                        checked={rememberMe}
                        onChange={(e) => setRememberMe(e.target.checked)}
                      />
                      <div className={styles.checkBox}>
                        <svg viewBox="0 0 12 12" fill="none">
                          <path d="M2.5 6l2.5 2.5L9.5 3.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                      </div>
                      <span className={styles.checkText}>Remember me on this device</span>
                    </label>

                    <button
                      type="button"
                      className={styles.fieldLabelLink}
                      onClick={() => setForgotOpen(true)}
                    >
                      Need help?
                    </button>
                  </div>

                  <button
                    type="submit"
                    className={styles.btnPrimary}
                    disabled={pending}
                  >
                    <span>{pending ? "Sending code…" : "Send sign-in code"}</span>
                    {!pending && (
                      <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M1 8h13m0 0L8 2m6 6l-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                      </svg>
                    )}
                  </button>
                </form>
              ) : (
                <form noValidate onSubmit={verifyCode}>
                  <ErrorSummary errors={errors} />

                  <div className={styles.field}>
                    <OtpInput
                      id="sign-in-code"
                      value={code}
                      onValueChange={setCode}
                      errorText={codeError}
                      helperText="You can paste the full code from your SMS."
                    />
                  </div>

                  <div style={{ display: "flex", gap: "12px", marginTop: "20px" }}>
                    <button
                      type="button"
                      className={styles.btnSecondary}
                      style={{ flex: "1" }}
                      onClick={reset}
                    >
                      Change number
                    </button>
                    <button
                      type="submit"
                      className={styles.btnPrimary}
                      style={{ flex: "2" }}
                      disabled={pending}
                    >
                      <span>{pending ? "Signing in…" : "Sign in"}</span>
                    </button>
                  </div>
                </form>
              )}

              {/* DIVIDER & REGISTRATION CTA */}
              <div className={styles.divider}>
                <div className={styles.dividerLine}></div>
                <div className={styles.dividerText}>New to Buzzycash?</div>
                <div className={styles.dividerLine}></div>
              </div>

              <a href="/register" className={styles.signupCta}>
                <div className={styles.signupCtaText}>
                  <div className={styles.signupCtaLabel}>Create an account</div>
                  <div className={styles.signupCtaMain}>Sign up in 2 minutes</div>
                </div>
                <div className={styles.signupCtaArrow}>
                  <svg viewBox="0 0 16 16" fill="none">
                    <path d="M1 8h13m0 0L8 2m6 6l-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </div>
              </a>

              <p className={styles.finePrint}>
                Licensed by NLRC · 18+ only.<br />
                Gaming can be addictive. <a href="/safe-play">Play responsibly</a>.
              </p>
            </section>
          )}
        </div>
      </main>

      {/* FORGOT PIN / HELP MODAL */}
      {forgotOpen && (
        <div className={styles.modalOverlay} role="dialog" aria-modal="true">
          <div className={styles.modalCard}>
            <button
              type="button"
              className={styles.modalClose}
              onClick={() => {
                setForgotOpen(false);
                setForgotStep("phone");
                setForgotSuccess(false);
              }}
              aria-label="Close modal"
            >
              ✕
            </button>

            <div className={styles.modalIcon}>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <rect x="6" y="2.5" width="12" height="19" rx="2.5" stroke="currentColor" strokeWidth="1.8" />
                <path d="M10 18h4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
              </svg>
            </div>

            <h2 className={styles.modalTitle}>Account <em>Recovery</em></h2>

            {forgotSuccess ? (
              <div>
                <p className={styles.modalSub}>
                  Recovery link sent! If this number is associated with an account, check your messages for next steps.
                </p>
                <button
                  type="button"
                  className={styles.btnPrimary}
                  onClick={() => setForgotOpen(false)}
                >
                  Back to Sign In
                </button>
              </div>
            ) : forgotStep === "phone" ? (
              <form
                className={styles.modalForm}
                onSubmit={(e) => {
                  e.preventDefault();
                  if (normalizeNigerianPhone(forgotPhone)) {
                    setForgotSuccess(true);
                  }
                }}
              >
                <p className={styles.modalSub}>
                  Enter the phone number on your account to request a secure recovery code.
                </p>

                <div className={styles.phoneInput}>
                  <span className={styles.phonePrefix}>+234</span>
                  <input
                    type="tel"
                    className={styles.phoneField}
                    placeholder="0801 234 5678"
                    value={forgotPhone}
                    onChange={(e) => setForgotPhone(e.target.value)}
                    required
                  />
                </div>

                <button type="submit" className={styles.btnPrimary}>
                  Send recovery instructions
                </button>
              </form>
            ) : null}
          </div>
        </div>
      )}
    </div>
  );
}
