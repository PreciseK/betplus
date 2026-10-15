"use client";

import { useState, type FormEvent } from "react";
import Image from "next/image";
import type { MobileNetwork } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { OtpInput } from "@/components/ui/OtpInput/OtpInput";
import { TextField } from "@/components/ui/TextField/TextField";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { ErrorSummary } from "@/components/auth/ErrorSummary/ErrorSummary";
import { maskNigerianPhone, normalizeNigerianPhone } from "@/lib/phone";
import { formatKobo } from "@/lib/money";
import { parseNigerianDateInput } from "@/lib/date";
import { mockRegistrationGateway, type RegistrationGateway } from "@/mocks/registration";
import { TurnstileWidget } from "@/components/ui/TurnstileWidget";
import { AuthShell } from "../AuthShell/AuthShell";
import styles from "../AuthShell/AuthFlow.module.css";

type Step = "phone" | "otp" | "opay" | "identity" | "opay-missing" | "opay-confirmed" | "kyc" | "tier-one" | "underage";

const PHONE_ERROR = "Enter an 11-digit Nigerian phone number, for example 0801 234 5678.";

export function RegistrationFlow({ gateway = mockRegistrationGateway }: { gateway?: RegistrationGateway }) {
  const [step, setStep] = useState<Step>("phone");
  const [phone, setPhone] = useState("");
  const [phoneError, setPhoneError] = useState<string>();
  const [network, setNetwork] = useState<MobileNetwork>();
  const [networkError, setNetworkError] = useState<string>();
  const [otp, setOtp] = useState("");
  const [otpError, setOtpError] = useState<string>();
  const [challengeId, setChallengeId] = useState("");
  const [phoneE164, setPhoneE164] = useState("");
  const [pending, setPending] = useState(false);
  const [providerError, setProviderError] = useState<string>();
  const [notice, setNotice] = useState<string>();
  const [resendCount, setResendCount] = useState(0);
  const [registeredName, setRegisteredName] = useState("");
  const [dateOfBirth, setDateOfBirth] = useState("");
  const [nin, setNin] = useState("");
  const [dateError, setDateError] = useState<string>();
  const [ninError, setNinError] = useState<string>();
  const [monthlyDepositLimitKobo, setMonthlyDepositLimitKobo] = useState<number>();

  const validatePhone = () => {
    const normalized = normalizeNigerianPhone(phone);
    setPhoneError(normalized ? undefined : PHONE_ERROR);
    return normalized;
  };

  const requestOtp = async (event: FormEvent) => {
    event.preventDefault();
    const normalized = validatePhone();
    setNetworkError(network ? undefined : "Select your mobile network.");
    if (!normalized || !network) return;
    setPending(true);
    setProviderError(undefined);
    setNotice(undefined);
    try {
      const response = await gateway.requestOtp(normalized, network);
      setChallengeId(response.challengeId);
      setPhoneE164(normalized);
      setStep("otp");
    } catch {
      setProviderError("We couldn’t send a verification code. Your number is saved—please try again.");
    } finally {
      setPending(false);
    }
  };

  const verifyOtp = async (event: FormEvent) => {
    event.preventDefault();
    if (otp.length !== 6) {
      setOtpError("Enter the six-digit code sent to your phone.");
      return;
    }
    setPending(true);
    setOtpError(undefined);
    setProviderError(undefined);
    setNotice(undefined);
    try {
      await gateway.verifyOtp(challengeId, otp);
      setStep("opay");
    } catch {
      setOtpError("That code is incorrect or has expired. Request a new code and try again.");
    } finally {
      setPending(false);
    }
  };

  const resendOtp = async () => {
    if (resendCount >= 3) {
      setProviderError("You’ve requested the maximum number of codes for now. Try again later or contact support.");
      return;
    }
    setPending(true);
    setProviderError(undefined);
    setNotice(undefined);
    try {
      const response = await gateway.requestOtp(phoneE164, network);
      setChallengeId(response.challengeId);
      setOtp("");
      setOtpError(undefined);
      setResendCount((count) => count + 1);
      setNotice("A new code was sent. The previous code no longer works.");
    } catch {
      setProviderError("We couldn’t send a new code. Please wait and try again.");
    } finally {
      setPending(false);
    }
  };

  const validateOpay = async () => {
    setPending(true);
    setProviderError(undefined);
    setNotice(undefined);
    try {
      const response = await gateway.validateOpayWallet(phoneE164);
      if (response.status === "not-found") {
        setStep("opay-missing");
        return;
      }
      setRegisteredName(response.registeredName);
      setStep("identity");
    } catch {
      setProviderError("OPay isn’t available right now. Your verified phone number is saved—please try again.");
    } finally {
      setPending(false);
    }
  };

  const verifyIdentity = async (event: FormEvent) => {
    event.preventDefault();
    const parsedDate = parseNigerianDateInput(dateOfBirth);
    const validDate = Boolean(parsedDate);
    const validNin = /^\d{11}$/.test(nin);
    setDateError(validDate ? undefined : "Enter your date of birth in DD/MM/YYYY format.");
    setNinError(validNin ? undefined : "Enter the 11 digits on your NIN.");
    if (!validDate || !validNin) return;
    setPending(true);
    setProviderError(undefined);
    try {
      const response = await gateway.verifyIdentity(parsedDate!, nin);
      setNin("");
      if (response.status === "underage") {
        setStep("underage");
        return;
      }
      setMonthlyDepositLimitKobo(response.monthlyDepositLimitKobo);
      setStep("tier-one");
    } catch {
      setProviderError("We couldn’t verify your identity right now. Your verified phone and OPay name are saved—please try again.");
    } finally {
      setPending(false);
    }
  };

  const currentStepNum = step === "phone" ? 1 : step === "otp" || step === "opay" || step === "opay-missing" || step === "identity" || step === "opay-confirmed" ? 2 : 3;

  return (
    <AuthShell>
      <div className={styles.formWrap}>
        {/* Top progress indicators */}
        <div className={styles.progressWrap}>
          <div className={styles.progressLabel}>
            Step {currentStepNum} of 3 · {currentStepNum === 1 ? "Phone number" : currentStepNum === 2 ? "Phone & wallet" : "Identity"}
          </div>
          <div className={styles.progressBar} aria-hidden="true">
            <div className={`${styles.progressStep} ${currentStepNum >= 1 ? styles.active : ""}`} />
            <div className={`${styles.progressStep} ${currentStepNum >= 2 ? styles.active : ""}`} />
            <div className={`${styles.progressStep} ${currentStepNum >= 3 ? styles.active : ""}`} />
          </div>
        </div>

        {step === "opay" ? (
          <section aria-labelledby="registration-title">
            <h1 id="registration-title" className={styles.stepH}>Your phone is verified.</h1>
            <InlineMessage tone="success" title="Phone number confirmed">
              We’ll use {maskNigerianPhone(phoneE164)} to secure your Betplus account.
            </InlineMessage>
            {providerError && <InlineMessage tone="error" title="OPay validation is delayed">{providerError}</InlineMessage>}
            <p className={styles.supporting}>
              Next, we’ll confirm the name on your OPay wallet. OPay validation confirms your wallet and name; it is not identity verification.
            </p>
            <Button status={pending ? "loading" : "idle"} statusLabel="Checking OPay wallet…" onClick={validateOpay}>
              Confirm OPay wallet
            </Button>
          </section>
        ) : step === "opay-missing" ? (
          <section aria-labelledby="registration-title">
            <h1 id="registration-title" className={styles.stepH}>An OPay wallet is required.</h1>
            <InlineMessage tone="warning" title="No OPay wallet found">
              We couldn’t find an OPay wallet for {maskNigerianPhone(phoneE164)}. Your phone verification is saved.
            </InlineMessage>
            <p className={styles.supporting}>Open an OPay wallet using this phone number, then return to continue registration.</p>
            <div className={styles.actions}>
              <Button href="https://www.opayweb.com/" variant="secondary">Open OPay</Button>
              <Button onClick={validateOpay}>Check again</Button>
            </div>
          </section>
        ) : step === "identity" ? (
          <section aria-labelledby="registration-title">
            <h1 id="registration-title" className={styles.stepH}>Is this the name on your OPay wallet?</h1>
            <div className={styles.identityCard}>
              <div className={styles.identityIcon}>
                <svg viewBox="0 0 24 24" fill="none">
                  <path d="M5 12l5 5L20 7" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              </div>
              <div className={styles.identityInfo}>
                <div className={styles.identityLabel}>Registered name</div>
                <div className={styles.identityName}>{registeredName}</div>
                <small className={styles.identityNote}>Returned by OPay · This name cannot be edited in Betplus</small>
              </div>
            </div>
            <p className={styles.supporting}>We retain this name for payout matching. Confirming it does not complete identity verification.</p>
            <div className={styles.actions}>
              <Button variant="secondary" onClick={() => setStep("opay-missing")}>This isn’t my name</Button>
              <Button onClick={() => setStep("opay-confirmed")}>Yes, confirm name</Button>
            </div>
          </section>
        ) : step === "opay-confirmed" ? (
          <section aria-labelledby="registration-title">
            <h1 id="registration-title" className={styles.stepH}>Your OPay name is confirmed.</h1>
            <InlineMessage tone="success" title="Wallet name saved">
              {registeredName} will be used for payout matching.
            </InlineMessage>
            <p className={styles.supporting}>Next, we’ll verify your age and identity before play or deposits are available.</p>
            <Button onClick={() => setStep("kyc")}>Continue to identity verification</Button>
          </section>
        ) : step === "underage" ? (
          <section aria-labelledby="registration-title">
            <h1 id="registration-title" className={styles.stepH}>You can’t use Betplus.</h1>
            <InlineMessage tone="error" title="Betplus is for adults aged 18 and over">
              Play and deposits are unavailable. If money was already received, contact support from your account for help with a refund.
            </InlineMessage>
            <div style={{ marginTop: "24px" }}>
              <Button href="/">Back to home</Button>
            </div>
          </section>
        ) : step === "tier-one" ? (
          <section aria-labelledby="registration-title">
            <div className={styles.successWrap}>
              <div className={styles.successCircle}>
                <svg viewBox="0 0 24 24" fill="none">
                  <path d="M5 12l5 5L20 7" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              </div>
              <h1 id="registration-title" className={styles.stepH}>Your identity is verified.</h1>
              <InlineMessage tone="success" title="Tier 1 account active">
                Your monthly deposit limit is {monthlyDepositLimitKobo === undefined ? "set by your account rules" : formatKobo(monthlyDepositLimitKobo)}.
              </InlineMessage>
              <p className={styles.supporting}>
                We still need to complete the player-protection exclusion check before a new game can start.
              </p>
              <div style={{ marginTop: "24px" }}>
                <Button href="/games">Enter Games</Button>
              </div>
            </div>
          </section>
        ) : step === "kyc" ? (
          <section aria-labelledby="registration-title">
            <h1 id="registration-title" className={styles.stepH}>Verify your age and identity</h1>
            <p className={styles.stepSub}>
              We use your NIN to verify age and identity and to check player-protection exclusions. It is stored separately and never shown in full.
            </p>
            {providerError && <InlineMessage tone="error" title="Verification is delayed">{providerError}</InlineMessage>}
            <form noValidate onSubmit={verifyIdentity}>
              <ErrorSummary
                errors={[
                  dateError && { fieldId: "date-of-birth", message: dateError },
                  ninError && { fieldId: "nin", message: ninError },
                ].filter(Boolean) as Array<{ fieldId: string; message: string }>}
              />
              <div style={{ marginBottom: "16px" }}>
                <TextField
                  id="date-of-birth"
                  label="Date of birth"
                  type="text"
                  inputMode="numeric"
                  autoComplete="bday"
                  placeholder="DD/MM/YYYY"
                  maxLength={10}
                  value={dateOfBirth}
                  errorText={dateError}
                  onBlur={() => setDateError(parseNigerianDateInput(dateOfBirth) ? undefined : "Enter your date of birth in DD/MM/YYYY format.")}
                  onChange={(event) => setDateOfBirth(event.target.value)}
                  required
                />
              </div>
              <div style={{ marginBottom: "20px" }}>
                <TextField
                  id="nin"
                  label="National Identification Number (NIN)"
                  type="text"
                  inputMode="numeric"
                  autoComplete="off"
                  placeholder="11 digits"
                  maxLength={11}
                  value={nin}
                  errorText={ninError}
                  helperText="Your NIN is sent only for identity verification and is never displayed in full."
                  onBlur={() => setNinError(/^\d{11}$/.test(nin) ? undefined : "Enter the 11 digits on your NIN.")}
                  onChange={(event) => setNin(event.target.value.replace(/\D/g, "").slice(0, 11))}
                  required
                />
              </div>
              <Button type="submit" status={pending ? "loading" : "idle"} statusLabel="Verifying identity…">
                Verify identity
              </Button>
            </form>
          </section>
        ) : (
          <section aria-labelledby="registration-title">
            {step === "otp" && (
              <button
                type="button"
                className={styles.backBtn}
                onClick={() => {
                  setStep("phone");
                  setOtp("");
                  setOtpError(undefined);
                }}
              >
                <svg viewBox="0 0 16 16" fill="none">
                  <path d="M10 4L6 8l4 4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                Back
              </button>
            )}

            <h1 id="registration-title" className={styles.stepH}>
              {step === "phone" ? (
                "Create your account"
              ) : (
                "Enter your verification code"
              )}
            </h1>
            <p className={styles.stepSub}>
              {step === "phone"
                ? "Use the Nigerian phone number linked to your OPay wallet."
                : `We sent a six-digit code to ${maskNigerianPhone(phoneE164)}. It expires after five minutes.`}
            </p>

            {providerError && <InlineMessage tone="error" title="We couldn’t complete that step">{providerError}</InlineMessage>}
            {notice && <InlineMessage tone="info" title="New code sent">{notice}</InlineMessage>}

            {step === "phone" ? (
              <form noValidate onSubmit={requestOtp}>
                <ErrorSummary errors={[
                  ...(phoneError ? [{ fieldId: "phone", message: phoneError }] : []),
                  ...(networkError ? [{ fieldId: "network-mtn", message: networkError }] : []),
                ]} />
                <div className={styles.field}>
                  <label className={styles.fieldLabel} htmlFor="phone">
                    Nigerian phone number
                  </label>
                  <div className={`${styles.phoneInput} ${phoneError ? styles.inputError : ""}`}>
                    <span className={styles.phonePrefix}>+234</span>
                    <input
                      id="phone"
                      aria-invalid={Boolean(phoneError)}
                      aria-describedby="phone-hint"
                      type="tel"
                      inputMode="tel"
                      autoComplete="tel"
                      placeholder="0801 234 5678"
                      className={styles.phoneField}
                      value={phone}
                      onBlur={validatePhone}
                      onChange={(event) => setPhone(event.target.value)}
                      required
                    />
                  </div>
                  <div id="phone-hint" className={`${styles.fieldHint} ${phoneError ? styles.error : ""}`}>
                    {phoneError || "Enter your number with the leading 0 or +234."}
                  </div>
                </div>

                <fieldset className={styles.networkField} aria-describedby={networkError ? "network-error" : undefined} aria-invalid={Boolean(networkError)}>
                  <legend>Mobile network</legend>
                  <div className={styles.networkOptions}>
                    {([ ["mtn", "MTN"], ["glo", "Glo"], ["airtel", "Airtel"], ["t2", "T2"] ] as const).map(([value, label]) => (
                      <label key={value} htmlFor={`network-${value}`}>
                        <input id={`network-${value}`} type="radio" name="mobile-network" value={value} checked={network === value} onChange={() => { setNetwork(value); setNetworkError(undefined); }} required />
                        <span className={styles.networkLogo}><Image src={`/assets/providers/${value}.png`} alt="" width={36} height={36} unoptimized /></span>
                        <span>{label}</span>
                      </label>
                    ))}
                  </div>
                  {networkError && <p id="network-error" className={`${styles.fieldHint} ${styles.error}`}>{networkError}</p>}
                </fieldset>

                <TurnstileWidget />

                <Button type="submit" status={pending ? "loading" : "idle"} statusLabel="Sending code…">
                  Send verification code
                </Button>
                <p className={styles.privacy}>
                  We use this number for account security and transactional updates. Marketing consent is separate and optional.
                </p>
              </form>
            ) : (
              <form noValidate onSubmit={verifyOtp}>
                <ErrorSummary errors={otpError ? [{ fieldId: "otp", message: otpError }] : []} />
                <div style={{ marginBottom: "20px" }}>
                  <OtpInput
                    id="otp"
                    value={otp}
                    onValueChange={setOtp}
                    errorText={otpError}
                    helperText="You can paste the full code from your SMS."
                  />
                </div>
                <div className={styles.actions}>
                  <Button
                    variant="secondary"
                    type="button"
                    onClick={() => {
                      setStep("phone");
                      setOtp("");
                      setOtpError(undefined);
                    }}
                  >
                    Change number
                  </Button>
                  <Button type="submit" status={pending ? "loading" : "idle"} statusLabel="Checking code…">
                    Verify phone number
                  </Button>
                </div>
                <button className={styles.resend} type="button" disabled={pending} onClick={resendOtp}>
                  Request a new code
                </button>
              </form>
            )}
          </section>
        )}

        {/* Footer Link */}
        <p className={styles.footerLink}>
          Already have an account? <a href="/login">Sign in</a>
        </p>

        <p className={styles.terms}>
          You must be 18 or older to use Betplus. An OPay wallet and identity verification are required.
        </p>
      </div>
    </AuthShell>
  );
}
