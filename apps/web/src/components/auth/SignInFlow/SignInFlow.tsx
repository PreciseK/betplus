"use client";

import { useState, type FormEvent } from "react";
import { ErrorSummary } from "@/components/auth/ErrorSummary/ErrorSummary";
import { Button } from "@/components/ui/Button/Button";
import { OtpInput } from "@/components/ui/OtpInput/OtpInput";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { maskNigerianPhone, normalizeNigerianPhone } from "@/lib/phone";
import { mockSessionGateway, type SessionGateway } from "@/mocks/session";
import { TurnstileWidget } from "@/components/ui/TurnstileWidget";
import { AuthShell } from "../AuthShell/AuthShell";
import styles from "../AuthShell/AuthFlow.module.css";

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
  const [helpOpen, setHelpOpen] = useState(false);

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
    <AuthShell>
      <div className={styles.formWrap}>
        {step === "signed-in" ? (
          <section aria-labelledby="sign-in-title">

            <h1 id="sign-in-title" className={styles.stepH}>
              Welcome back{displayName ? `, ${displayName}` : ""}.
            </h1>
            <p className={styles.stepSub}>You’re signed in.</p>
            <InlineMessage tone="success" title="Sign-in confirmed">
              Continue to your games, wallet and account.
            </InlineMessage>
            <div style={{ marginTop: "24px" }}>
              <Button href="/games">Continue to Games</Button>
            </div>
          </section>
        ) : step === "session-ended" ? (
          <section aria-labelledby="sign-in-title">

            <h1 id="sign-in-title" className={styles.stepH}>
              Your session has ended.
            </h1>
            <p className={styles.stepSub}>Sign in again to continue.</p>
            <InlineMessage tone="warning" title="Sign in again to continue">
              Check your activity before repeating an unfinished transaction.
            </InlineMessage>
            <div style={{ marginTop: "24px" }}>
              <Button onClick={reset}>Sign in again</Button>
            </div>
          </section>
        ) : (
          <section aria-labelledby="sign-in-title">

            <h1 id="sign-in-title" className={styles.stepH}>
              {step === "phone" ? "Sign in to Betplus" : "Enter your sign-in code"}
            </h1>
            <p className={styles.stepSub}>
              {step === "phone"
                ? "We’ll send a sign-in code to your registered phone number."
                : `We sent a six-digit code to ${maskNigerianPhone(phoneE164)}. It expires after five minutes.`}
            </p>


            {providerError && (
              <div style={{ marginBottom: "20px" }}>
                <InlineMessage tone="error" title="Sign-in is delayed">{providerError}</InlineMessage>
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
                      aria-invalid={Boolean(phoneError)}
                      aria-describedby="sign-in-phone-hint"
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
                  <div id="sign-in-phone-hint" className={`${styles.fieldHint} ${phoneError ? styles.error : ""}`}>
                    {phoneError || "Enter the 11-digit phone number registered to your account."}
                  </div>
                </div>

                <button type="button" className={styles.helpToggle} aria-expanded={helpOpen} aria-controls="sign-in-help" onClick={() => setHelpOpen(!helpOpen)}>Need help signing in?</button>
                {helpOpen && (
                  <div id="sign-in-help" className={styles.helpText}>
                    <p>Use the phone number registered to your account. If your code has expired, choose Change number and request another code.</p>
                    <p>You need access to this phone to receive the sign-in code.</p>
                  </div>
                )}

                <TurnstileWidget />

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

            <p className={styles.footerLink}>New to Betplus? <a href="/register">Create account</a></p>
            <p className={styles.finePrint}>18+ only. Set limits and play responsibly.</p>

            {process.env.NODE_ENV === "development" && step === "phone" && (
              <details className={styles.demoAccountCard}>
                <summary>Development tools</summary>
                <div className={styles.demoAccountHeader}>
                  Development account

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
                  <span>{pending ? "Signing in…" : "Use test account"}</span>
                </button>
              </details>
            )}

          </section>
        )}
      </div>

    </AuthShell>
  );
}
