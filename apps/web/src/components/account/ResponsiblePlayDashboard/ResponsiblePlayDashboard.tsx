"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Amount } from "@/components/ui/Amount/Amount";
import { Button } from "@/components/ui/Button/Button";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { Banner } from "@/components/ui/feedback/Banner/Banner";
import { InlineMessage } from "@/components/ui/feedback/InlineMessage/InlineMessage";
import { Icon } from "@/components/ui/Icon/Icon";
import { MoneyInput } from "@/components/ui/MoneyInput/MoneyInput";
import { LoadingState } from "@/components/ui/states/LoadingState/LoadingState";
import { TextField } from "@/components/ui/TextField/TextField";
import { formatKobo } from "@/lib/money";
import {
  mockResponsiblePlayGateway,
  type DurationOption,
  type LimitKey,
  type PlayerLimit,
  type ResponsiblePlayGateway,
  type ResponsiblePlaySnapshot,
} from "@/mocks/responsiblePlay";
import styles from "./ResponsiblePlayDashboard.module.css";

type Confirmation = "cool-off" | "self-exclusion" | "deactivate" | null;

export function ResponsiblePlayDashboard({ gateway = mockResponsiblePlayGateway }: { gateway?: ResponsiblePlayGateway }) {
  const [snapshot, setSnapshot] = useState<ResponsiblePlaySnapshot>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [editingKey, setEditingKey] = useState<LimitKey>();
  const [draftValue, setDraftValue] = useState<number | null>(null);
  const [limitError, setLimitError] = useState<string>();
  const [limitBusy, setLimitBusy] = useState(false);
  const [notice, setNotice] = useState<{ title: string; body: string }>();
  const [selectedCoolOff, setSelectedCoolOff] = useState<string>();
  const [selectedExclusion, setSelectedExclusion] = useState<string>();
  const [selectionError, setSelectionError] = useState<string>();
  const [confirmation, setConfirmation] = useState<Confirmation>(null);
  const [confirmationEndsAt, setConfirmationEndsAt] = useState<string>();
  const [protectionBusy, setProtectionBusy] = useState(false);
  const [deactivateSuccess, setDeactivateSuccess] = useState<string>();

  useEffect(() => {
    let active = true;
    gateway.load()
      .then((value) => { if (active) setSnapshot(value); })
      .catch(() => { if (active) setLoadFailed(true); });
    return () => { active = false; };
  }, [gateway]);

  if (loadFailed) {
    return <InlineMessage tone="error" title="Responsible-play settings are unavailable">Your limits were not changed. Try again later or contact support.</InlineMessage>;
  }

  if (!snapshot) {
    return <LoadingState label="Loading your safety settings" description="Checking current limits and account protection status." />;
  }

  const blocked = snapshot.status !== "active";
  const currentLimit = snapshot.limits.find((limit) => limit.key === editingKey);
  const selectedBreak = snapshot.coolOffOptions.find((option) => option.id === selectedCoolOff);
  const selectedSelfExclusion = snapshot.selfExclusionOptions.find((option) => option.id === selectedExclusion);

  const startEditing = (limit: PlayerLimit) => {
    setEditingKey(limit.key);
    setDraftValue(limit.currentValue);
    setLimitError(undefined);
    setNotice(undefined);
  };

  const saveLimit = async (event: FormEvent) => {
    event.preventDefault();
    if (!currentLimit || draftValue === null || !Number.isSafeInteger(draftValue) || draftValue <= 0) {
      setLimitError(currentLimit?.unit === "minutes" ? "Enter at least 1 minute." : "Enter an amount greater than ₦0.");
      return;
    }

    const isReduction = draftValue <= currentLimit.currentValue;
    setLimitBusy(true);
    setLimitError(undefined);
    try {
      const updated = await gateway.updateLimit(currentLimit.key, draftValue);
      setSnapshot((value) => value ? { ...value, limits: value.limits.map((limit) => limit.key === updated.key ? updated : limit) } : value);
      setNotice({
        title: isReduction ? "Lower limit active now" : "Increase scheduled",
        body: isReduction
          ? `${currentLimit.label} is now ${formatLimit(updated)} across all games.`
          : `Your current limit remains ${formatLimit(currentLimit)} until ${formatWat(updated.pendingEffectiveAt!)}.`,
      });
      setEditingKey(undefined);
      setDraftValue(null);
    } catch {
      setLimitError("This limit could not be saved. Your current limit is unchanged.");
    } finally {
      setLimitBusy(false);
    }
  };

  const requestProtection = (kind: Exclude<Confirmation, null | "deactivate">) => {
    const option = kind === "cool-off" ? selectedBreak : selectedSelfExclusion;
    if (!option) {
      setSelectionError(kind === "cool-off" ? "Choose a break duration first." : "Choose an exclusion period first.");
      return;
    }
    setSelectionError(undefined);
    setConfirmationEndsAt(new Date(Date.now() + option.durationHours * 60 * 60 * 1000).toISOString());
    setConfirmation(kind);
  };

  const applyProtection = async () => {
    if (!confirmation || confirmation === "deactivate") return;
    const option = confirmation === "cool-off" ? selectedBreak : selectedSelfExclusion;
    if (!option) return;
    setProtectionBusy(true);
    try {
      const result = confirmation === "cool-off"
        ? await gateway.startCoolOff(option.id)
        : await gateway.selfExclude(option.id);
      setSnapshot((value) => value ? { ...value, status: result.status, statusEndsAt: result.statusEndsAt } : value);
      setNotice({
        title: confirmation === "cool-off" ? "Break started" : "Self-exclusion active",
        body: `Play and deposits are blocked until ${formatWat(result.statusEndsAt)}. Withdrawal remains available. An SMS confirmation has been requested.`,
      });
      setConfirmation(null);
      setConfirmationEndsAt(undefined);
    } catch {
      setSelectionError("The request was not applied. Your account status is unchanged.");
      setConfirmation(null);
      setConfirmationEndsAt(undefined);
    } finally {
      setProtectionBusy(false);
    }
  };

  const applyDeactivation = async () => {
    setProtectionBusy(true);
    try {
      if (gateway.deactivateAccount) {
        await gateway.deactivateAccount();
      }
      setConfirmation(null);
      setDeactivateSuccess("Your account is now deactivated. You have been placed in a 6-month cooling-off state. Transaction records are preserved for 7 years under statutory NLRC/AML regulations.");
    } catch {
      setSelectionError("Could not deactivate account right now. Please try again or contact support.");
      setConfirmation(null);
    } finally {
      setProtectionBusy(false);
    }
  };

  return (
    <div className={styles.dashboard}>
      <StatusBanner snapshot={snapshot} />

      {deactivateSuccess && (
        <InlineMessage tone="warning" title="Account Deactivated">
          {deactivateSuccess}
        </InlineMessage>
      )}

      <section className={styles.positionSection} aria-labelledby="net-position-title">
        <div className={styles.sectionHeading}>
          <div><h2 id="net-position-title">Net position</h2><p>Total won minus total staked across every game.</p></div>
          <Button href="/activity" variant="quiet">View activity</Button>
        </div>
        <div className={styles.positionGrid}>
          <Position label="Last 7 days" valueKobo={snapshot.netPositionKobo.sevenDays} />
          <Position label="Last 30 days" valueKobo={snapshot.netPositionKobo.thirtyDays} />
          <Position label="Last 90 days" valueKobo={snapshot.netPositionKobo.ninetyDays} />
        </div>
      </section>

      {notice && <InlineMessage tone="info" title={notice.title}>{notice.body}</InlineMessage>}

      <section className={styles.limitsSection} aria-labelledby="limits-title">
        <div className={styles.sectionHeading}>
          <div><h2 id="limits-title">Your limits</h2><p>Deposit and stake limits apply across BlackRed and Heritage combined.</p></div>
          <span className={styles.ruleNote}>Lower now · increases after 24 hours</span>
        </div>
        <div className={styles.limitList}>
          {snapshot.limits.map((limit) => (
            <div className={styles.limitRow} key={limit.key}>
              <div><strong>{limit.label}</strong><span>{limit.scope}</span></div>
              <div className={styles.limitValue}>
                <span>Current</span><strong>{formatLimit(limit)}</strong>
                {limit.pendingValue !== undefined && <small>Changes to {formatLimit(limit, limit.pendingValue)} on {formatWat(limit.pendingEffectiveAt!)}</small>}
              </div>
              <Button variant="secondary" disabled={blocked} onClick={() => startEditing(limit)}>Change</Button>
            </div>
          ))}
        </div>

        {currentLimit && (
          <form className={styles.limitEditor} onSubmit={saveLimit} noValidate>
            <div><h3>Change {currentLimit.label.toLowerCase()}</h3><p>The platform decides whether the change is immediate or held for 24 hours by comparing it with your current limit.</p></div>
            {currentLimit.unit === "kobo" ? (
              <MoneyInput id="limit-value" label="New limit" valueKobo={draftValue} errorText={limitError} onValueChange={setDraftValue} required />
            ) : (
              <TextField id="limit-value" label="New session limit in minutes" inputMode="numeric" value={draftValue === null ? "" : String(draftValue)} errorText={limitError} onChange={(event) => setDraftValue(event.target.value ? Number(event.target.value.replace(/\D/g, "")) : null)} required />
            )}
            {draftValue !== null && draftValue > 0 && (
              <p className={styles.changeTiming}><Icon name="info" /> {draftValue <= currentLimit.currentValue ? "This lower limit takes effect immediately." : "This increase keeps your current limit in place for 24 hours."}</p>
            )}
            <div className={styles.editorActions}><Button variant="secondary" onClick={() => { setEditingKey(undefined); setLimitError(undefined); }}>Cancel</Button><Button type="submit" status={limitBusy ? "loading" : "idle"} statusLabel="Saving limit…">Save limit</Button></div>
          </form>
        )}
      </section>

      {!blocked && (
        <div className={styles.protectionGrid}>
          <ProtectionSection
            id="take-a-break"
            title="Take a break"
            description="Temporarily block play and deposits. Withdrawal stays available and marketing stops."
            options={snapshot.coolOffOptions}
            selected={selectedCoolOff}
            disabled={false}
            actionLabel="Start a break"
            onSelect={(id) => { setSelectedCoolOff(id); setSelectionError(undefined); }}
            onAction={() => requestProtection("cool-off")}
          />
          <ProtectionSection
            id="self-exclusion"
            title="Self-exclusion"
            description="Exclude yourself across every game and channel. The selected period cannot be shortened or cancelled."
            options={snapshot.selfExclusionOptions}
            selected={selectedExclusion}
            disabled={false}
            actionLabel="Continue to self-exclusion"
            onSelect={(id) => { setSelectedExclusion(id); setSelectionError(undefined); }}
            onAction={() => requestProtection("self-exclusion")}
          />
        </div>
      )}

      {selectionError && <InlineMessage tone="error" title="Request not ready">{selectionError}</InlineMessage>}

      <section className={styles.withdrawalAccess} aria-labelledby="withdrawal-title">
        <Icon name="wallet" size="navigation" />
        <div><h2 id="withdrawal-title">Withdrawal remains available</h2><p>Limits, breaks and exclusions never prevent access to eligible Winnings Balance funds.</p></div>
        <Button href="/wallet" variant="secondary">Go to withdrawal</Button>
      </section>

      <section className={styles.deactivationSection} aria-labelledby="deactivation-title">
        <div>
          <h2 id="deactivation-title">Deactivate Account / Account Closure</h2>
          <p>
            Request account deactivation with a 6-month cooling-off period. In accordance with National Lottery Regulatory Commission (NLRC) and AML/CFT regulations, your transaction records and financial history are legally preserved for 7 years. You can contact support within 6 months to reactivate.
          </p>
        </div>
        <Button variant="secondary" onClick={() => setConfirmation("deactivate")}>Deactivate Account</Button>
      </section>

      <Dialog
        open={confirmation === "cool-off"}
        title={`Start a ${selectedBreak?.label ?? ""} break?`}
        confirmLabel="Start break"
        busy={protectionBusy}
        onConfirm={applyProtection}
        onClose={() => { setConfirmation(null); setConfirmationEndsAt(undefined); }}
      >
        <ConfirmationCopy option={selectedBreak} endsAt={confirmationEndsAt} type="break" />
      </Dialog>

      <Dialog
        open={confirmation === "self-exclusion"}
        title={`Self-exclude for ${selectedSelfExclusion?.label ?? ""}?`}
        confirmLabel="Confirm self-exclusion"
        busy={protectionBusy}
        destructive
        balancedActions
        onConfirm={applyProtection}
        onClose={() => { setConfirmation(null); setConfirmationEndsAt(undefined); }}
      >
        <ConfirmationCopy option={selectedSelfExclusion} endsAt={confirmationEndsAt} type="exclusion" />
      </Dialog>

      <Dialog
        open={confirmation === "deactivate"}
        title="Deactivate your account?"
        confirmLabel="Confirm Deactivation"
        busy={protectionBusy}
        destructive
        balancedActions
        onConfirm={applyDeactivation}
        onClose={() => setConfirmation(null)}
      >
        <div className={styles.confirmationCopy}>
          <p>
            When you deactivate, you will be immediately placed in an <strong>inactive state for 6 months</strong>.
          </p>
          <p>
            <strong>Compliance Notice:</strong> Your past financial ledger, game tickets, and KYC records are retained for <strong>7 years</strong> strictly in compliance with Nigerian gaming and anti-money laundering laws (NLRC & AML/CFT).
          </p>
          <p>
            You can reach out to Betplus Support at any time during the 6-month window if you wish to reactivate.
          </p>
        </div>
      </Dialog>
    </div>
  );
}

function StatusBanner({ snapshot }: { snapshot: ResponsiblePlaySnapshot }) {
  if (snapshot.status === "active") {
    return <div className={styles.activeStatus}><Icon name="check" /><div><strong>Account protection active</strong><span>No break or exclusion is currently active.</span></div><Button href="/wallet" variant="secondary">Withdraw</Button></div>;
  }

  const copy = statusCopy(snapshot);

  return <Banner tone="info" title={copy.title} action={<Button href="/wallet" variant="secondary">Withdraw</Button>}><p>{copy.body} Withdrawal remains available.</p></Banner>;
}

function statusCopy(snapshot: ResponsiblePlaySnapshot) {
  if (snapshot.status === "cool-off") return { title: "Break active", body: `Play and deposits are blocked until ${formatWat(snapshot.statusEndsAt!)}.` };
  if (snapshot.status === "self-excluded") return { title: "Self-exclusion active", body: `Play, deposits and marketing are blocked until ${formatWat(snapshot.statusEndsAt!)}.` };
  if (snapshot.status === "registry-excluded") return { title: "Play and deposits are unavailable", body: "Your state registry status applies across every game and channel. You do not need to self-exclude separately." };
  if (snapshot.status === "registry-unavailable") return { title: "Play is temporarily unavailable", body: "The required registry check could not be completed. No game can be placed until the check is current." };
  return { title: "Play is temporarily paused", body: "A platform safety control is active. Deposits and withdrawals remain available unless another restriction applies." };
}

function Position({ label, valueKobo }: { label: string; valueKobo: number }) {
  return <div className={styles.position}><span>{label}</span><Amount amountKobo={valueKobo} size="hero" showPositiveSign accessibleLabel={`${label} net position ${formatKobo(valueKobo)}`} /><small>{valueKobo > 0 ? "Net ahead" : valueKobo < 0 ? "Net down" : "Even"}</small></div>;
}

function ProtectionSection({ id, title, description, options, selected, disabled, actionLabel, onSelect, onAction }: {
  id: string;
  title: string;
  description: string;
  options: DurationOption[];
  selected?: string;
  disabled: boolean;
  actionLabel: string;
  onSelect: (id: string) => void;
  onAction: () => void;
}) {
  return (
    <section className={styles.protectionSection} aria-labelledby={`${id}-title`}>
      <div><h2 id={`${id}-title`}>{title}</h2><p>{description}</p></div>
      <div className={styles.durationOptions}>
        {options.map((option) => <button key={option.id} type="button" aria-pressed={selected === option.id} disabled={disabled} onClick={() => onSelect(option.id)}><strong>{option.label}</strong><span>{option.detail}</span></button>)}
      </div>
      <Button variant="secondary" disabled={disabled} onClick={onAction}>{actionLabel}</Button>
    </section>
  );
}

function ConfirmationCopy({ option, endsAt, type }: { option?: DurationOption; endsAt?: string; type: "break" | "exclusion" }) {
  if (!option || !endsAt) return null;
  return <div className={styles.confirmationCopy}><p>From now until <strong>{formatWat(endsAt)}</strong>, play and deposits will be blocked across every game and channel.</p><p>Withdrawal remains available. Marketing messages stop and an SMS confirmation will be requested.</p>{type === "exclusion" && <p><strong>This exclusion cannot be shortened or cancelled during the selected period.</strong></p>}</div>;
}

function formatLimit(limit: PlayerLimit, value = limit.currentValue) {
  return limit.unit === "kobo" ? formatKobo(value) : `${value} minutes`;
}

function formatWat(value: string) {
  return `${new Intl.DateTimeFormat("en-NG", { dateStyle: "medium", timeStyle: "short", timeZone: "Africa/Lagos" }).format(new Date(value))} WAT`;
}
