"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { profileGateway } from "@betplus/api-client";
import styles from "./SettingsDashboard.module.css";

type SettingsSection = "profile" | "notifications" | "security" | "preferences" | "limits";

const SECTIONS = [
  { id: "profile" as SettingsSection, label: "Profile", icon: "👤", desc: "Name, phone, KYC status" },
  { id: "notifications" as SettingsSection, label: "Notifications", icon: "🔔", desc: "SMS, push, email alerts" },
  { id: "security" as SettingsSection, label: "Security", icon: "🔒", desc: "PIN, 2FA, sessions" },
  { id: "preferences" as SettingsSection, label: "Preferences", icon: "🎨", desc: "Theme, language, sounds" },
  { id: "limits" as SettingsSection, label: "Play Limits", icon: "🛡️", desc: "Deposit, stake & session caps" },
];

const KYC_TIER_LABELS: Record<number, string> = {
  0: "Unverified",
  1: "NIN verified",
  2: "NIN and BVN verified",
};

const PREFERENCES_STORAGE_KEY = "betplus:preferences";

interface StoredPreferences {
  soundEnabled: boolean;
  animationsEnabled: boolean;
  language: string;
}

const DEFAULT_PREFERENCES: StoredPreferences = { soundEnabled: true, animationsEnabled: true, language: "en" };

function loadStoredPreferences(): StoredPreferences {
  if (typeof window === "undefined") return DEFAULT_PREFERENCES;
  try {
    const raw = window.localStorage.getItem(PREFERENCES_STORAGE_KEY);
    if (!raw) return DEFAULT_PREFERENCES;
    return { ...DEFAULT_PREFERENCES, ...(JSON.parse(raw) as Partial<StoredPreferences>) };
  } catch {
    return DEFAULT_PREFERENCES;
  }
}

export function SettingsDashboard() {
  const [activeSection, setActiveSection] = useState<SettingsSection>("profile");

  // ── Profile state — real, read from GET /v1/me ─────────────
  const [registeredName, setRegisteredName] = useState<string>();
  const [msisdn, setMsisdn] = useState<string>();
  const [kycTier, setKycTier] = useState<number>();
  const [accountStatus, setAccountStatus] = useState<string>();
  const [profileLoadFailed, setProfileLoadFailed] = useState(false);

  // ── Preferences state — real, persisted to localStorage ────
  const [preferences, setPreferences] = useState<StoredPreferences>(DEFAULT_PREFERENCES);

  useEffect(() => {
    setPreferences(loadStoredPreferences());
    let active = true;
    profileGateway
      .loadProfile()
      .then((profile) => {
        if (!active) return;
        setRegisteredName(profile.registeredName);
        setMsisdn(profile.msisdn);
        setKycTier(profile.kycTier);
        setAccountStatus(profile.accountStatus);
      })
      .catch(() => {
        if (active) setProfileLoadFailed(true);
      });
    return () => { active = false; };
  }, []);

  function updatePreference<K extends keyof StoredPreferences>(key: K, value: StoredPreferences[K]) {
    setPreferences((current) => {
      const next = { ...current, [key]: value };
      window.localStorage.setItem(PREFERENCES_STORAGE_KEY, JSON.stringify(next));
      return next;
    });
  }

  const initials = registeredName
    ? registeredName.split(" ").filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase()).join("")
    : "…";

  return (
    <div className={styles.settingsLayout}>
      {/* ── LEFT: SECTION SIDEBAR ── */}
      <nav className={styles.sectionNav} aria-label="Settings sections">
        <div className={styles.navHeader}>
          <div className={styles.eyebrow}>
            <span className={styles.eyebrowDash}>──</span>
            <span>SETTINGS</span>
          </div>
          <h1 className={styles.pageTitle}>Preferences</h1>
        </div>
        <ul className={styles.sectionList}>
          {SECTIONS.map((s) => (
            <li key={s.id}>
              <button
                type="button"
                className={`${styles.sectionBtn} ${activeSection === s.id ? styles.sectionBtnActive : ""}`}
                onClick={() => setActiveSection(s.id)}
              >
                <span className={styles.sectionIcon}>{s.icon}</span>
                <span className={styles.sectionBtnText}>
                  <span className={styles.sectionBtnLabel}>{s.label}</span>
                  <span className={styles.sectionBtnDesc}>{s.desc}</span>
                </span>
                <svg className={styles.chevron} width="14" height="14" viewBox="0 0 16 16" fill="none">
                  <path d="M5 3l5 5-5 5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/>
                </svg>
              </button>
            </li>
          ))}
        </ul>

        <div className={styles.dangerZone}>
          <button type="button" className={styles.dangerBtn}>
            <span>🚪</span>
            <span>Sign out</span>
          </button>
        </div>
      </nav>

      {/* ── RIGHT: PANEL CONTENT ── */}
      <div className={styles.panelContent}>
        {/* ── PROFILE ─────────────────────────────────── */}
        {activeSection === "profile" && (
          <div className={styles.section}>
            <div className={styles.sectionHead}>
              <div className={styles.sectionHeadIcon}>👤</div>
              <div>
                <h2 className={styles.sectionTitle}>Profile</h2>
                <p className={styles.sectionSub}>Your registered identity, as verified during onboarding.</p>
              </div>
            </div>

            {profileLoadFailed ? (
              <div className={styles.infoCard}>
                <span className={styles.infoIcon}>⚠</span>
                <p>Your profile could not be loaded. Sign in again if this persists.</p>
              </div>
            ) : (
              <>
                <div className={styles.avatarRow}>
                  <div className={styles.avatarCircle}>{initials}</div>
                  <div className={styles.avatarMeta}>
                    <span className={styles.avatarName}>{registeredName ?? "Loading…"}</span>
                  </div>
                </div>

                <div className={styles.fieldGrid}>
                  <div className={styles.field}>
                    <label className={styles.fieldLabel} htmlFor="display-name">Registered name</label>
                    <input
                      id="display-name"
                      className={`${styles.fieldInput} ${styles.fieldReadonly}`}
                      value={registeredName ?? ""}
                      readOnly
                    />
                    <span className={styles.fieldHint}>Set during identity verification and cannot be changed here.</span>
                  </div>
                  <div className={styles.field}>
                    <label className={styles.fieldLabel} htmlFor="phone">Phone number</label>
                    <input
                      id="phone"
                      type="tel"
                      className={`${styles.fieldInput} ${styles.fieldReadonly}`}
                      value={msisdn ?? ""}
                      readOnly
                    />
                    <span className={styles.fieldHint}>Linked to your OPay account and cannot be changed here.</span>
                  </div>
                </div>

                <div className={styles.infoCard}>
                  <span className={styles.infoIcon}>ℹ</span>
                  <p>
                    {kycTier !== undefined ? KYC_TIER_LABELS[kycTier] ?? `KYC tier ${kycTier}` : "Loading verification status…"}
                    {accountStatus ? ` · Account ${accountStatus}` : ""}
                  </p>
                </div>
              </>
            )}
          </div>
        )}

        {/* ── NOTIFICATIONS ────────────────────────────── */}
        {activeSection === "notifications" && (
          <div className={styles.section}>
            <div className={styles.sectionHead}>
              <div className={styles.sectionHeadIcon}>🔔</div>
              <div>
                <h2 className={styles.sectionTitle}>Notifications</h2>
                <p className={styles.sectionSub}>Control which alerts you receive and how.</p>
              </div>
            </div>
            <div className={styles.infoCard}>
              <span className={styles.infoIcon}>ℹ</span>
              <p>
                Notification preferences aren&apos;t available yet — there is no backend endpoint to read or save
                them, so this section previously showed toggles that did not do anything. Betplus currently sends
                SMS receipts for tickets automatically; that isn&apos;t user-configurable yet.
              </p>
            </div>
          </div>
        )}

        {/* ── SECURITY ─────────────────────────────────── */}
        {activeSection === "security" && (
          <div className={styles.section}>
            <div className={styles.sectionHead}>
              <div className={styles.sectionHeadIcon}>🔒</div>
              <div>
                <h2 className={styles.sectionTitle}>Security</h2>
                <p className={styles.sectionSub}>Manage PIN, two-factor authentication, and active sessions.</p>
              </div>
            </div>
            <div className={styles.infoCard}>
              <span className={styles.infoIcon}>ℹ</span>
              <p>
                PIN management, two-factor authentication and session listing aren&apos;t available yet — there is
                no backend endpoint for any of them. Sign-in itself is real (see the sign-in flow), this is only
                about post-login account security controls.
              </p>
            </div>
          </div>
        )}

        {/* ── PREFERENCES ──────────────────────────────── */}
        {activeSection === "preferences" && (
          <div className={styles.section}>
            <div className={styles.sectionHead}>
              <div className={styles.sectionHeadIcon}>🎨</div>
              <div>
                <h2 className={styles.sectionTitle}>Preferences</h2>
                <p className={styles.sectionSub}>Language, sound and animation settings, saved on this device.</p>
              </div>
            </div>

            <div className={styles.field}>
              <label className={styles.fieldLabel} htmlFor="language">Language</label>
              <select
                id="language"
                className={styles.fieldSelect}
                value={preferences.language}
                onChange={(e) => updatePreference("language", e.target.value)}
              >
                <option value="en">English</option>
                <option value="yo">Yorùbá</option>
                <option value="ig">Igbo</option>
                <option value="ha">Hausa</option>
              </select>
              <span className={styles.fieldHint}>Only English is translated today; other options are saved but not yet applied.</span>
            </div>

            <div className={styles.toggleList} style={{ marginTop: '24px' }}>
              <div className={styles.toggleRow}>
                <div className={styles.toggleInfo}>
                  <strong className={styles.toggleLabel}>Game sounds</strong>
                  <span className={styles.toggleDesc}>Play audio effects during card reveals and wins.</span>
                </div>
                <button
                  type="button"
                  role="switch"
                  aria-checked={preferences.soundEnabled}
                  className={`${styles.toggle} ${preferences.soundEnabled ? styles.toggleOn : ""}`}
                  onClick={() => updatePreference("soundEnabled", !preferences.soundEnabled)}
                >
                  <span className={styles.toggleThumb}></span>
                </button>
              </div>

              <div className={styles.toggleRow}>
                <div className={styles.toggleInfo}>
                  <strong className={styles.toggleLabel}>Animations</strong>
                  <span className={styles.toggleDesc}>Enable card flip and reveal animations.</span>
                </div>
                <button
                  type="button"
                  role="switch"
                  aria-checked={preferences.animationsEnabled}
                  className={`${styles.toggle} ${preferences.animationsEnabled ? styles.toggleOn : ""}`}
                  onClick={() => updatePreference("animationsEnabled", !preferences.animationsEnabled)}
                >
                  <span className={styles.toggleThumb}></span>
                </button>
              </div>
            </div>
          </div>
        )}

        {/* ── PLAY LIMITS ──────────────────────────────── */}
        {activeSection === "limits" && (
          <div className={styles.section}>
            <div className={styles.sectionHead}>
              <div className={styles.sectionHeadIcon}>🛡️</div>
              <div>
                <h2 className={styles.sectionTitle}>Play Limits</h2>
                <p className={styles.sectionSub}>Deposit, stake and session caps live on the Account page.</p>
              </div>
            </div>
            <div className={styles.infoCard}>
              <span className={styles.infoIcon}>ℹ</span>
              <p>
                Real deposit, stake and session limits — the ones the backend actually enforces — are set from
                the Account page, not here. This tab used to show a second, disconnected copy of the same
                controls that saved nothing; it has been removed to avoid two places claiming to set the same
                limit.
              </p>
            </div>
            <div className={styles.saveRow}>
              <Link href="/account" className={styles.btnSave}>Go to Account limits</Link>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
