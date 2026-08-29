-- =============================================================================
-- BlackRed Raffle - RECONSTRUCTED DDL for undocumented database objects
-- Version: 1.0 (reconstruction)
-- Database: amoamvfc_blackRedTSQL
-- Engine: MySQL 8.0+ / MariaDB 10.5+
-- Charset: utf8mb4
-- Reconstructed: 10 August 2026
--
-- PURPOSE
-- -------
-- migrations/001_initial_schema.sql and migrations/002_phase2_auth.sql (plus
-- ussd/migrations/008-009) do not contain CREATE statements for three
-- objects that application code reads and writes constantly:
--
--     gameRound              (src/Wallet/GameEngineService.php, ussd/lib/game.php,
--                              src/Wallet/StakesService.php, ussd/lib/player.php)
--     dailyRevenueSummary    (src/Wallet/GameEngineService.php, ussd/lib/game.php)
--     eventLog               (src/Auth/ForgotPasswordService.php,
--                              src/Auth/PasswordChangeService.php)
--
-- A fourth object named in the investigation brief, the view
-- vw_walletDualBalance, turned out NOT to be missing: it is already fully
-- defined in migrations/001_initial_schema.sql (lines 1079-1096) and its
-- columns match its only consumer (src/Http/Controllers/MeController.php)
-- exactly. It is reproduced verbatim below (section 4) for completeness of
-- this deliverable only — do not treat it as a "reconstruction".
--
-- CORROBORATING FINDING: ussd/migrations/008_ussd_channel.sql's header
-- comment says "Run order: after the web app's migrations 001-007", but
-- this repository only contains migrations/001 and migrations/002. Web
-- migrations 003-007 do not exist anywhere in the checked-in tree. This
-- strongly suggests gameRound, dailyRevenueSummary, and eventLog (and
-- possibly other undiscovered objects) were introduced in that missing
-- 003-007 range and simply never got committed. Treat "these are the only
-- four gaps" as unproven — there may be more.
--
-- METHOD
-- ------
-- Every column below is backed by at least one of:
--   (a) an INSERT/SELECT/UPDATE/WHERE/ORDER BY clause in application code
--       that names it explicitly, or
--   (b) the SQLite test-double schema in ussd/tests/run.php, which the USSD
--       author states was "verified against live SHOW COLUMNS" (see
--       ussd/lib/game.php:508 and the inline comment at line 409).
-- Evidence is cited per-column as `-- SOURCE: <file>:<line>`. Where (a) and
-- (b) disagree, or where a type/constraint could not be pinned down from
-- either, it is flagged inline and repeated in the UNCERTAIN section at the
-- bottom of this file.
--
-- Naming/style follows migrations/001_initial_schema.sql exactly: camelCase
-- columns, MySQL ENUM where the existing schema uses them, BIGINT for money
-- in pesewas (GHS minor unit), explicit index/constraint naming, InnoDB,
-- utf8mb4.
-- =============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
START TRANSACTION;

SET NAMES utf8mb4;

-- =============================================================================
-- 1. gameRound
-- =============================================================================
-- Per-round audit/history row for the "Stop & Reveal" game engine. One row
-- per stake, written inside the same DB transaction as the stake/win ledger
-- entries, by BOTH engines (web PHP and USSD PHP) so their outputs must be
-- byte-for-byte column-compatible. Read by StakesService (player-facing
-- history, filtered/paginated) and by both engines themselves (daily stake
-- cap check, "last stake" lookup on USSD).
--
-- Primary evidence:
--   src/Wallet/GameEngineService.php:96-107   (daily stake-cap SELECT)
--   src/Wallet/GameEngineService.php:305-339  (INSERT — full column list)
--   src/Wallet/StakesService.php:71-87        (player history SELECT)
--   ussd/lib/game.php:251-262                 (daily stake-cap SELECT, USSD)
--   ussd/lib/game.php:510-545                 (INSERT — full column list, USSD;
--                                               comment states "Column set
--                                               verified against live SHOW
--                                               COLUMNS FROM gameRound")
--   ussd/lib/player.php:161-173               ("last stake" SELECT)
--   ussd/tests/run.php:95-117                 (SQLite test-double schema —
--                                               nullability evidence)
--   ussd/tests/run.php:434-441, 2545-2561     (test seed/assert usage)
-- =============================================================================
CREATE TABLE `gameRound` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- 'STK-' + 12 lowercase hex chars = 16 chars observed. Sized like every
  -- other refNumber column in the schema (walletTransaction.refNumber,
  -- depositRequest.refNumber, etc. are all VARCHAR(64)).
  -- SOURCE: GameEngineService.php:200 ($refNumber = 'STK-' . substr(bin2hex(random_bytes(8)), 0, 12))
  -- SOURCE: ussd/lib/game.php:329 (identical construction)
  `refNumber`             VARCHAR(64)  NOT NULL,

  -- SOURCE: GameEngineService.php:96-107 (WHERE playerId = :pid, hot path)
  -- SOURCE: StakesService.php:76 (WHERE playerId = :pid)
  -- SOURCE: ussd/lib/player.php:167 (WHERE playerId = :pid)
  `playerId`              BIGINT UNSIGNED NOT NULL,

  -- 1..5, indexes GameEngineService::MULTIPLIERS / GAME_MULTIPLIERS.
  -- SOURCE: GameEngineService.php:321 (:gt => $gameType, int)
  -- SOURCE: ussd/tests/run.php:96 (gameType INTEGER NOT NULL)
  `gameType`              TINYINT UNSIGNED NOT NULL,

  -- Values 2,10,20,50,100 per MULTIPLIERS table. Same domain as
  -- gameEvent.multiplier (SMALLINT UNSIGNED) in 001_initial_schema.sql.
  -- SOURCE: GameEngineService.php:322 (:mult => $multiplier)
  `multiplier`            SMALLINT UNSIGNED NOT NULL,

  -- implode(',', ['red','black',...]) — spelled-out colour words, comma
  -- joined. NOT the compact 'B'/'R' encoding gameEvent.playerSelection uses.
  -- Worst case 5 picks of 'black' = "black,black,black,black,black" = 29 chars.
  -- SOURCE: GameEngineService.php:323 (implode(',', $colorPicks))
  -- SOURCE: StakesService.php:99 (explode(',', $r['colorPicks']) on read)
  -- SOURCE: ussd/tests/run.php:2554 (asserts 'red,black,red' round-trips)
  `colorPicks`            VARCHAR(50)  NOT NULL,

  -- SOURCE: GameEngineService.php:324, ussd/lib/game.php:417
  `stakePesewas`          BIGINT UNSIGNED NOT NULL,

  -- stake * multiplier. Always computed and passed by both engines despite
  -- the SQLite test-double declaring it nullable (see UNCERTAIN).
  -- SOURCE: GameEngineService.php:325, ussd/lib/game.php:418
  `potentialPayoutPesewas` BIGINT UNSIGNED DEFAULT NULL,

  -- Only 'fair_random' and 'forced_loss' are ever written by either engine.
  -- SOURCE: GameEngineService.php:172-181 ($enginePath assignment)
  -- SOURCE: GameEngineService.php:326, ussd/lib/game.php:419
  -- SOURCE: ussd/tests/run.php:2557 (asserts value is one of the two)
  `enginePath`            ENUM('fair_random','forced_loss') DEFAULT NULL,

  -- Integer percentage (systemConfig 'engine.houseWinThresholdPct', default
  -- 50 on web / 20 on USSD). Always a whole-number percent in app code.
  -- SOURCE: GameEngineService.php:159,327 ($thresholdPct = getInt(...))
  -- SOURCE: ussd/lib/game.php:232,420
  `thresholdPctAtTime`    TINYINT UNSIGNED DEFAULT NULL,

  -- netRevenueToday = lossesToday - winsToday. Docblock explicitly says
  -- "may be negative" — MUST be signed, unlike every other *Pesewas column
  -- in this schema.
  -- SOURCE: GameEngineService.php:163,328 ($netRevenueToday, can be < 0 — see
  --         line 180 "if ($netRevenueToday < 0)")
  -- SOURCE: ussd/lib/game.php:301,421
  `netRevenuePesewas`     BIGINT DEFAULT NULL,

  -- Cumulative wins-so-far-today snapshot at decision time. Always >= 0.
  -- SOURCE: GameEngineService.php:161,329, ussd/lib/game.php:298,422
  `winsTodayPesewas`      BIGINT UNSIGNED DEFAULT NULL,

  -- json_encode() of the 12-card locked deck.
  -- SOURCE: GameEngineService.php:330 (json_encode($lockedDeck))
  -- SOURCE: ussd/lib/game.php:423
  -- SOURCE: ussd/tests/run.php:2558 (asserts non-null after a real play)
  `lockedDeck`            JSON DEFAULT NULL,

  -- json_encode() of the n drawn cards (n = gameType).
  -- SOURCE: GameEngineService.php:331, ussd/lib/game.php:424
  -- SOURCE: StakesService.php:74,100 (parsed back out of JSON on read)
  `drawnCards`            JSON DEFAULT NULL,

  -- Only 'win' and 'loss' are ever written; unlike gameEvent.outcome
  -- (ENUM('pending','win','loss','void')) no 'pending'/'void' value is ever
  -- produced by either engine (the whole round is one atomic transaction).
  -- SOURCE: GameEngineService.php:196,332 ($outcome = $allMatch ? 'win' : 'loss')
  -- SOURCE: ussd/lib/game.php:326
  `outcome`               ENUM('win','loss') NOT NULL,

  -- 0 on loss, stake*multiplier on win. Always provided.
  -- SOURCE: GameEngineService.php:197,333, ussd/lib/game.php:327
  `payoutPesewas`         BIGINT UNSIGNED NOT NULL DEFAULT 0,

  -- FK to the STAKE walletTransaction. Always set by both engines on every
  -- round, but kept nullable (DEFAULT NULL) to match the ON DELETE SET NULL
  -- FK convention used by gamePlay.stakeTxnId / gamePlay.payoutTxnId in
  -- 001_initial_schema.sql.
  -- SOURCE: GameEngineService.php:205-218,334 ($stakeTxnId = $db->insert(...))
  -- SOURCE: ussd/lib/game.php:332-346,427
  `stakeWalletTxnId`      BIGINT UNSIGNED DEFAULT NULL,

  -- FK to the WIN_PAYOUT walletTransaction. NULL on loss.
  -- SOURCE: GameEngineService.php:240-259,335 ($winTxnId = null unless win)
  -- SOURCE: ussd/lib/game.php:363-380,428
  `winWalletTxnId`        BIGINT UNSIGNED DEFAULT NULL,

  -- SOURCE: GameEngineService.php:336, ussd/lib/game.php:429
  `clientIp`               VARCHAR(45)  DEFAULT NULL,

  -- Only ever populated by the WEB engine (substr($userAgent, 0, 500)).
  -- The USSD engine's INSERT column list omits userAgent entirely (see
  -- ussd/lib/game.php:513-518) — there is no browser User-Agent on a USSD
  -- session — so this column is always NULL for USSD-originated rounds.
  -- SOURCE: GameEngineService.php:337 (substr($userAgent, 0, 500))
  -- SOURCE: ussd/lib/game.php:513-518 (column absent from USSD INSERT)
  `userAgent`             VARCHAR(500) DEFAULT NULL,

  -- SOURCE: GameEngineService.php:317 (NOW()), ussd/lib/game.php:524 (NOW())
  `createdAt`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- UNIQUE inferred from schema-wide convention (every refNumber column in
  -- 001_initial_schema.sql is UNIQUE) — not directly proven; see UNCERTAIN.
  UNIQUE KEY `uniq_gameRound_ref` (`refNumber`),

  -- Composite index required by TWO hot-path queries that run on every
  -- single play, in both engines: the daily-stake-cap SELECT
  -- (WHERE playerId = ? AND createdAt >= ? AND createdAt < ?) and
  -- StakesService's history feed (same WHERE shape). Column order
  -- (playerId, createdAt) matches the equality-then-range access pattern.
  KEY `idx_gameRound_player_created` (`playerId`, `createdAt`),

  -- Supports ussd/lib/player.php's "last stake" lookup
  -- (WHERE playerId = ? ORDER BY id DESC LIMIT 1) — covered by the composite
  -- index above too, but id-ordering makes a plain playerId index cheaper
  -- for this specific query shape; kept for FK-support regardless (see
  -- UNCERTAIN — not a directly observed requirement, just InnoDB FK need).
  KEY `idx_gameRound_stakeTxn` (`stakeWalletTxnId`),
  KEY `idx_gameRound_winTxn` (`winWalletTxnId`),

  CONSTRAINT `fk_gameRound_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_gameRound_stakeTxn`
    FOREIGN KEY (`stakeWalletTxnId`) REFERENCES `walletTransaction`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gameRound_winTxn`
    FOREIGN KEY (`winWalletTxnId`) REFERENCES `walletTransaction`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='RECONSTRUCTED. Per-round game engine audit row. One per stake. Written by both web and USSD engines inside the stake transaction.';

-- =============================================================================
-- 2. dailyRevenueSummary
-- =============================================================================
-- One row per calendar business-day (Africa/Accra). Tracks the running
-- lossesPesewas/winsPesewas totals that the "house win threshold" decision
-- in both engines depends on. Read-modify-write with SELECT ... FOR UPDATE
-- inside the SAME transaction as every single stake — see design-problem
-- note in the accompanying summary.
--
-- Primary evidence:
--   src/Wallet/GameEngineService.php:540-569 (getOrCreateDailySummary — SELECT,
--                                              INSERT, and both UPDATE forms)
--   src/Wallet/GameEngineService.php:284-302 (UPDATE ... winsPesewas/lossesPesewas)
--   ussd/lib/game.php:484-502                (gameGetOrCreateDailySummary — mirrors web)
--   ussd/lib/game.php:393-406                (UPDATE ... winsPesewas/lossesPesewas)
--   ussd/tests/run.php:118-127                (SQLite test-double schema —
--                                               confirms businessDate UNIQUE NOT NULL)
--   ussd/tests/run.php:1574-1583, 2523-2541   (test seed/assert usage)
-- =============================================================================
CREATE TABLE `dailyRevenueSummary` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Business date in Africa/Accra, format 'Y-m-d'. Matches DATE columns
  -- (revenueDaily.dateDraw, winAnalysisDaily.dateDraw) already in the schema.
  -- SOURCE: GameEngineService.php:530-533 (businessDate(), Africa/Accra 'Y-m-d')
  -- SOURCE: ussd/tests/run.php:120 (businessDate TEXT UNIQUE NOT NULL)
  `businessDate`      DATE NOT NULL,

  -- Snapshot of systemConfig 'engine.dailyRevenueFloorPesewas' (default
  -- 50000) at the moment the row is first created for that day, so later
  -- config changes don't retroactively alter past decisions (explicit in
  -- the docblock).
  -- SOURCE: GameEngineService.php:551-559 (getOrCreateDailySummary insert)
  -- SOURCE: ussd/lib/game.php:494-498
  `floorPesewas`      BIGINT UNSIGNED NOT NULL,

  -- Sum of stakePesewas for every LOSING round today. Only ever incremented.
  -- SOURCE: GameEngineService.php:294-301 (lossesPesewas = lossesPesewas + :s)
  -- SOURCE: ussd/lib/game.php:400-405
  `lossesPesewas`     BIGINT UNSIGNED NOT NULL DEFAULT 0,

  -- Sum of payoutPesewas for every WINNING round today. Only ever incremented.
  -- SOURCE: GameEngineService.php:284-291 (winsPesewas = winsPesewas + :p)
  -- SOURCE: ussd/lib/game.php:394-399
  `winsPesewas`       BIGINT UNSIGNED NOT NULL DEFAULT 0,

  -- Count of rounds today (win + loss). Only ever incremented by 1.
  -- SOURCE: GameEngineService.php:288,297 (roundsCount = roundsCount + 1)
  -- SOURCE: ussd/lib/game.php:397,403
  `roundsCount`       INT UNSIGNED NOT NULL DEFAULT 0,

  -- SOURCE: GameEngineService.php:557 (NOW()), ussd/lib/game.php:497
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- SOURCE: GameEngineService.php:289,298 (updatedAt = NOW() on every UPDATE)
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Confirmed by the SQLite test-double ("businessDate TEXT UNIQUE NOT NULL")
  -- and logically required by the get-or-create pattern in both engines
  -- (SELECT ... FOR UPDATE; if not found, INSERT) to prevent a duplicate row
  -- for the same day under concurrent first-play-of-the-day races. Also
  -- matches the sibling convention: revenueDaily.uniq_revenue_date,
  -- winAnalysisDaily.uniq_winAnalysis_date_cards.
  UNIQUE KEY `uniq_dailyRevenueSummary_date` (`businessDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='RECONSTRUCTED. One row per business day (Africa/Accra). Locked FOR UPDATE by every game round on both engines — see design-problem note.';

-- =============================================================================
-- 3. eventLog
-- =============================================================================
-- Player-facing security/audit event log — the player-side counterpart to
-- institutionUser-facing auditLog in 001_initial_schema.sql. Currently only
-- ever written by two web-only password flows. No SELECT/read of this table
-- was found anywhere in src/ or ussd/ — it appears to be write-only from
-- this codebase's perspective (an admin/forensics surface likely reads it
-- outside this repo, or it isn't built yet).
--
-- Primary evidence:
--   src/Auth/ForgotPasswordService.php:186-199 (INSERT — 'password_reset_via_ussd')
--   src/Auth/PasswordChangeService.php:110-120 (INSERT — 'password_changed')
-- No corroborating test-double schema exists for this table (absent from
-- ussd/tests/run.php's fixture, consistent with it being web-only).
-- =============================================================================
CREATE TABLE `eventLog` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Both observed call sites always pass a real, authenticated playerId.
  -- SOURCE: ForgotPasswordService.php:190 ('pid' => $playerId)
  -- SOURCE: PasswordChangeService.php:114 ('pid' => $playerId)
  `playerId`          BIGINT UNSIGNED NOT NULL,

  -- Free-text event code, not an ENUM — matches the sibling auditLog.action
  -- column's style (VARCHAR(100), "e.g. KYC_APPROVE, ..."), not a closed
  -- domain. Only two values observed in this codebase:
  --   'password_reset_via_ussd', 'password_changed'
  -- SOURCE: ForgotPasswordService.php:191, PasswordChangeService.php:115
  `eventType`         VARCHAR(100) NOT NULL,

  -- SOURCE: ForgotPasswordService.php:192, PasswordChangeService.php:116
  `ipAddress`         VARCHAR(45)  DEFAULT NULL,

  -- NOTE: both call sites truncate with substr($userAgent, 0, 512) — 512,
  -- not the 500 used everywhere else in the schema (playerSession.userAgent,
  -- auditLog.userAgent are both VARCHAR(500)). Modelled here at 500 to match
  -- schema-wide convention; see design-problem note — this may be a real
  -- app/schema mismatch worth checking directly against production.
  -- SOURCE: ForgotPasswordService.php:193 (substr($userAgent, 0, 512))
  -- SOURCE: PasswordChangeService.php:117 (substr($userAgent, 0, 512))
  `userAgent`         VARCHAR(500) DEFAULT NULL,

  -- json_encode() of a small free-form array (e.g. {method, sessions_terminated}).
  -- SOURCE: ForgotPasswordService.php:194-197, PasswordChangeService.php:118
  `metadata`          JSON DEFAULT NULL,

  -- SOURCE: ForgotPasswordService.php:188 (NOW()), PasswordChangeService.php:112
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Indexes inferred entirely from the sibling auditLog table's convention
  -- (idx_audit_actor, idx_audit_action, idx_audit_createdAt) — no read query
  -- against eventLog exists anywhere in the codebase to confirm access
  -- patterns directly.
  KEY `idx_eventLog_player` (`playerId`),
  KEY `idx_eventLog_type` (`eventType`),
  KEY `idx_eventLog_createdAt` (`createdAt`),

  CONSTRAINT `fk_eventLog_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='RECONSTRUCTED. Player-facing security/audit event log. Currently write-only from this codebase (password reset/change flows).';

-- =============================================================================
-- 4. vw_walletDualBalance — NOT ACTUALLY MISSING
-- =============================================================================
-- Reproduced VERBATIM from migrations/001_initial_schema.sql:1079-1096 for
-- completeness of this deliverable only. This view already has a CREATE
-- statement in the checked-in migrations and its columns match its only
-- consumer, src/Http/Controllers/MeController.php:53-60, exactly
-- (playBalancePesewas, payoutBalancePesewas, totalBalancePesewas,
-- maxPlayWithdrawablePesewas, maxPayoutWithdrawablePesewas,
-- maxTotalWithdrawablePesewas, playVersion, payoutVersion). No
-- reconstruction was necessary or performed here — do not apply this
-- statement if migrations/001_initial_schema.sql has already been applied,
-- it is purely informational (CREATE OR REPLACE is idempotent regardless).
-- =============================================================================
CREATE OR REPLACE VIEW `vw_walletDualBalance` AS
SELECT
  p.id                                                  AS playerId,
  p.msisdn                                              AS msisdn,
  COALESCE(wp.cachedBalancePesewas, 0)                  AS playBalancePesewas,
  COALESCE(wo.cachedBalancePesewas, 0)                  AS payoutBalancePesewas,
  COALESCE(wp.cachedBalancePesewas, 0)
    + COALESCE(wo.cachedBalancePesewas, 0)              AS totalBalancePesewas,
  FLOOR(COALESCE(wp.cachedBalancePesewas, 0) / 2)       AS maxPlayWithdrawablePesewas,
  COALESCE(wo.cachedBalancePesewas, 0)                  AS maxPayoutWithdrawablePesewas,
  FLOOR(COALESCE(wp.cachedBalancePesewas, 0) / 2)
    + COALESCE(wo.cachedBalancePesewas, 0)              AS maxTotalWithdrawablePesewas,
  COALESCE(wp.version, 0)                               AS playVersion,
  COALESCE(wo.version, 0)                               AS payoutVersion
FROM player p
LEFT JOIN wallet wp ON wp.playerId = p.id AND wp.walletType = 'PLAY'
LEFT JOIN wallet wo ON wo.playerId = p.id AND wo.walletType = 'PAYOUT'
WHERE p.deletedAt IS NULL;

COMMIT;

-- =============================================================================
-- UNCERTAIN
-- =============================================================================
-- Everything in this section is a guess, a convention-based inference, or an
-- access pattern that could only be partially confirmed. None of it should
-- be trusted without validating against the live production schema
-- (SHOW CREATE TABLE / SHOW COLUMNS FROM ...).
--
-- gameRound
-- ---------
-- 1. UNIQUE KEY uniq_gameRound_ref (refNumber): inferred purely from the
--    schema-wide convention that every refNumber column elsewhere is
--    UNIQUE. No duplicate-refNumber check or collision-handling code was
--    found for gameRound specifically (unlike, say, depositRequest's
--    idempotency-key framing). If production omits this constraint, a
--    refNumber collision (statistically ~1 in 16^12, but not impossible at
--    scale) would silently insert a second row rather than fail loudly.
-- 2. outcome ENUM('win','loss'): only these two values are ever written by
--    either engine (the round is always fully resolved in one transaction).
--    Production may use a wider ENUM matching gameEvent.outcome
--    ('pending','win','loss','void') for schema consistency, or may use
--    VARCHAR instead of ENUM. If production has a third value in use
--    (e.g. from a manual admin correction path not in this codebase), this
--    reconstruction's narrower ENUM would reject it.
-- 3. enginePath ENUM('fair_random','forced_loss'): same caveat — only two
--    values observed, but this reconstruction cannot rule out a third
--    (e.g. 'admin_override') existing in production and used by tooling
--    outside this repo.
-- 4. thresholdPctAtTime type: modelled as TINYINT UNSIGNED because the
--    application always stores whole-number percentages (0-100). The
--    ussd/tests/run.php SQLite test-double instead types this column REAL
--    (float-capable) — worth double-checking; if production genuinely
--    allows fractional percentages this reconstruction's type is wrong.
-- 5. colorPicks VARCHAR(50): sized off the worst-case comma-joined string
--    for 5 picks of "black" (29 chars) with headroom. The actual column
--    width in production was never directly observed.
-- 6. refNumber VARCHAR(64): sized by convention match with
--    walletTransaction.refNumber / depositRequest.refNumber, not proven
--    for gameRound specifically (observed values are only ever 16 chars).
-- 7. No channel/playMedium column was found in either engine's INSERT
--    column list, despite gamePlay (the equivalent table in
--    001_initial_schema.sql) having playMedium ENUM('web','app','ussd').
--    Either gameRound genuinely has no such column in production, or it
--    exists and neither engine populates it (a real gap, if so). Cannot
--    distinguish between these from the code alone.
-- 8. idx_gameRound_stakeTxn / idx_gameRound_winTxn: added only because
--    InnoDB requires an index to support a foreign key; no query in the
--    codebase actually filters gameRound by stakeWalletTxnId or
--    winWalletTxnId. Production may or may not have these FKs at all — the
--    columns could simply be unconstrained BIGINTs.
-- 9. FK ON DELETE behaviour (RESTRICT for playerId, SET NULL for the two
--    walletTxnId columns): inferred by matching the closest analog table,
--    gamePlay, in 001_initial_schema.sql. Not independently confirmed.
--
-- dailyRevenueSummary
-- --------------------
-- 10. UNSIGNED on floorPesewas/lossesPesewas/winsPesewas: the application
--     only ever adds positive amounts to these columns, so UNSIGNED fits
--     observed behaviour, but if production ever needs a manual downward
--     correction (e.g. reversing a miscounted round) via direct UPDATE,
--     UNSIGNED would reject a negative delta unless done as a full
--     re-statement rather than a subtraction. No such correction path was
--     found in the code either way.
-- 11. The UNIQUE constraint on businessDate is corroborated by the test
--     fixture, which raises confidence, but a SQLite test double authored
--     for local unit tests is not the same evidentiary weight as
--     `SHOW CREATE TABLE` against production — flagging it here rather
--     than silently trusting it.
--
-- eventLog
-- --------
-- 12. playerId NOT NULL: both observed call sites always pass a concrete
--     playerId. Sibling audit-adjacent tables (smsLog.playerId,
--     auditLog.actorId) are nullable to allow system-initiated rows with no
--     player. If eventLog is ever used for a system/anonymous security
--     event (e.g. a failed-login-with-unknown-phone-number attempt) outside
--     the two flows seen here, production's playerId is very likely
--     nullable and this reconstruction's NOT NULL would be wrong.
-- 13. userAgent VARCHAR(500) vs the app's substr(...,0,512): flagged as a
--     probable real inconsistency (see design-problem summary) rather than
--     a confirmed fact. Production could equally be VARCHAR(512) or larger,
--     in which case there is no bug — only this reconstruction's guess.
-- 14. metadata JSON: inferred from schema-wide convention (every other
--     json_encode()-fed column in the codebase is typed JSON, never TEXT).
--     Not directly provable from a write-only call site.
-- 15. All three secondary indexes (idx_eventLog_player/type/createdAt) are
--     pattern-matched from auditLog's indexing, not from any observed
--     SELECT against eventLog — there are none in this codebase.
-- 16. Whether eventLog has additional columns entirely absent from both
--     observed call sites (e.g. a targetTable/targetId pair like auditLog
--     has, or a channel column) cannot be ruled out. Only 2 call sites
--     exist in the whole codebase, both with identical column sets — a
--     small evidence base for a 6-column table with a fairly narrow
--     documented purpose.
--
-- General
-- -------
-- 17. Web migrations 003-007 are referenced by
--     ussd/migrations/008_ussd_channel.sql's header comment but do not
--     exist in this repository. All three reconstructed tables (and
--     possibly other undiscovered objects) were most likely introduced in
--     that missing range. This file only covers the three gaps the
--     investigation brief named as confirmed by grep; it does not claim to
--     be a complete inventory of every undocumented object in production.
-- =============================================================================
