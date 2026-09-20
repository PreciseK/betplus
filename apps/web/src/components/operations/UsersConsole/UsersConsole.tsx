"use client";

import { useEffect, useState } from "react";
import { backOfficeGateway, BackOfficeApiError, type BackOfficeInstitutionUser } from "@betplus/api-client";
import { Button } from "@/components/ui/Button/Button";
import { Icon } from "@/components/ui/Icon/Icon";
import styles from "@/components/operations/OperationsConsole.module.css";

const ROLES = ['super_admin', 'system_admin', 'support_agent', 'support_lead', 'finance', 'compliance', 'game_ops', 'content_editor', 'cultural_reviewer'] as const;

export function UsersConsole() {
  const [users, setUsers] = useState<BackOfficeInstitutionUser[]>();
  const [loadFailed, setLoadFailed] = useState(false);
  const [receipt, setReceipt] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [busyId, setBusyId] = useState<number>();

  const [email, setEmail] = useState("");
  const [displayName, setDisplayName] = useState("");
  const [role, setRole] = useState<(typeof ROLES)[number]>("support_agent");

  function load() {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    backOfficeGateway.institutionUsers()
      .then((result) => setUsers(result.users))
      .catch(() => setLoadFailed(true));
  }

  useEffect(load, []);

  async function createUser(event: React.FormEvent) {
    event.preventDefault();
    setError("");
    setSubmitting(true);
    try {
      const created = await backOfficeGateway.createInstitutionUser({ email, display_name: displayName, role });
      setReceipt(`${created.display_name} created. One-time password: ${created.one_time_password} — record it now, it will not be shown again.`);
      setEmail(""); setDisplayName(""); setRole("support_agent");
      load();
    } catch (submitError) {
      setError(submitError instanceof BackOfficeApiError ? submitError.message : "Could not create that user.");
    } finally {
      setSubmitting(false);
    }
  }

  async function toggleStatus(user: BackOfficeInstitutionUser) {
    setBusyId(user.id);
    try {
      const nextStatus = user.status === "active" ? "suspended" : "active";
      await backOfficeGateway.updateInstitutionUser(user.id, { status: nextStatus });
      setReceipt(`${user.display_name} is now ${nextStatus}.`);
      load();
    } catch {
      setReceipt("Could not update that user. Please try again.");
    } finally {
      setBusyId(undefined);
    }
  }

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live user data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Administration</p>
          <h1>Team access</h1>
          <p>Real institution-user accounts. Applies immediately — no maker-checker gate on account management.</p>
        </div>
      </header>

      {receipt && <div className={styles.receipt} role="status"><Icon name="check" />{receipt}</div>}

      <section className={styles.section} aria-labelledby="create-user-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="create-user-title">Add a team member</h2><p>Issues a one-time password and a fresh TOTP secret, shown once.</p></div>
        </header>

        <form onSubmit={createUser} className={styles.filters} aria-label="Create an institution user">
          <label className={styles.selectField} htmlFor="user-email">
            Email
            <input id="user-email" type="email" required value={email} onChange={(event) => setEmail(event.target.value)} />
          </label>
          <label className={styles.selectField} htmlFor="user-display-name">
            Display name
            <input id="user-display-name" type="text" required value={displayName} onChange={(event) => setDisplayName(event.target.value)} />
          </label>
          <label className={styles.selectField} htmlFor="user-role">
            Role
            <select id="user-role" value={role} onChange={(event) => setRole(event.target.value as (typeof ROLES)[number])}>
              {ROLES.map((option) => <option key={option} value={option}>{option.replaceAll("_", " ")}</option>)}
            </select>
          </label>
          {error && <span className={styles.fieldError} role="alert"><Icon name="error" />{error}</span>}
          <Button type="submit" status={submitting ? "loading" : "idle"}>Create account</Button>
        </form>
      </section>

      <section className={styles.section} aria-labelledby="users-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="users-title">Team members</h2><p>{users?.length ?? 0} accounts.</p></div>
        </header>

        {users === undefined ? <p className={styles.muted}>Loading…</p> : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Institution user accounts</caption>
              <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last login</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>{users.map((user) => (
                <tr key={user.id}>
                  <th scope="row" data-label="Name">{user.display_name}</th>
                  <td data-label="Email">{user.email}</td>
                  <td data-label="Role">{user.role.replaceAll("_", " ")}</td>
                  <td data-label="Status"><span className={styles.statusLabel}>{user.status}</span></td>
                  <td data-label="Last login">{user.last_login_at ? new Date(user.last_login_at).toLocaleString() : "Never"}</td>
                  <td data-label="Actions">
                    <Button variant="secondary" onClick={() => toggleStatus(user)} status={busyId === user.id ? "loading" : "idle"}>
                      {user.status === "active" ? "Suspend" : "Reactivate"}
                    </Button>
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
