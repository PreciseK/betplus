"use client";

import { useEffect, useMemo, useState } from "react";
import { backOfficeGateway, type BackOfficeInstitutionUser } from "@betplus/api-client";
import styles from "@/components/operations/OperationsConsole.module.css";

const ROLES = ['super_admin', 'system_admin', 'support_agent', 'support_lead', 'finance', 'compliance', 'game_ops', 'content_editor', 'cultural_reviewer'] as const;

export function RolesConsole() {
  const [users, setUsers] = useState<BackOfficeInstitutionUser[]>();
  const [loadFailed, setLoadFailed] = useState(false);

  useEffect(() => {
    if (!backOfficeGateway.hasSession()) {
      setLoadFailed(true);
      return;
    }
    backOfficeGateway.institutionUsers()
      .then((result) => setUsers(result.users))
      .catch(() => setLoadFailed(true));
  }, []);

  const byRole = useMemo(() => {
    const grouped = new Map<string, BackOfficeInstitutionUser[]>();
    for (const role of ROLES) grouped.set(role, []);
    for (const user of users ?? []) grouped.get(user.role)?.push(user);
    return grouped;
  }, [users]);

  if (loadFailed) {
    return <div className={styles.page}><p className={styles.muted}>Live user data could not be loaded. Sign in again if this persists.</p></div>;
  }

  return (
    <div className={styles.page}>
      <header className={styles.pageHeader}>
        <div>
          <p className={styles.context}>Administration</p>
          <h1>Roles</h1>
          <p>Who holds each role, derived from the real team access list.</p>
        </div>
      </header>

      <section className={styles.section} aria-labelledby="roles-title">
        <header className={styles.sectionHeader}>
          <div><h2 id="roles-title">Roles overview</h2><p>{users?.length ?? 0} accounts across {ROLES.length} roles.</p></div>
        </header>

        {users === undefined ? <p className={styles.muted}>Loading…</p> : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">Institution users grouped by role</caption>
              <thead><tr><th scope="col">Role</th><th scope="col">Accounts</th><th scope="col">Members</th></tr></thead>
              <tbody>{ROLES.map((role) => {
                const members = byRole.get(role) ?? [];
                return (
                  <tr key={role}>
                    <th scope="row" data-label="Role">{role.replaceAll("_", " ")}</th>
                    <td data-label="Accounts">{members.length}</td>
                    <td data-label="Members">{members.length > 0 ? members.map((m) => m.display_name).join(", ") : "—"}</td>
                  </tr>
                );
              })}</tbody>
            </table>
          </div>
        )}
      </section>

      <aside className={styles.contractNote} aria-labelledby="roles-boundary-title">
        <h2 id="roles-boundary-title">Integration boundary</h2>
        <p>
          Roles are a fixed, hardcoded set of eight — there is no capability/permissions table, and nothing here
          lets you edit which actions a role can take. This view is read-only by design; editing a role&apos;s
          capabilities would need a new permissions table and a rewrite of how routes enforce roles today.
        </p>
      </aside>
    </div>
  );
}
