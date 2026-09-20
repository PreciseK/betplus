"use client";

import { useState, type FormEvent } from "react";
import { ErrorSummary } from "@/components/auth/ErrorSummary/ErrorSummary";
import { OPERATOR_ROLE_LABELS } from "@/components/operations/operations-navigation";
import { Button } from "@/components/ui/Button/Button";
import { OtpInput } from "@/components/ui/OtpInput/OtpInput";
import { TextField } from "@/components/ui/TextField/TextField";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import {
  OperatorSessionError,
  type OperatorSession,
  type OperatorSessionGateway,
} from "@/mocks/operator-session";
import { operatorSessionGateway } from "@/lib/operator-session-gateway";
import styles from "./OperatorSignIn.module.css";

type SignInStep = "credentials" | "mfa" | "authorized";

interface OperatorSignInProps {
  gateway?: OperatorSessionGateway;
}

const EMAIL_ERROR = "Enter your Betplus work email ending in @betplus.com.ng or @betplus.ng.";
const PASSWORD_ERROR = "Enter your operator password. It must be at least 8 characters.";

export function OperatorSignIn({ gateway = operatorSessionGateway }: OperatorSignInProps) {
  const [step, setStep] = useState<SignInStep>("credentials");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [emailError, setEmailError] = useState<string>();
  const [passwordError, setPasswordError] = useState<string>();
  const [code, setCode] = useState("");
  const [codeError, setCodeError] = useState<string>();
  const [challengeId, setChallengeId] = useState("");
  const [maskedEmail, setMaskedEmail] = useState("");
  const [providerError, setProviderError] = useState<string>();
  const [pending, setPending] = useState(false);
  const [session, setSession] = useState<OperatorSession>();

  const validateEmail = () => {
    const valid = /^[^@\s]+@(betplus\.com\.ng|betplus\.ng)$/i.test(email.trim());
    setEmailError(valid ? undefined : EMAIL_ERROR);
    return valid;
  };

  const validatePassword = () => {
    const valid = password.length >= 8;
    setPasswordError(valid ? undefined : PASSWORD_ERROR);
    return valid;
  };

  const beginMfa = async (event: FormEvent) => {
    event.preventDefault();
    const validEmail = validateEmail();
    const validPassword = validatePassword();
    if (!validEmail || !validPassword) return;
    setPending(true);
    setProviderError(undefined);
    try {
      const response = await gateway.beginMfa(email.trim(), password);
      setChallengeId(response.challengeId);
      setMaskedEmail(response.maskedEmail);
      setPassword("");
      setStep("mfa");
    } catch (error) {
      if (error instanceof OperatorSessionError && error.code === "IP_NOT_ALLOWED") {
        setProviderError("This network is not approved for operator access. Connect to the Betplus operations VPN and try again.");
      } else {
        setProviderError("We couldn’t verify those operator credentials. Check them and try again.");
      }
    } finally {
      setPending(false);
    }
  };

  const verifyMfa = async (event: FormEvent) => {
    event.preventDefault();
    if (code.length !== 6) {
      setCodeError("Enter the six-digit code from your authenticator app.");
      return;
    }
    setPending(true);
    setCodeError(undefined);
    setProviderError(undefined);
    try {
      const response = await gateway.verifyMfa(challengeId, code);
      setSession(response);
      setStep("authorized");
    } catch {
      setCodeError("That authenticator code is incorrect or has expired. Enter the current code and try again.");
    } finally {
      setPending(false);
    }
  };

  if (step === "authorized" && session) {
    return (
      <section className={styles.flow} aria-labelledby="operator-sign-in-title">
        <p className={styles.context}>Betplus back office</p>
        <h1 id="operator-sign-in-title">Access confirmed</h1>
        <InlineMessage tone="success" title="MFA verified">
          {session.operator.displayName} · {OPERATOR_ROLE_LABELS[session.operator.role]} · {session.approvedNetwork}
        </InlineMessage>
        <p className={styles.supporting}>Your permissions and every operator action are recorded against this session.</p>
        <Button href="/back-office/overview">Open operations overview</Button>
      </section>
    );
  }

  const errors = step === "credentials"
    ? [
        ...(emailError ? [{ fieldId: "operator-email", message: emailError }] : []),
        ...(passwordError ? [{ fieldId: "operator-password", message: passwordError }] : []),
      ]
    : (codeError ? [{ fieldId: "operator-mfa", message: codeError }] : []);

  return (
    <section className={styles.flow} aria-labelledby="operator-sign-in-title">
      <div className={styles.intro}>
        <p className={styles.context}>Betplus back office</p>
        <h1 id="operator-sign-in-title">{step === "credentials" ? "Operator sign in" : "Verify with MFA"}</h1>
        <p>{step === "credentials"
          ? "Use your assigned operator account. This surface is separate from player sign-in."
          : `Enter the current code from your authenticator app. A security notice was also sent to ${maskedEmail}.`}</p>
      </div>

      {providerError && <InlineMessage tone="error" title="Access not confirmed">{providerError}</InlineMessage>}

      {step === "credentials" ? (
        <form className={styles.form} noValidate onSubmit={beginMfa}>
          <ErrorSummary errors={errors} />
          <TextField
            id="operator-email"
            label="Work email"
            type="email"
            inputMode="email"
            autoComplete="username"
            placeholder="name@betplus.com.ng"
            value={email}
            errorText={emailError}
            onBlur={validateEmail}
            onChange={(event) => setEmail(event.target.value)}
            required
          />
          <TextField
            id="operator-password"
            label="Password"
            type="password"
            autoComplete="current-password"
            value={password}
            errorText={passwordError}
            onBlur={validatePassword}
            onChange={(event) => setPassword(event.target.value)}
            required
          />
          <Button type="submit" status={pending ? "loading" : "idle"} statusLabel="Checking credentials…">
            Continue to MFA
          </Button>
          <p className={styles.prototypeNote}>Your password and authenticator code are verified by the separate Betplus institutional access service.</p>
        </form>
      ) : (
        <form className={styles.form} noValidate onSubmit={verifyMfa}>
          <ErrorSummary errors={errors} />
          <OtpInput
            id="operator-mfa"
            label="Authenticator code"
            value={code}
            onValueChange={setCode}
            errorText={codeError}
            helperText="You can paste all six digits. Codes are never stored in the browser."
          />
          <div className={styles.actions}>
            <Button variant="secondary" type="button" onClick={() => { setStep("credentials"); setCode(""); setCodeError(undefined); }}>
              Back
            </Button>
            <Button type="submit" status={pending ? "loading" : "idle"} statusLabel="Verifying MFA…">
              Verify and sign in
            </Button>
          </div>
        </form>
      )}
    </section>
  );
}
