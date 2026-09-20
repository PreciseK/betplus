"use client";

import { useEffect, useState } from "react";
import {
  backOfficeGateway,
  type BackOfficePromotion,
  type BackOfficeMonthlyDrawPool,
} from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { Icon } from "@/components/ui/Icon/Icon";
import { formatKobo } from "@/lib/money";
import { EmptyState } from "@/components/operations/EmptyState/EmptyState";
import opsStyles from "@/components/operations/OperationsConsole.module.css";
import styles from "./PromotionsConsole.module.css";

export function PromotionsConsole() {
  const [promotions, setPromotions] = useState<BackOfficePromotion[]>([]);
  const [monthlyDraws, setMonthlyDraws] = useState<BackOfficeMonthlyDrawPool[]>([]);
  const [loading, setLoading] = useState(true);
  const [receipt, setReceipt] = useState("");
  const [errorMsg, setErrorMsg] = useState("");

  // Emergency Kill Modal State
  const [killTarget, setKillTarget] = useState<BackOfficePromotion | null>(null);
  const [killJustification, setKillJustification] = useState("");
  const [killSubmitting, setKillSubmitting] = useState(false);
  const [killError, setKillError] = useState("");

  // Maker-Checker Proposal Modal State
  const [proposeTarget, setProposeTarget] = useState<BackOfficePromotion | null>(null);
  const [proposeJustification, setProposeJustification] = useState("");
  const [proposeRules, setProposeRules] = useState<Record<string, unknown>>({});
  const [proposeSubmitting, setProposeSubmitting] = useState(false);
  const [proposeError, setProposeError] = useState("");

  // Monthly Draw Trigger State
  const [triggerPeriod, setTriggerPeriod] = useState("");
  const [triggerSubmitting, setTriggerSubmitting] = useState(false);
  const [triggerSuccess, setTriggerSuccess] = useState("");

  useEffect(() => {
    loadData();
  }, []);

  async function loadData() {
    setLoading(true);
    try {
      const [promosRes, drawsRes] = await Promise.all([
        backOfficeGateway.promotions(),
        backOfficeGateway.monthlyDraws(),
      ]);
      setPromotions(promosRes.promotions);
      setMonthlyDraws(drawsRes.draw_pools);
      setErrorMsg("");
    } catch {
      setErrorMsg("Failed to load promotional campaign data from BackOffice.");
    } finally {
      setLoading(false);
    }
  }

  function handleOpenKillModal(promo: BackOfficePromotion) {
    setKillTarget(promo);
    setKillJustification("");
    setKillError("");
  }

  async function handleConfirmKill() {
    if (!killTarget) return;
    if (!killJustification.trim()) {
      setKillError("An emergency justification is mandatory under LSLGA governance.");
      return;
    }

    setKillSubmitting(true);
    setKillError("");
    try {
      await backOfficeGateway.emergencyKillPromotion(killTarget.campaign_key, {
        justification: killJustification.trim(),
      });
      setReceipt(`Promotion "${killTarget.name}" immediately disabled. Audit record committed.`);
      setKillTarget(null);
      await loadData();
    } catch (err: unknown) {
      setKillError(err instanceof Error ? err.message : "Failed to execute emergency kill switch.");
    } finally {
      setKillSubmitting(false);
    }
  }

  function handleOpenProposeModal(promo: BackOfficePromotion) {
    setProposeTarget(promo);
    setProposeJustification("");
    setProposeRules({ ...promo.rules });
    setProposeError("");
  }

  async function handleConfirmPropose() {
    if (!proposeTarget) return;
    if (!proposeJustification.trim()) {
      setProposeError("A change justification is required for the Maker-Checker audit log.");
      return;
    }

    setProposeSubmitting(true);
    setProposeError("");
    try {
      const res = await backOfficeGateway.proposeChange({
        change_type: "promotional_config_publish",
        payload: {
          campaign_key: proposeTarget.campaign_key,
          rules: proposeRules,
        },
        before_snapshot: {
          campaign_key: proposeTarget.campaign_key,
          rules: proposeTarget.rules,
          status: proposeTarget.status,
        },
        justification: proposeJustification.trim(),
      });

      setReceipt(
        `Change proposal #${res.id} queued successfully. Awaiting review from a second authorized operator.`
      );
      setProposeTarget(null);
      await loadData();
    } catch (err: unknown) {
      setProposeError(err instanceof Error ? err.message : "Failed to submit change proposal.");
    } finally {
      setProposeSubmitting(false);
    }
  }

  async function handleTriggerDraw() {
    setTriggerSubmitting(true);
    setTriggerSuccess("");
    try {
      const res = await backOfficeGateway.triggerMonthlyDraw(
        triggerPeriod ? { month_period: triggerPeriod } : undefined
      );
      setTriggerSuccess(res.message);
      await loadData();
    } catch (err: unknown) {
      setErrorMsg(err instanceof Error ? err.message : "Failed to trigger monthly draw execution.");
    } finally {
      setTriggerSubmitting(false);
    }
  }

  return (
    <div className={opsStyles.page}>
      <header className={opsStyles.pageHeader}>
        <div>
          <span className={opsStyles.context}>Product & Marketing Governance</span>
          <h1>Promotions & Boost Controls</h1>
          <p>
            Oversee active promotional campaigns, subvention budgets, and draw pools. All rule adjustments
            strictly enforce Maker-Checker dual authorization pursuant to LSLGA compliance.
          </p>
        </div>
      </header>

      {receipt && (
        <div className={opsStyles.receipt} role="status">
          <Icon name="check" />
          <div>
            <strong>Action Committed</strong>
            <p>{receipt}</p>
          </div>
          <button type="button" onClick={() => setReceipt("")} aria-label="Dismiss receipt">
            ✕
          </button>
        </div>
      )}

      {errorMsg && (
        <div className={opsStyles.warningStrip} role="alert">
          <Icon name="warning" />
          <div>
            <strong>Error</strong>
            <p>{errorMsg}</p>
          </div>
          <button type="button" onClick={() => setErrorMsg("")} aria-label="Dismiss error">
            ✕
          </button>
        </div>
      )}

      {loading ? (
        <p className={opsStyles.muted}>Loading campaign configurations and performance metrics…</p>
      ) : (
        <section aria-labelledby="campaigns-heading">
          <h2 id="campaigns-heading" className="sr-only">
            Active Campaigns
          </h2>
          {promotions.length === 0 ? (
            <EmptyState
              icon="activity"
              title="No active promotional campaigns"
              description="No marketing promotions or boost pools are currently configured in the game engine."
            />
          ) : (
            <div className={styles.promoGrid}>
            {promotions.map((promo) => {
              const isWeekend = promo.campaign_key === "weekend_double_odds";
              const isVipDraw = promo.campaign_key === "monthly_vip_draw";
              const isVelocity = promo.campaign_key === "velocity_bonus";

              const rules = promo.rules as Record<string, any>;
              const stats = promo.stats as Record<string, any>;

              return (
                <article key={promo.campaign_key} className={styles.promoCard}>
                  <div className={styles.cardTop}>
                    <div className={styles.cardHeader}>
                      <div className={styles.cardTitleBlock}>
                        <h2>{promo.name}</h2>
                        <span className={styles.campaignKey}>{promo.campaign_key} (v{promo.version})</span>
                      </div>
                      <span
                        className={`${styles.statusBadge} ${
                          promo.status === "ENABLED" ? styles.statusBadgeEnabled : styles.statusBadgeDisabled
                        }`}
                      >
                        ● {promo.status}
                      </span>
                    </div>

                    <p className={styles.cardDescription}>{promo.description}</p>

                    {/* Rules Overview */}
                    <div className={styles.rulesSection}>
                      {isWeekend && (
                        <>
                          <div className={styles.ruleItem}>
                            <span className={styles.ruleItemLabel}>Max Stake</span>
                            <span className={styles.ruleItemValue}>{formatKobo(rules.maxStakeKobo ?? 100_00)}</span>
                          </div>
                          <div className={styles.ruleItem}>
                            <span className={styles.ruleItemLabel}>Max Boost</span>
                            <span className={styles.ruleItemValue}>{formatKobo(rules.maxBonusKobo ?? 1000_00)}</span>
                          </div>
                          <div className={styles.ruleItem}>
                            <span className={styles.ruleItemLabel}>Total Budget</span>
                            <span className={styles.ruleItemValue}>
                              {formatKobo(rules.totalBudgetKobo ?? 500000_00)}
                            </span>
                          </div>
                        </>
                      )}

                      {isVipDraw && (
                        <>
                          <div className={styles.ruleItem}>
                            <span className={styles.ruleItemLabel}>Turnover Stake</span>
                            <span className={styles.ruleItemValue}>
                              {formatKobo(rules.qualifyingStakeKobo ?? 20000_00)}
                            </span>
                          </div>
                          <div className={styles.ruleItem}>
                            <span className={styles.ruleItemLabel}>Rake Rate</span>
                            <span className={styles.ruleItemValue}>
                              {((rules.rakeBasisPoints ?? 100) / 100).toFixed(2)}%
                            </span>
                          </div>
                          <div className={styles.ruleItem}>
                            <span className={styles.ruleItemLabel}>Distribution</span>
                            <span className={styles.ruleItemValue}>
                              {(rules.prizeDistribution ?? [50, 30, 20]).join("/")}%
                            </span>
                          </div>
                        </>
                      )}

                      {isVelocity && (
                        <>
                          <div className={styles.ruleItem}>
                            <span className={styles.ruleItemLabel}>Target Rounds</span>
                            <span className={styles.ruleItemValue}>{rules.targetRounds ?? 30}</span>
                          </div>
                          <div className={styles.ruleItem}>
                            <span className={styles.ruleItemLabel}>Bonus Credit</span>
                            <span className={styles.ruleItemValue}>
                              {formatKobo(rules.bonusAmountKobo ?? 500_00)}
                            </span>
                          </div>
                          <div className={styles.ruleItem}>
                            <span className={styles.ruleItemLabel}>Expiry</span>
                            <span className={styles.ruleItemValue}>{rules.expiryDays ?? 7} Days</span>
                          </div>
                        </>
                      )}
                    </div>

                    {/* Stats & Utilization */}
                    <div className={styles.statsSection}>
                      {isWeekend && (
                        <>
                          <div className={styles.statsRow}>
                            <span className={styles.statsLabel}>Total Claims Count:</span>
                            <span className={styles.statsValue}>{stats.claims_count ?? 0}</span>
                          </div>
                          <div className={styles.statsRow}>
                            <span className={styles.statsLabel}>Boost Awarded:</span>
                            <span className={styles.statsValue}>
                              {formatKobo(stats.total_boost_awarded_kobo ?? 0)}
                            </span>
                          </div>
                          <div className={styles.progressBarTrack}>
                            <div
                              className={styles.progressBarFill}
                              style={{
                                width: `${Math.min(
                                  100,
                                  ((stats.total_boost_awarded_kobo ?? 0) / (rules.totalBudgetKobo ?? 500000_00)) * 100
                                )}%`,
                              }}
                            />
                          </div>
                        </>
                      )}

                      {isVipDraw && (
                        <>
                          <div className={styles.statsRow}>
                            <span className={styles.statsLabel}>Active Pool Tickets Issued:</span>
                            <span className={styles.statsValue}>{stats.latest_pool_tickets ?? 0}</span>
                          </div>
                          <div className={styles.statsRow}>
                            <span className={styles.statsLabel}>Completed Monthly Draws:</span>
                            <span className={styles.statsValue}>{stats.total_pools_completed ?? 0}</span>
                          </div>
                        </>
                      )}

                      {isVelocity && (
                        <>
                          <div className={styles.statsRow}>
                            <span className={styles.statsLabel}>Milestones Achieved:</span>
                            <span className={styles.statsValue}>{stats.milestones_achieved ?? 0}</span>
                          </div>
                          <div className={styles.statsRow}>
                            <span className={styles.statsLabel}>Total Bonus Disbursed:</span>
                            <span className={styles.statsValue}>
                              {formatKobo(stats.total_bonus_credited_kobo ?? 0)}
                            </span>
                          </div>
                        </>
                      )}
                    </div>
                  </div>

                  {/* Actions */}
                  <div className={styles.cardActions}>
                    <Button variant="secondary" onClick={() => handleOpenProposeModal(promo)}>
                      Propose Changes
                    </Button>
                    {promo.status === "ENABLED" && (
                      <Button variant="danger" onClick={() => handleOpenKillModal(promo)}>
                        Kill Switch
                      </Button>
                    )}
                  </div>
                </article>
              );
            })}
          </div>
        )}
      </section>
    )}

      {/* Monthly VIP Draw Pool Section */}
      <section style={{ marginBlockStart: "32px" }} aria-labelledby="draw-history-heading">
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <div>
            <h2 id="draw-history-heading" style={{ fontSize: "1.25rem", margin: 0 }}>
              Monthly VIP Draw Pools
            </h2>
            <p className={opsStyles.muted}>
              1% turnover rake pools drawn on the 1st of each month via provably fair HMAC-SHA256.
            </p>
          </div>
          <div style={{ display: "flex", gap: "8px", alignItems: "center" }}>
            <input
              type="text"
              placeholder="YYYY-MM (optional)"
              value={triggerPeriod}
              onChange={(e) => setTriggerPeriod(e.target.value)}
              style={{
                background: "rgba(255,255,255,0.05)",
                border: "1px solid rgba(255,255,255,0.15)",
                color: "#ffffff",
                padding: "8px 12px",
                borderRadius: "6px",
                fontSize: "0.85rem",
              }}
            />
            <Button
              variant="secondary"
              onClick={handleTriggerDraw}
              disabled={triggerSubmitting}
            >
              {triggerSubmitting ? "Executing Draw…" : "Trigger Draw"}
            </Button>
          </div>
        </div>

        {triggerSuccess && (
          <p style={{ color: "#10b981", fontSize: "0.88rem", marginTop: "8px" }}>
            ✓ {triggerSuccess}
          </p>
        )}

        <div className={styles.drawsTableWrap}>
          <table className={styles.drawsTable}>
            <thead>
              <tr>
                <th>Period</th>
                <th>Status</th>
                <th>Total Turnover</th>
                <th>Prize Pool</th>
                <th>Tickets Issued</th>
                <th>Players Qualified</th>
                <th>Drawn At</th>
              </tr>
            </thead>
            <tbody>
              {monthlyDraws.length === 0 ? (
                <tr>
                  <td colSpan={7} style={{ textAlign: "center", padding: "24px" }} className={opsStyles.muted}>
                    No monthly draw pools recorded yet.
                  </td>
                </tr>
              ) : (
                monthlyDraws.map((pool) => (
                  <tr key={pool.id}>
                    <td><strong>{pool.month_period}</strong></td>
                    <td>
                      <span
                        className={`${styles.statusBadge} ${
                          pool.status === "DISBURSED" ? styles.statusBadgeEnabled : styles.statusBadgeDisabled
                        }`}
                      >
                        {pool.status}
                      </span>
                    </td>
                    <td>{formatKobo(pool.total_turnover_kobo)}</td>
                    <td><strong>{formatKobo(pool.allocated_prize_pool_kobo)}</strong></td>
                    <td>{pool.total_tickets_issued.toLocaleString()}</td>
                    <td>{pool.qualifying_players_count}</td>
                    <td>{pool.drawn_at ? new Date(pool.drawn_at).toLocaleString("en-NG") : "Pending"}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>

      {/* Emergency Kill Confirmation Dialog */}
      {killTarget && (
        <Dialog
          open={true}
          title={`Emergency Kill: ${killTarget.name}`}
          confirmLabel="Confirm Emergency Kill"
          cancelLabel="Cancel"
          destructive={true}
          busy={killSubmitting}
          onConfirm={handleConfirmKill}
          onClose={() => setKillTarget(null)}
        >
          <div style={{ display: "flex", flexDirection: "column", gap: "16px" }}>
            <p className={styles.killWarning}>
              ⚠️ WARNING: This action will immediately halt this promotion across BlackRed, Heritage,
              and Caged games without requiring Maker-Checker review.
            </p>
            <div>
              <label
                htmlFor="kill-justification"
                style={{ display: "block", fontSize: "0.85rem", fontWeight: 600, marginBottom: "6px" }}
              >
                Emergency Justification (Required for LSLGA Audit):
              </label>
              <textarea
                id="kill-justification"
                rows={3}
                value={killJustification}
                onChange={(e) => setKillJustification(e.target.value)}
                placeholder="Detail reason for emergency shutdown (e.g. liquidity protection, actuarial review)..."
                style={{
                  width: "100%",
                  background: "rgba(0,0,0,0.3)",
                  border: "1px solid rgba(255,255,255,0.2)",
                  color: "#ffffff",
                  padding: "10px",
                  borderRadius: "6px",
                  fontSize: "0.9rem",
                }}
              />
            </div>

            {killError && <p style={{ color: "#ef4444", fontSize: "0.85rem" }}>{killError}</p>}
          </div>
        </Dialog>
      )}

      {/* Maker-Checker Proposal Dialog */}
      {proposeTarget && (
        <Dialog
          open={true}
          title={`Propose Changes: ${proposeTarget.name}`}
          confirmLabel="Submit Proposal"
          cancelLabel="Cancel"
          busy={proposeSubmitting}
          onConfirm={handleConfirmPropose}
          onClose={() => setProposeTarget(null)}
        >
          <div style={{ display: "flex", flexDirection: "column", gap: "16px" }}>
            <p className={opsStyles.muted}>
              Changes submitted here will create a reviewable proposal in the Maker-Checker queue.
              A checker with authorized permissions must approve before changes take effect.
            </p>

            {proposeTarget.campaign_key === "weekend_double_odds" && (
              <>
                <div>
                  <label style={{ display: "block", fontSize: "0.85rem", marginBottom: "4px" }}>
                    Max Stake (Kobo):
                  </label>
                  <input
                    type="number"
                    value={Number(proposeRules.maxStakeKobo ?? 10000)}
                    onChange={(e) =>
                      setProposeRules({ ...proposeRules, maxStakeKobo: parseInt(e.target.value, 10) })
                    }
                    style={{
                      width: "100%",
                      padding: "8px",
                      background: "rgba(0,0,0,0.3)",
                      border: "1px solid rgba(255,255,255,0.2)",
                      color: "#fff",
                      borderRadius: "6px",
                    }}
                  />
                  <small className={opsStyles.muted}>Currently: {formatKobo(proposeRules.maxStakeKobo as number ?? 10000)}</small>
                </div>
                <div>
                  <label style={{ display: "block", fontSize: "0.85rem", marginBottom: "4px" }}>
                    Max Bonus (Kobo):
                  </label>
                  <input
                    type="number"
                    value={Number(proposeRules.maxBonusKobo ?? 100000)}
                    onChange={(e) =>
                      setProposeRules({ ...proposeRules, maxBonusKobo: parseInt(e.target.value, 10) })
                    }
                    style={{
                      width: "100%",
                      padding: "8px",
                      background: "rgba(0,0,0,0.3)",
                      border: "1px solid rgba(255,255,255,0.2)",
                      color: "#fff",
                      borderRadius: "6px",
                    }}
                  />
                </div>
                <div>
                  <label style={{ display: "block", fontSize: "0.85rem", marginBottom: "4px" }}>
                    Total Weekend Budget (Kobo):
                  </label>
                  <input
                    type="number"
                    value={Number(proposeRules.totalBudgetKobo ?? 50000000)}
                    onChange={(e) =>
                      setProposeRules({ ...proposeRules, totalBudgetKobo: parseInt(e.target.value, 10) })
                    }
                    style={{
                      width: "100%",
                      padding: "8px",
                      background: "rgba(0,0,0,0.3)",
                      border: "1px solid rgba(255,255,255,0.2)",
                      color: "#fff",
                      borderRadius: "6px",
                    }}
                  />
                </div>
              </>
            )}

            {proposeTarget.campaign_key === "monthly_vip_draw" && (
              <>
                <div>
                  <label style={{ display: "block", fontSize: "0.85rem", marginBottom: "4px" }}>
                    Qualifying Turnover Stake (Kobo):
                  </label>
                  <input
                    type="number"
                    value={Number(proposeRules.qualifyingStakeKobo ?? 2000000)}
                    onChange={(e) =>
                      setProposeRules({ ...proposeRules, qualifyingStakeKobo: parseInt(e.target.value, 10) })
                    }
                    style={{
                      width: "100%",
                      padding: "8px",
                      background: "rgba(0,0,0,0.3)",
                      border: "1px solid rgba(255,255,255,0.2)",
                      color: "#fff",
                      borderRadius: "6px",
                    }}
                  />
                </div>
                <div>
                  <label style={{ display: "block", fontSize: "0.85rem", marginBottom: "4px" }}>
                    Rake Basis Points (e.g. 100 = 1.00%):
                  </label>
                  <input
                    type="number"
                    value={Number(proposeRules.rakeBasisPoints ?? 100)}
                    onChange={(e) =>
                      setProposeRules({ ...proposeRules, rakeBasisPoints: parseInt(e.target.value, 10) })
                    }
                    style={{
                      width: "100%",
                      padding: "8px",
                      background: "rgba(0,0,0,0.3)",
                      border: "1px solid rgba(255,255,255,0.2)",
                      color: "#fff",
                      borderRadius: "6px",
                    }}
                  />
                </div>
              </>
            )}

            {proposeTarget.campaign_key === "velocity_bonus" && (
              <>
                <div>
                  <label style={{ display: "block", fontSize: "0.85rem", marginBottom: "4px" }}>
                    Target Rounds:
                  </label>
                  <input
                    type="number"
                    value={Number(proposeRules.targetRounds ?? 30)}
                    onChange={(e) =>
                      setProposeRules({ ...proposeRules, targetRounds: parseInt(e.target.value, 10) })
                    }
                    style={{
                      width: "100%",
                      padding: "8px",
                      background: "rgba(0,0,0,0.3)",
                      border: "1px solid rgba(255,255,255,0.2)",
                      color: "#fff",
                      borderRadius: "6px",
                    }}
                  />
                </div>
                <div>
                  <label style={{ display: "block", fontSize: "0.85rem", marginBottom: "4px" }}>
                    Bonus Amount (Kobo):
                  </label>
                  <input
                    type="number"
                    value={Number(proposeRules.bonusAmountKobo ?? 50000)}
                    onChange={(e) =>
                      setProposeRules({ ...proposeRules, bonusAmountKobo: parseInt(e.target.value, 10) })
                    }
                    style={{
                      width: "100%",
                      padding: "8px",
                      background: "rgba(0,0,0,0.3)",
                      border: "1px solid rgba(255,255,255,0.2)",
                      color: "#fff",
                      borderRadius: "6px",
                    }}
                  />
                </div>
              </>
            )}

            <div>
              <label
                htmlFor="propose-justification"
                style={{ display: "block", fontSize: "0.85rem", fontWeight: 600, marginBottom: "6px" }}
              >
                Maker Justification (Mandatory):
              </label>
              <textarea
                id="propose-justification"
                rows={3}
                value={proposeJustification}
                onChange={(e) => setProposeJustification(e.target.value)}
                placeholder="State the business rationale or actuarial review reference for this change..."
                style={{
                  width: "100%",
                  background: "rgba(0,0,0,0.3)",
                  border: "1px solid rgba(255,255,255,0.2)",
                  color: "#ffffff",
                  padding: "10px",
                  borderRadius: "6px",
                  fontSize: "0.9rem",
                }}
              />
            </div>

            {proposeError && <p style={{ color: "#ef4444", fontSize: "0.85rem" }}>{proposeError}</p>}
          </div>
        </Dialog>
      )}
    </div>
  );
}
