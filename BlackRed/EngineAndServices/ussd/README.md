# BlackRed USSD

Slim, flat-PHP USSD app for the BlackRed Raffle, served via Nalo Solutions on `*920*9#`. Shares the BlackRed web app's database; otherwise independent.

## What's in here (PR 1)

This is the **PR 1 foundation**. The full state machine (registration, play, deposit, withdraw, balance, last stake) lands in PRs 2-7. Right now what's wired up is:

- Nalo webhook receiver at `index.php`
- MSISDN normalization (Nalo's `233xxxxxxxxx` → BlackRed's `0xxxxxxxxx`)
- DB-backed session storage (one row per dial)
- Transaction-safe FSM dispatcher
- A placeholder state that returns a single-screen "USSD coming soon"
- ANM callback endpoint at `/callbacks/momo.php` (stub — full settlement in PR 5)
- Cleanup cron for expired sessions
- Flat-file logging of every inbound + outbound

If you dial in right now, you get back: "Welcome to BlackRed. USSD coming soon. Phone: 0XXXXXXXXX" and the session ends. That's expected — it proves the wire is hot.

## Stack

- PHP 8.1+
- MariaDB / MySQL — shared with the web app
- No Composer dependencies. No framework. No autoloader.
- Apache/LiteSpeed (cPanel default)

## Layout

```
.
├── index.php               # Single entrypoint (Nalo USSD webhook)
├── config.php              # Copy from config.example.php; gitignored
├── config.example.php      # Template
├── .htaccess               # Deny direct access to lib/, states/, etc.
├── callbacks/
│   └── momo.php            # ANM callback endpoint (PR 1 stub; PR 5 fills settlement)
├── lib/
│   ├── db.php              # PDO singleton + dbTxn() helper
│   ├── log.php             # ussdLog() → logs/ussd.log
│   ├── msisdn.php          # normalizeMsisdn()
│   ├── response.php        # respStay/respNext/respEnd + Nalo serializer
│   ├── session.php         # ussdSession CRUD
│   └── states.php          # State name → file/function dispatch
├── states/
│   └── PlaceholderState.php   # PR 1; removed in PR 2
├── workers/
│   └── cleanup.php         # Cron: purge expired sessions
├── migrations/
│   └── 008_ussd_channel.sql
├── tests/
│   └── run.php             # `php tests/run.php`
└── logs/
    └── ussd.log            # Created at runtime
```

## Install on dev

The USSD app lives at `pncgw.engboxx.com/ussd/` for dev — same host as the BlackRed web app, separate folder. Steps:

1. **Upload the contents of this folder** to `/home/amoamvfc/ussd/`.

   File Manager: drag-drop the unzipped folder, then make sure the structure is `/home/amoamvfc/ussd/index.php` (not `/home/amoamvfc/ussd/blackred-ussd/index.php`).

2. **Configure it**:
   ```bash
   cd /home/amoamvfc/ussd
   cp config.example.php config.php
   chmod 600 config.php
   nano config.php
   ```

   At minimum, set `db.pass` to your real DB password. The other CHANGE_ME values (ANM, Hubtel) are needed for PR 3+ but not PR 1.

3. **Run the migration** against the shared web DB:
   ```bash
   mysql -u amoamvfc_brCoreUser -p amoamvfc_br_mainDB < migrations/008_ussd_channel.sql
   ```

   Or paste the SQL into phpMyAdmin. The migration is idempotent — re-running is a no-op.

4. **Set up the cleanup cron** (cPanel → Cron Jobs):
   ```
   */5 * * * * /usr/local/bin/php /home/amoamvfc/ussd/workers/cleanup.php
   ```

5. **Point Nalo at the USSD endpoint**:
   ```
   https://pncgw.engboxx.com/ussd/index.php
   ```

6. **Point ANM at the callback endpoint** (per-request via API, not configured at service level):
   ```
   https://pncgw.engboxx.com/ussd/callbacks/momo.php
   ```
   This URL is in `config.php` under `anm.callback_url`. The USSD app will pass it in every deposit/withdraw API call to ANM (PR 5).

7. **Make sure logs/ is writable** by the web server user:
   ```bash
   chmod 755 logs
   ```

## Smoke test

After install, run these from any terminal:

```bash
# Test 1: MTN-style first turn (no hash)
curl -X POST 'https://pncgw.engboxx.com/ussd/index.php' \
  -H 'Content-Type: application/json' \
  -d '{"SESSIONID":"smoke-mtn","USERID":"BlackRedApp","USERDATA":"*920*9","MSGTYPE":true,"MSISDN":"233244000000","NETWORK":"MTN"}'

# Test 2: Telecel-style first turn (with hash)
curl -X POST 'https://pncgw.engboxx.com/ussd/index.php' \
  -H 'Content-Type: application/json' \
  -d '{"SESSIONID":"smoke-tel","USERID":"BlackRedApp","USERDATA":"*920*9#","MSGTYPE":true,"MSISDN":"233203000000","NETWORK":"TELECEL"}'

# Test 3: ANM callback stub (PR 5 will make this functional)
curl -X POST 'https://pncgw.engboxx.com/ussd/callbacks/momo.php' \
  -H 'Content-Type: application/json' \
  -d '{"trans_ref":"abc123def456","trans_status":"000/200"}'
```

Expected response:

```json
{"USERID":"BlackRedApp","MSISDN":"0244000000","MSGTYPE":false,"MSG":"Welcome to BlackRed.\nUSSD coming soon.\nPhone: 0244000000"}
```

Verify the session row landed:

```sql
SELECT * FROM ussdSession ORDER BY id DESC LIMIT 3;
```

And the log:

```bash
tail logs/ussd.log
```

## Testing

```bash
php tests/run.php
```

18 tests cover MSISDN normalization, response builders, session CRUD, state dispatch, and an end-to-end first-turn simulation. The runner uses in-memory SQLite (no live DB needed).

## Logging

Every webhook turn writes two lines (inbound + outbound) to `logs/ussd.log`:

```
2026-05-11 13:22:15 | INBOUND | {"body":"{\"SESSIONID\":\"...\",...}"}
2026-05-11 13:22:15 | OUTBOUND | {"body":"{\"USERID\":\"...\",...}"}
```

For longer-term observability, rotate via OS logrotate or a weekly cron that gzips and archives.

## Operations

- **Database**: `ussdSession` is the only USSD-owned table. The session row's `data` JSON column holds whatever accumulated state the user has built up (game type, picks, stake, etc.). Cleanup runs every 5 min; rows older than 7 min are removed.

- **Errors**: Any thrown exception in the FSM is caught at the top level. In production, the user sees "Service temporarily unavailable." In `env: development`, the user sees the raw error message — helpful for smoke testing.

- **Concurrency**: Each turn runs inside a `SELECT ... FOR UPDATE` transaction on the session row. If Nalo retries a turn while we're still processing the original, the retry waits for the first to commit.

## Coming up

| PR | Adds |
|----|------|
| **PR 1 (this)** | Foundation: entry, session DB, placeholder state, tests |
| PR 2 | EntryState, registered-vs-unregistered branch, balance + last stake (reads) |
| PR 3 | Registration sub-flow (ANM name lookup) |
| PR 4 | Play sub-flow (copy of GameEngineService logic, password-as-confirmation) |
| PR 5 | Deposit sub-flow (async ANM trigger + Hubtel SMS) |
| PR 6 | Withdraw sub-flow (MoMo + internal transfer) |
| PR 7 | Polish: structured logging, observability, hardening |
