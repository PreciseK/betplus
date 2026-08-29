# Epic 6 frontend implementation status

This inventory records whether an operator destination has a real, role-scoped frontend workflow. A sidebar link or generic placeholder does not count as implemented.

## Epic story coverage

| Story | Frontend status | Primary surface |
| --- | --- | --- |
| 6.1 RBAC and MFA | Implemented | Sign-in, access boundary, role-filtered navigation |
| 6.2 Audit log | Implemented | Audit log |
| 6.3 Maker-checker | Implemented | Approval workspace |
| 6.4 Player 360 | Implemented | Current case plus focused ledger, ticket, payment, tax, notification and responsible-play views |
| 6.5 Ticket replay | Implemented | Support tickets and outcome evidence |
| 6.6 Reconciliation | Implemented | Exception-first reconciliation queue |
| 6.7 Jurisdictions and tax | Implemented | Licences and tax console |
| 6.8 Game registry | Implemented | Game overview and prize-table management |
| 6.9 Regulatory reports | Implemented | Reports and exports |
| 6.10 Analytics | Implemented | First-party analytics |
| 6.11 Operational pattern | Implemented | Shared table, loading, empty, stale and error states |

## Sub-management screen backlog

| Area | Screen | Status |
| --- | --- | --- |
| Player protection | Safer play reviews | Implemented in current batch |
| Player protection | Limits | Implemented in current batch |
| Player protection | Exclusions | Implemented in current batch |
| Finance | Deposits | Implemented |
| Finance | Payouts | Implemented with payout queue and provider float sub-screens |
| Finance | Adjustments | Implemented |
| Game management | Configurations | Implemented with live and pending-change sub-screens |
| Game management | Rounds and results | Implemented |
| Content | Content releases | Implemented with release queue and live catalogue sub-screens |
| Insights | Daily summary | Implemented with current and previous-day comparison |
| Administration | Team access | Implemented with team member and invitation sub-screens |
| Administration | Roles and permissions | Implemented |

## Delivered structure

1. Player protection: reviews, limits and exclusions.
2. Finance: deposits, payouts and adjustments.
3. Game operations: configurations and rounds.
4. Administration: team access and roles.
5. Content releases and daily summary.

Each screen should contain one task, one compact filter row, one primary dataset and no decorative dashboard blocks.
