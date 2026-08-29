# Deployment Guide

---

## 1. Current deployment model

Manual file upload to a cPanel-style shared host. There is no pipeline, no container, no
infrastructure-as-code and no release automation of any kind.

| Aspect | Current |
|---|---|
| Web server | **Apache** with `.htaccess` in six locations |
| Document root | `BlackRed/EngineAndServices/public/` |
| PHP | mod_php or CGI (not FPM-specific) |
| Release method | File copy; `ussdDeploy.zip` and `app.zip` are committed deployment bundles |
| Rollback | None |
| CI/CD | None — no `.github/`, no `.gitlab-ci.yml`, no Jenkinsfile |
| Containers | None |
| Scheduled work | cPanel cron → `ussd/workers/cleanup.php` |
| Long-running workers | **None** — `workers/` is empty |
| Cache / queue | None |
| Backups | Not configured in-repo |
| Monitoring | Monolog to `logs/app.log`; Apache `error_log` files inside the web root |

## 2. `.htaccess` inventory

| Location | Purpose |
|---|---|
| `/.htaccess` | Root |
| `public/.htaccess` | Front-controller rewrite to `index.php` |
| `src/.htaccess` | Deny direct access |
| `logs/.htaccess` | Deny |
| `migrations/.htaccess` | Deny |
| `ussd/.htaccess` | USSD rewrite |

`public/htaccess.txt` is an unused copy kept alongside the live file.

Denying direct access to `src/`, `logs/` and `migrations/` is correct and shows the risk was
understood. It is nonetheless defence-in-depth for a layout that puts application code inside
the served tree — the target should place only the front controller in the document root.

## 3. Distance from the PRD's hosting requirements (§5.5)

| Requirement | Current |
|---|---|
| `REQ-HOST-001` VPS or dedicated with root and WHM | Unknown; layout suggests shared hosting |
| `REQ-HOST-002` static egress IP under change control | Not established — **a Phase 0 blocker for OPay, which IP-whitelists in both directions** |
| `REQ-HOST-003` Nginx terminating TLS, reverse-proxying PHP-FPM and Passenger | Apache, no proxy layer |
| `REQ-HOST-004` Python app server, multi-process | No Python |
| `REQ-HOST-005` supervised long-running queue workers | None; only cron |
| `REQ-HOST-006` cron for scheduled work only | Correct by accident — cron is the only mechanism |
| `REQ-HOST-007` tuned FPM pools, opcache, buffer pool, Redis maxmemory | No tuning, no Redis |
| `REQ-HOST-008` rehearsed scale-out path | None; DB-backed sessions block statelessness |
| `REQ-HOST-009` off-server backups, 15-min binlog shipping, monthly restore drills | Not configured |
| `REQ-HOST-010` staging mirroring production | No staging; and it cannot be built — see [data-models.md](./data-models.md) §2.1 |
| `REQ-HOST-011` scripted atomic deploys with rollback | Manual file copy |

Every line of §5.5 is greenfield work. None of it is a migration of something that exists.

## 4. Deployment risks carried by the current model

1. **No rollback.** A bad upload is corrected by another upload.
2. **No staging.** Changes are validated in production.
3. **Runtime logs inside the web root** (`public/error_log`, `ussd/error_log`) — served unless
   an `.htaccess` rule catches them.
4. **Committed build artefacts** (`app.zip`, `icons.zip`, `ussdDeploy.zip`) with no guarantee
   they match the deployed source.
5. **Two independent config files** that must be updated together on every environment change.
6. **No health gate** — `/api/health` exists but nothing consumes it.

## 5. Target sequencing

Infrastructure is on the critical path, not a Phase 5 concern. In particular the **static
egress IP must be provisioned and registered on the OPay dashboard before any payment
integration work can be tested at all**, which places it in Phase 0 alongside the commercial
and legal items.

The order that unblocks the most work soonest:

1. VPS/WHM with root, static egress IP, IP registered with OPay (test + production).
2. Nginx + PHP-FPM, tuned; Redis with AOF.
3. Staging built from reconstructed migrations — the point at which automated testing becomes
   possible at all.
4. Scripted atomic release with symlinked release directories and rehearsed rollback.
5. Supervised workers under systemd or a supervisor daemon.
6. Off-server backups with binlog shipping, and a restore drill.
7. Passenger or supervised Gunicorn for the Python engine, multi-process.
