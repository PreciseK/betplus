# infra/systemd

Unit files for supervised long-running processes: Horizon queue workers, the Heritage
engine's Uvicorn worker manager, and any other process that must not run as cron
(`REQ-HOST-001`–`011`). Cron is reserved for genuinely scheduled work only.

## Horizon is not yet a composer dependency — here's why, and what to do about it

`laravel/horizon` requires the `pcntl` and `posix` PHP extensions for graceful worker
signal handling. Those extensions are Unix-only — they don't exist on Windows PHP builds
at all, no `.ini` change fixes it. This repo's local dev happened on Windows, so
`composer require laravel/horizon` was deliberately **not** run there: forcing it in with
`--ignore-platform-req` would add an unverifiable dependency to the committed
`composer.lock` that every future `composer install` — including any other Windows
contributor's — would have to resolve, without this sandbox ever being able to actually
run or test it.

Add it for real from a Linux/Mac dev machine or directly in CI/on the production host:

```bash
composer require laravel/horizon
php artisan horizon:install    # publishes config/horizon.php and the dashboard assets
```

Then, in `config/horizon.php`:
- Point every supervisor's `connection` at `redis` (`.env.example` already documents the
  four-line production cutover from the current `database` cache/session/queue driver —
  do that first; Horizon does not work with the `database` queue driver at all).
- All five jobs in `app/Jobs/` (`DispatchPrizePayoutJob`, `GenerateFinancialReportExportJob`,
  `PollCollectionStatusJob`, `PollPayoutStatusJob`, `SubmitSecondChanceEntryJob`) dispatch
  onto the implicit `default` queue today — none call `->onQueue(...)`. A single supervisor
  processing `queue: ['default']` is enough to match current behavior; splitting
  payment-critical jobs onto their own higher-priority queue is a real future improvement,
  but only after adding the matching `->onQueue('payouts')` calls at each dispatch site —
  don't invent a queue name in horizon.php that nothing actually dispatches onto.

`betplus-horizon.service` in this directory is a working systemd unit template — copy it,
fix `User`/`Group`/`WorkingDirectory` for the real host, `systemctl enable --now
betplus-horizon`.

**The Horizon dashboard (`/horizon`) is not exposed anywhere yet, and should stay that
way until it's deliberately secured.** Horizon's stock `HorizonServiceProvider::gate()`
checks `Auth::user()` against Laravel's normal web-session auth — this app has no such
thing; every back-office identity check here goes through
`App\Http\Middleware\EnsureInstitutionUser` and a bearer token resolved via
`InstitutionAuthService::resolveToken()`, not `Auth::user()`. Bridging the two (so only a
signed-in `system_admin` operator can view queue internals and job payloads, which can
contain player financial data) is real work — don't just flip Horizon's default
`app()->environment('local')` gate to `true` in production, that exposes it to anyone.

## No root/systemd access (shared cPanel hosting)

If the production host doesn't offer root or a systemd unit, `queue:work` still needs a
persistent process from *somewhere*. cPanel's Cron Jobs UI can approximate one:

```
* * * * * cd /home/betplus/apps/platform && php artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

This is not Horizon (no dashboard, no auto-balancing across queues, no per-job metrics)
but it's a real, working substitute for job execution — worlds better than a queue that
accumulates in the `jobs` table with nothing ever consuming it.
