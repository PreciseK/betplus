-- =============================================================================
-- BlackRed Raffle - Production Database Schema
-- Version: 2.1
-- Database: amoamvfc_blackRedTSQL
-- Engine: MySQL 8.0+ / MariaDB 10.5+
-- Charset: utf8mb4
-- Date: 25 April 2026
--
-- Changelog v2.0 -> v2.1 (post UI audit):
--   * Provider codes aligned with UI: MTN | ATL | TEL (Vodafone -> Telecel)
--     Renamed MOMO_FLOAT_VOD -> MOMO_FLOAT_TEL
--   * Added wallet.version column for optimistic concurrency control
--   * Added gameEvent.playerSelectionPayload + drawDisplayPayload (JSON)
--     so the UI can render the actual drawn cards (suits + ranks)
--   * Added 'ussd_momo' to kycRecord.verificationMethod enum and a
--     ussdAuthCode/ussdAuthSessionId pair to capture the *920*9*1# flow
--   * Added systemConfig table for tunable limits (deposit min/max, etc.)
--   * Added passwordResetRequest table
--   * Added marketingEvent + playerEventRegistration tables for the
--     dashboard "Events & Shows" CMS feature
--   * Added a vw_walletDualBalance view tailored to the new dual-balance UI
--
-- Design principles:
--   1. Money is stored in PESEWAS (BIGINT). GHS 2.50 = 250 pesewas.
--      Never use FLOAT or DECIMAL for money in this schema.
--   2. Double-entry ledger. Wallet balances are DERIVED, not stored.
--      Every money movement = 2 ledger entries (debit + credit) under one txn.
--   3. Soft deletes via deleted_at TIMESTAMP NULL.
--   4. All timestamps stored as DATETIME (UTC at app layer).
--   5. Naming: camelCase to match existing codebase conventions.
--   6. Idempotency: every external-facing transaction has a unique reference.
--   7. Optimistic concurrency on wallets via version column.
-- =============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
START TRANSACTION;

SET NAMES utf8mb4;

-- Drop in reverse dependency order if rebuilding
-- (Commented out for safety. Uncomment for clean rebuild.)
-- DROP TABLE IF EXISTS `smsLog`;
-- DROP TABLE IF EXISTS `momoApiCallLog`;
-- DROP TABLE IF EXISTS `nameLookupCache`;
-- DROP TABLE IF EXISTS `suspiciousActivityFlag`;
-- DROP TABLE IF EXISTS `withdrawalRequest`;
-- DROP TABLE IF EXISTS `depositRequest`;
-- DROP TABLE IF EXISTS `gamePayout`;
-- DROP TABLE IF EXISTS `gamePlay`;
-- DROP TABLE IF EXISTS `drawResult`;
-- DROP TABLE IF EXISTS `gameEvent`;
-- DROP TABLE IF EXISTS `ledgerEntry`;
-- DROP TABLE IF EXISTS `walletTransaction`;
-- DROP TABLE IF EXISTS `wallet`;
-- DROP TABLE IF EXISTS `account`;
-- DROP TABLE IF EXISTS `kycRecord`;
-- DROP TABLE IF EXISTS `playerSession`;
-- DROP TABLE IF EXISTS `player`;
-- DROP TABLE IF EXISTS `institutionUser`;
-- DROP TABLE IF EXISTS `institutionRole`;

-- =============================================================================
-- 1. IDENTITY LAYER
-- =============================================================================

-- -----------------------------------------------------------------------------
-- player
-- -----------------------------------------------------------------------------
-- The natural person who plays the BlackRed Raffle.
-- One player = one MoMo number (enforced via UNIQUE on msisdn).
-- KYC details live in kycRecord; this table holds identity essentials only.
-- -----------------------------------------------------------------------------
CREATE TABLE `player` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `msisdn`            VARCHAR(15)  NOT NULL COMMENT 'E.164-compatible MoMo number, no leading +',
  `paymentProvider`   VARCHAR(10)  NOT NULL COMMENT 'MTN | ATL (AirtelTigo) | VOD',
  `registeredName`    VARCHAR(150) NOT NULL COMMENT 'Name as returned by MoMo name lookup at registration',
  `displayName`       VARCHAR(100) DEFAULT NULL COMMENT 'Optional player-chosen display name',
  `email`             VARCHAR(150) DEFAULT NULL,
  `passwordHash`      VARCHAR(255) DEFAULT NULL COMMENT 'NULL for USSD-only players',
  `pinHash`           VARCHAR(255) DEFAULT NULL COMMENT 'Optional 4-digit transaction PIN',
  `kycStatus`         ENUM('pending','verified','rejected','suspended') NOT NULL DEFAULT 'pending',
  `accountStatus`     ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
  `registrationChannel` ENUM('web','app','ussd') NOT NULL,
  `lastLoginAt`       DATETIME DEFAULT NULL,
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deletedAt`         DATETIME DEFAULT NULL COMMENT 'Soft delete marker; preserved 7 yrs per AML policy',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_player_msisdn` (`msisdn`),
  KEY `idx_player_kycStatus` (`kycStatus`),
  KEY `idx_player_accountStatus` (`accountStatus`),
  KEY `idx_player_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered BlackRed Raffle players. One row per natural person.';

-- -----------------------------------------------------------------------------
-- kycRecord
-- -----------------------------------------------------------------------------
-- Detailed KYC verification record per player. A player may have multiple
-- records over time (re-verification, document refresh). Latest = highest id
-- with verifiedAt NOT NULL.
-- -----------------------------------------------------------------------------
CREATE TABLE `kycRecord` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `idType`            ENUM('ghana_card','passport','voter_id','drivers_license') NOT NULL,
  `idNumber`          VARCHAR(50)  NOT NULL,
  `firstName`         VARCHAR(100) NOT NULL,
  `lastName`          VARCHAR(100) NOT NULL,
  `dateOfBirth`       DATE NOT NULL,
  `gender`            ENUM('male','female','other') DEFAULT NULL,
  `momoNameMatch`     ENUM('exact','partial','mismatch','not_checked') NOT NULL DEFAULT 'not_checked',
  `momoLookupRaw`     JSON DEFAULT NULL COMMENT 'Raw response from name lookup API',
  `verificationMethod` ENUM('automated','manual','hybrid','ussd_momo') NOT NULL,
  `ussdAuthCode`      VARCHAR(20)  DEFAULT NULL COMMENT 'Auth code shown to player after USSD dial (e.g. 6-digit OTP)',
  `ussdAuthSessionId` VARCHAR(100) DEFAULT NULL COMMENT 'Telco-side USSD session reference',
  `ussdDialedCode`    VARCHAR(50)  DEFAULT NULL COMMENT 'The USSD shortcode the player dialled (e.g. *920*9*1#)',
  `verifiedAt`        DATETIME DEFAULT NULL,
  `verifiedBy`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'institutionUser.id if manual',
  `rejectionReason`   VARCHAR(500) DEFAULT NULL,
  `documentRef`       VARCHAR(255) DEFAULT NULL COMMENT 'Storage reference for ID image',
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kyc_player` (`playerId`),
  KEY `idx_kyc_idNumber` (`idNumber`),
  KEY `idx_kyc_verifiedAt` (`verifiedAt`),
  CONSTRAINT `fk_kyc_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='KYC verification records. Supports re-verification history.';

-- -----------------------------------------------------------------------------
-- institutionRole
-- -----------------------------------------------------------------------------
-- Roles for BlackRed staff (admins, compliance, draw operators, finance).
-- Replaces the integer/string role_id soup in the POC.
-- -----------------------------------------------------------------------------
CREATE TABLE `institutionRole` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `roleCode`          VARCHAR(50)  NOT NULL COMMENT 'e.g. SUPER_ADMIN, COMPLIANCE, DRAW_OP, FINANCE, SUPPORT',
  `roleName`          VARCHAR(100) NOT NULL,
  `description`       VARCHAR(500) DEFAULT NULL,
  `permissions`       JSON DEFAULT NULL COMMENT 'Array of permission strings',
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_code` (`roleCode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='RBAC roles for BlackRed institutional/staff users.';

-- -----------------------------------------------------------------------------
-- institutionUser
-- -----------------------------------------------------------------------------
-- BlackRed staff: admins, compliance officers, draw operators, finance.
-- Consolidates the POC's institution_users + institution_draw_users.
-- -----------------------------------------------------------------------------
CREATE TABLE `institutionUser` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`          VARCHAR(50)  NOT NULL,
  `fullName`          VARCHAR(150) NOT NULL,
  `email`             VARCHAR(150) DEFAULT NULL,
  `phoneNumber`       VARCHAR(20)  DEFAULT NULL,
  `passwordHash`      VARCHAR(255) NOT NULL,
  `roleId`            INT UNSIGNED NOT NULL,
  `gender`            ENUM('male','female','other') DEFAULT NULL,
  `mfaSecret`         VARCHAR(255) DEFAULT NULL COMMENT 'TOTP secret for 2FA',
  `mfaEnabled`        TINYINT(1) NOT NULL DEFAULT 0,
  `lastLoginAt`       DATETIME DEFAULT NULL,
  `lastLoginIp`       VARCHAR(45) DEFAULT NULL,
  `failedLoginCount`  INT UNSIGNED NOT NULL DEFAULT 0,
  `lockedUntil`       DATETIME DEFAULT NULL,
  `status`            ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `createdBy`         BIGINT UNSIGNED DEFAULT NULL,
  `updatedBy`         BIGINT UNSIGNED DEFAULT NULL,
  `deletedAt`         DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inst_username` (`username`),
  KEY `idx_inst_role` (`roleId`),
  KEY `idx_inst_status` (`status`),
  CONSTRAINT `fk_inst_role`
    FOREIGN KEY (`roleId`) REFERENCES `institutionRole`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='BlackRed staff users (admins, compliance, ops, finance).';

-- -----------------------------------------------------------------------------
-- playerSession
-- -----------------------------------------------------------------------------
-- Active and historical login/USSD sessions for players.
-- Replaces the POC `session` table; expanded for web/app/USSD.
-- -----------------------------------------------------------------------------
CREATE TABLE `playerSession` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playerId`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL until player identified',
  `msisdn`            VARCHAR(15)  NOT NULL,
  `sessionToken`      VARCHAR(128) NOT NULL,
  `channel`           ENUM('web','app','ussd') NOT NULL,
  `ussdSessionId`     VARCHAR(100) DEFAULT NULL,
  `ipAddress`         VARCHAR(45)  DEFAULT NULL,
  `userAgent`         VARCHAR(500) DEFAULT NULL,
  `state`             VARCHAR(100) DEFAULT NULL COMMENT 'USSD flow state machine position',
  `selection`         VARCHAR(5)   DEFAULT NULL COMMENT 'In-flight prediction (e.g. BRRBB)',
  `stakeAmountPesewas` BIGINT UNSIGNED DEFAULT NULL COMMENT 'In-flight stake',
  `expiresAt`         DATETIME NOT NULL,
  `terminatedAt`      DATETIME DEFAULT NULL,
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_session_token` (`sessionToken`),
  KEY `idx_session_player` (`playerId`),
  KEY `idx_session_msisdn` (`msisdn`),
  KEY `idx_session_ussd` (`ussdSessionId`),
  KEY `idx_session_expires` (`expiresAt`),
  CONSTRAINT `fk_session_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Player login and USSD sessions, including in-flight game state.';

-- -----------------------------------------------------------------------------
-- passwordResetRequest
-- -----------------------------------------------------------------------------
-- Captures every password reset request (player-initiated forgot-password flow).
-- Tokens are HASHED at rest. Successful and unsuccessful uses are both retained
-- for audit. Expired or used tokens are kept as historical record, not deleted.
-- -----------------------------------------------------------------------------
CREATE TABLE `passwordResetRequest` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `tokenHash`         VARCHAR(255) NOT NULL COMMENT 'SHA-256 (or stronger) hash of the reset token',
  `deliveryChannel`   ENUM('sms','email','ussd') NOT NULL,
  `requestIp`         VARCHAR(45) DEFAULT NULL,
  `requestUserAgent`  VARCHAR(500) DEFAULT NULL,
  `status`            ENUM('pending','used','expired','revoked') NOT NULL DEFAULT 'pending',
  `requestedAt`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expiresAt`         DATETIME NOT NULL,
  `usedAt`            DATETIME DEFAULT NULL,
  `usedFromIp`        VARCHAR(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pwreset_player` (`playerId`),
  KEY `idx_pwreset_status` (`status`),
  KEY `idx_pwreset_expires` (`expiresAt`),
  CONSTRAINT `fk_pwreset_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Player password reset token requests. Tokens hashed at rest.';

-- =============================================================================
-- 2. WALLET & LEDGER LAYER
-- =============================================================================
-- This is the heart of the AML architecture.
-- - `account` is a generic ledger account (player wallet, house revenue, etc.)
-- - `wallet` is a player-facing concept: each player has 2 accounts (Play, Payout)
--   plus the system has accounts for house, suspense, MoMo float, etc.
-- - `walletTransaction` is the umbrella transaction (one logical money event)
-- - `ledgerEntry` is the double-entry leg (always 2+ per transaction)
-- - Balances are DERIVED, never stored directly. Truth lives in ledgerEntry.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- account
-- -----------------------------------------------------------------------------
-- A generic ledger account. Every money movement debits one account and
-- credits another. Account types:
--   PLAYER_PLAY    - player's Play Balance (deposits + transfers from Payout)
--   PLAYER_PAYOUT  - player's Payout Balance (winnings only)
--   HOUSE_REVENUE  - BlackRed's earnings (stakes that didn't win)
--   HOUSE_LIABILITY - amounts owed to players (mirror of player wallets)
--   MOMO_FLOAT_MTN - settlement holding for MTN MoMo
--   MOMO_FLOAT_ATL - settlement holding for AirtelTigo
--   MOMO_FLOAT_TEL - settlement holding for Telecel (formerly Vodafone)
--   SUSPENSE       - holding for in-doubt transactions
-- -----------------------------------------------------------------------------
CREATE TABLE `account` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `accountCode`       VARCHAR(64)  NOT NULL COMMENT 'Unique business code, e.g. PLAYER_PLAY:42 or HOUSE_REVENUE',
  `accountType`       ENUM(
                        'PLAYER_PLAY',
                        'PLAYER_PAYOUT',
                        'HOUSE_REVENUE',
                        'HOUSE_LIABILITY',
                        'MOMO_FLOAT_MTN',
                        'MOMO_FLOAT_ATL',
                        'MOMO_FLOAT_TEL',
                        'SUSPENSE'
                      ) NOT NULL,
  `ownerType`         ENUM('player','house','momo','system') NOT NULL,
  `ownerId`           BIGINT UNSIGNED DEFAULT NULL COMMENT 'player.id when ownerType=player; NULL otherwise',
  `currency`          CHAR(3) NOT NULL DEFAULT 'GHS',
  `normalBalance`     ENUM('debit','credit') NOT NULL
                      COMMENT 'Asset/expense=debit normal; liability/equity/revenue=credit normal',
  `status`            ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_code` (`accountCode`),
  KEY `idx_account_owner` (`ownerType`, `ownerId`),
  KEY `idx_account_type` (`accountType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Generic ledger accounts. Player wallets, house, MoMo float, suspense.';

-- -----------------------------------------------------------------------------
-- wallet
-- -----------------------------------------------------------------------------
-- Player-facing wallet record. Each player has exactly 2 wallets: Play & Payout.
-- This table is essentially a player-friendly view of their accounts plus
-- some convenience cached fields. Balance fields are CACHED for performance
-- but the canonical truth is the ledger.
--
-- Cached balance is updated transactionally with each ledger write.
-- A nightly reconciliation job verifies cachedBalancePesewas == derived balance.
-- -----------------------------------------------------------------------------
CREATE TABLE `wallet` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `walletType`        ENUM('PLAY','PAYOUT') NOT NULL,
  `accountId`         BIGINT UNSIGNED NOT NULL COMMENT 'FK to underlying ledger account',
  `cachedBalancePesewas` BIGINT NOT NULL DEFAULT 0
                      COMMENT 'Cached for query speed. Source of truth = ledger.',
  `version`           BIGINT UNSIGNED NOT NULL DEFAULT 0
                      COMMENT 'Optimistic concurrency token. Incremented on every balance change.',
  `lastReconciledAt`  DATETIME DEFAULT NULL,
  `status`            ENUM('active','frozen') NOT NULL DEFAULT 'active',
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wallet_player_type` (`playerId`, `walletType`),
  UNIQUE KEY `uniq_wallet_account` (`accountId`),
  CONSTRAINT `fk_wallet_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wallet_account`
    FOREIGN KEY (`accountId`) REFERENCES `account`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Player Play Balance and Payout Balance wallets.';

-- -----------------------------------------------------------------------------
-- walletTransaction
-- -----------------------------------------------------------------------------
-- The umbrella transaction. One logical money event = one row here, multiple
-- rows in ledgerEntry. Examples:
--   - DEPOSIT:        MoMo -> Play Balance       (2 ledger entries)
--   - STAKE:          Play Balance -> Suspense   (held until draw resolves)
--   - WIN_PAYOUT:     Suspense -> Payout Balance (on win)
--   - LOSS_FORFEIT:   Suspense -> House Revenue  (on loss)
--   - INTERNAL_XFER:  Payout -> Play             (player-initiated, unrestricted)
--   - WITHDRAWAL:     Payout or Play -> MoMo
--   - REVERSAL:       Reverse a prior transaction (audit-friendly)
-- -----------------------------------------------------------------------------
CREATE TABLE `walletTransaction` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refNumber`         VARCHAR(64) NOT NULL COMMENT 'Globally unique business reference (idempotency key)',
  `txnType`           ENUM(
                        'DEPOSIT',
                        'STAKE',
                        'WIN_PAYOUT',
                        'LOSS_FORFEIT',
                        'INTERNAL_XFER',
                        'WITHDRAWAL_PLAY',
                        'WITHDRAWAL_PAYOUT',
                        'REVERSAL',
                        'ADJUSTMENT'
                      ) NOT NULL,
  `playerId`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL for system-only transactions',
  `amountPesewas`     BIGINT NOT NULL COMMENT 'Always positive; direction defined by ledger entries',
  `currency`          CHAR(3) NOT NULL DEFAULT 'GHS',
  `status`            ENUM('pending','completed','failed','reversed') NOT NULL DEFAULT 'pending',
  `failureReason`     VARCHAR(500) DEFAULT NULL,
  `relatedTxnId`      BIGINT UNSIGNED DEFAULT NULL COMMENT 'For reversals/links',
  `metadata`          JSON DEFAULT NULL COMMENT 'Free-form context (eventNumber, momoTxnId, etc.)',
  `initiatedBy`       VARCHAR(100) DEFAULT NULL COMMENT 'player|system|admin:<id>',
  `channel`           ENUM('web','app','ussd','system','admin') DEFAULT NULL,
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completedAt`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_txn_ref` (`refNumber`),
  KEY `idx_txn_player` (`playerId`),
  KEY `idx_txn_type_status` (`txnType`, `status`),
  KEY `idx_txn_createdAt` (`createdAt`),
  KEY `idx_txn_related` (`relatedTxnId`),
  CONSTRAINT `fk_txn_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_txn_related`
    FOREIGN KEY (`relatedTxnId`) REFERENCES `walletTransaction`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Umbrella record for every money movement on the platform.';

-- -----------------------------------------------------------------------------
-- ledgerEntry
-- -----------------------------------------------------------------------------
-- Double-entry ledger. For any walletTransaction, the sum of debits MUST equal
-- the sum of credits. This is enforced at the application layer in a DB
-- transaction. Periodic integrity check:
--
--   SELECT walletTxnId, SUM(CASE WHEN side='debit' THEN amountPesewas ELSE 0 END)
--                     - SUM(CASE WHEN side='credit' THEN amountPesewas ELSE 0 END) AS imbalance
--   FROM ledgerEntry GROUP BY walletTxnId HAVING imbalance != 0;
--
-- An imbalance is a P0 incident.
-- -----------------------------------------------------------------------------
CREATE TABLE `ledgerEntry` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `walletTxnId`       BIGINT UNSIGNED NOT NULL,
  `accountId`         BIGINT UNSIGNED NOT NULL,
  `side`              ENUM('debit','credit') NOT NULL,
  `amountPesewas`     BIGINT UNSIGNED NOT NULL COMMENT 'Always positive',
  `currency`          CHAR(3) NOT NULL DEFAULT 'GHS',
  `description`       VARCHAR(255) DEFAULT NULL,
  `postedAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ledger_txn` (`walletTxnId`),
  KEY `idx_ledger_account` (`accountId`),
  KEY `idx_ledger_account_posted` (`accountId`, `postedAt`),
  CONSTRAINT `fk_ledger_txn`
    FOREIGN KEY (`walletTxnId`) REFERENCES `walletTransaction`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ledger_account`
    FOREIGN KEY (`accountId`) REFERENCES `account`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable double-entry ledger. Every money movement, two sides.';

-- =============================================================================
-- 3. DEPOSIT & WITHDRAWAL REQUESTS
-- =============================================================================
-- These tables track the FULL LIFECYCLE of a deposit or withdrawal, including
-- the in-flight state with the external MoMo API, distinct from the wallet
-- transaction (which only fires on confirmed success).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- depositRequest
-- -----------------------------------------------------------------------------
-- Tracks every deposit attempt: initiated -> pending with telco -> confirmed/failed.
-- A successful deposit yields a walletTransaction of type DEPOSIT.
-- -----------------------------------------------------------------------------
CREATE TABLE `depositRequest` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refNumber`         VARCHAR(64) NOT NULL COMMENT 'Same value used as walletTransaction.refNumber on success',
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `msisdn`            VARCHAR(15) NOT NULL,
  `paymentProvider`   VARCHAR(10) NOT NULL,
  `amountPesewas`     BIGINT UNSIGNED NOT NULL,
  `currency`          CHAR(3) NOT NULL DEFAULT 'GHS',
  `momoTxnId`         VARCHAR(100) DEFAULT NULL COMMENT 'Telco-side transaction ID',
  `momoRequestPayload` JSON DEFAULT NULL,
  `momoResponsePayload` JSON DEFAULT NULL,
  `status`            ENUM('initiated','pending','succeeded','failed','timeout','reconciling','reversed') NOT NULL DEFAULT 'initiated',
  `failureCode`       VARCHAR(50) DEFAULT NULL,
  `failureReason`     VARCHAR(500) DEFAULT NULL,
  `walletTxnId`       BIGINT UNSIGNED DEFAULT NULL COMMENT 'Set when wallet credit posts',
  `channel`           ENUM('web','app','ussd') NOT NULL,
  `initiatedAt`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmedAt`       DATETIME DEFAULT NULL,
  `lastPolledAt`      DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_deposit_ref` (`refNumber`),
  KEY `idx_deposit_player` (`playerId`),
  KEY `idx_deposit_msisdn` (`msisdn`),
  KEY `idx_deposit_status` (`status`),
  KEY `idx_deposit_momoTxn` (`momoTxnId`),
  CONSTRAINT `fk_deposit_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_deposit_walletTxn`
    FOREIGN KEY (`walletTxnId`) REFERENCES `walletTransaction`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lifecycle record for every deposit attempt via MoMo.';

-- -----------------------------------------------------------------------------
-- withdrawalRequest
-- -----------------------------------------------------------------------------
-- Tracks every withdrawal attempt. Source wallet tells us whether the 50%
-- rule applies (Play) or unrestricted (Payout). The application layer must
-- compute and validate the 50% rule BEFORE creating the withdrawalRequest;
-- this row serves as the audit record of that decision.
-- -----------------------------------------------------------------------------
CREATE TABLE `withdrawalRequest` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refNumber`         VARCHAR(64) NOT NULL,
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `msisdn`            VARCHAR(15) NOT NULL,
  `paymentProvider`   VARCHAR(10) NOT NULL,
  `sourceWallet`      ENUM('PLAY','PAYOUT') NOT NULL,
  `amountPesewas`     BIGINT UNSIGNED NOT NULL,
  `currency`          CHAR(3) NOT NULL DEFAULT 'GHS',
  `playBalanceAtRequestPesewas` BIGINT DEFAULT NULL
                      COMMENT 'Snapshot of Play Balance when request made (for 50% rule audit)',
  `fiftyPercentRuleApplied` TINYINT(1) NOT NULL DEFAULT 0,
  `fiftyPercentRulePassed`  TINYINT(1) NOT NULL DEFAULT 0,
  `momoTxnId`         VARCHAR(100) DEFAULT NULL,
  `momoRequestPayload` JSON DEFAULT NULL,
  `momoResponsePayload` JSON DEFAULT NULL,
  `status`            ENUM('initiated','rule_blocked','approved','pending','succeeded','failed','timeout','reconciling','reversed') NOT NULL DEFAULT 'initiated',
  `blockReason`       VARCHAR(500) DEFAULT NULL COMMENT 'Set when rule_blocked',
  `failureCode`       VARCHAR(50) DEFAULT NULL,
  `failureReason`     VARCHAR(500) DEFAULT NULL,
  `walletTxnId`       BIGINT UNSIGNED DEFAULT NULL,
  `channel`           ENUM('web','app','ussd') NOT NULL,
  `initiatedAt`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approvedAt`        DATETIME DEFAULT NULL,
  `completedAt`       DATETIME DEFAULT NULL,
  `lastPolledAt`      DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_withdrawal_ref` (`refNumber`),
  KEY `idx_withdrawal_player` (`playerId`),
  KEY `idx_withdrawal_msisdn` (`msisdn`),
  KEY `idx_withdrawal_status` (`status`),
  KEY `idx_withdrawal_momoTxn` (`momoTxnId`),
  KEY `idx_withdrawal_source` (`sourceWallet`),
  CONSTRAINT `fk_withdrawal_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_withdrawal_walletTxn`
    FOREIGN KEY (`walletTxnId`) REFERENCES `walletTransaction`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lifecycle record for every withdrawal attempt. Audits 50% rule application.';

-- =============================================================================
-- 4. GAME LAYER
-- =============================================================================
-- Evolved from the POC's gamePlays/gamePayouts/drawResults/activeEvent.
-- Connected to the wallet/ledger so stakes and wins flow through the books.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- gameEvent
-- -----------------------------------------------------------------------------
-- A single instant-draw event. In an instant-draw model, an "event" is the
-- pairing of (player stake) -> (machine draw) -> (resolution). Each player
-- play creates its own event; events are NOT shared across players.
-- This replaces the POC's notion of activeEvent + drawResults.
-- -----------------------------------------------------------------------------
CREATE TABLE `gameEvent` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `eventNumber`       VARCHAR(64) NOT NULL COMMENT 'Public-facing event ref',
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `cardCount`         TINYINT UNSIGNED NOT NULL COMMENT '1..5',
  `multiplier`        SMALLINT UNSIGNED NOT NULL COMMENT '2,10,20,50,100',
  `playerSelection`   VARCHAR(5) NOT NULL COMMENT 'Colour string e.g. B, BR, BRRBB - source of truth for win calc',
  `drawResult`        VARCHAR(5) DEFAULT NULL COMMENT 'Colour string set when drawn',
  `playerSelectionPayload` JSON DEFAULT NULL
                      COMMENT 'Optional rich UI payload for picks. Reserved for future suit-level games.',
  `drawDisplayPayload` JSON DEFAULT NULL
                      COMMENT 'Full drawn cards for UI display: [{color,suit,rank},...]. Display-only; colours in drawResult are canonical.',
  `outcome`           ENUM('pending','win','loss','void') NOT NULL DEFAULT 'pending',
  `rngSeed`           VARCHAR(128) DEFAULT NULL COMMENT 'Cryptographic seed for verifiability',
  `rngAlgorithm`      VARCHAR(50) DEFAULT NULL,
  `drawnAt`           DATETIME DEFAULT NULL,
  `drawnBy`           VARCHAR(50) DEFAULT 'machine' COMMENT 'machine|admin:<id>',
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_number` (`eventNumber`),
  KEY `idx_event_player` (`playerId`),
  KEY `idx_event_outcome` (`outcome`),
  KEY `idx_event_drawnAt` (`drawnAt`),
  CONSTRAINT `fk_event_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_event_cardCount` CHECK (`cardCount` BETWEEN 1 AND 5),
  CONSTRAINT `chk_event_multiplier` CHECK (`multiplier` IN (2,10,20,50,100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-player instant draw events. RNG-verifiable.';

-- -----------------------------------------------------------------------------
-- gamePlay
-- -----------------------------------------------------------------------------
-- Singular form (the POC used plural). One row per stake placed.
-- Linked to the gameEvent it produced and to the walletTransaction that
-- moved the stake out of Play Balance.
-- -----------------------------------------------------------------------------
CREATE TABLE `gamePlay` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refNumber`         VARCHAR(64) NOT NULL,
  `eventId`           BIGINT UNSIGNED NOT NULL,
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `msisdn`            VARCHAR(15) NOT NULL,
  `paymentProvider`   VARCHAR(10) NOT NULL,
  `stakePesewas`      BIGINT UNSIGNED NOT NULL,
  `potentialPayoutPesewas` BIGINT UNSIGNED NOT NULL COMMENT 'stake * multiplier',
  `actualPayoutPesewas`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `selection`         VARCHAR(5) NOT NULL,
  `playMedium`        ENUM('web','app','ussd') NOT NULL,
  `winStatus`         ENUM('pending','win','loss','void') NOT NULL DEFAULT 'pending',
  `playType`          VARCHAR(20) DEFAULT NULL COMMENT 'Reserved for future variants',
  `stakeTxnId`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'walletTransaction that debited Play Balance',
  `payoutTxnId`       BIGINT UNSIGNED DEFAULT NULL COMMENT 'walletTransaction that credited Payout Balance (on win)',
  `playedAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolvedAt`        DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_play_ref` (`refNumber`),
  KEY `idx_play_event` (`eventId`),
  KEY `idx_play_player` (`playerId`),
  KEY `idx_play_msisdn` (`msisdn`),
  KEY `idx_play_winStatus` (`winStatus`),
  KEY `idx_play_playedAt` (`playedAt`),
  CONSTRAINT `fk_play_event`
    FOREIGN KEY (`eventId`) REFERENCES `gameEvent`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_play_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_play_stakeTxn`
    FOREIGN KEY (`stakeTxnId`) REFERENCES `walletTransaction`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_play_payoutTxn`
    FOREIGN KEY (`payoutTxnId`) REFERENCES `walletTransaction`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual game plays. One per stake. Linked to wallet transactions.';

-- -----------------------------------------------------------------------------
-- drawResult
-- -----------------------------------------------------------------------------
-- Detailed log of each draw. Could be merged into gameEvent but kept separate
-- for: (a) audit clarity, (b) ability to publish a draw feed independent of
-- player attribution, (c) RNG verification logs without exposing player data.
-- -----------------------------------------------------------------------------
CREATE TABLE `drawResult` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `eventId`           BIGINT UNSIGNED NOT NULL,
  `eventNumber`       VARCHAR(64) NOT NULL,
  `drawSelection`     VARCHAR(5) NOT NULL COMMENT 'The cards the machine drew, e.g. BRRBB',
  `cardCount`         TINYINT UNSIGNED NOT NULL,
  `rngSeed`           VARCHAR(128) DEFAULT NULL,
  `rngAlgorithm`      VARCHAR(50) DEFAULT NULL,
  `rngOutput`         JSON DEFAULT NULL COMMENT 'Raw RNG output for verifiability',
  `drawRunBy`         VARCHAR(100) NOT NULL DEFAULT 'machine',
  `drawnAt`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_drawResult_event` (`eventId`),
  KEY `idx_drawResult_eventNumber` (`eventNumber`),
  KEY `idx_drawResult_drawnAt` (`drawnAt`),
  CONSTRAINT `fk_drawResult_event`
    FOREIGN KEY (`eventId`) REFERENCES `gameEvent`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='RNG draw audit log. One row per executed draw.';

-- -----------------------------------------------------------------------------
-- gamePayout
-- -----------------------------------------------------------------------------
-- Retained for compatibility with POC reporting flows, but in v2 it is
-- ESSENTIALLY a denormalised view of (gamePlay where winStatus=win) plus
-- the credit to Payout Balance. The wallet ledger remains the canonical
-- source. Useful for finance reports without joining 4 tables every time.
-- -----------------------------------------------------------------------------
CREATE TABLE `gamePayout` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gamePlayId`        BIGINT UNSIGNED NOT NULL,
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `msisdn`            VARCHAR(15) NOT NULL,
  `paymentProvider`   VARCHAR(10) NOT NULL,
  `payoutPesewas`     BIGINT UNSIGNED NOT NULL,
  `walletTxnId`       BIGINT UNSIGNED DEFAULT NULL COMMENT 'Credit to Payout Balance',
  `refNumber`         VARCHAR(64) NOT NULL,
  `payStatus`         ENUM('pending','completed','failed','reversed') NOT NULL DEFAULT 'pending',
  `initiatedBy`       VARCHAR(100) NOT NULL DEFAULT 'system',
  `triggeredAt`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paidAt`            DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payout_ref` (`refNumber`),
  KEY `idx_payout_play` (`gamePlayId`),
  KEY `idx_payout_player` (`playerId`),
  KEY `idx_payout_status` (`payStatus`),
  CONSTRAINT `fk_payout_play`
    FOREIGN KEY (`gamePlayId`) REFERENCES `gamePlay`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payout_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payout_walletTxn`
    FOREIGN KEY (`walletTxnId`) REFERENCES `walletTransaction`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Win payouts. Credits Payout Balance, NOT MoMo (post-AML policy).';

-- =============================================================================
-- 5. INTEGRATION LAYER (External APIs)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- momoApiCallLog
-- -----------------------------------------------------------------------------
-- Every single call to a MoMo API (deposit, withdraw, name lookup) is logged
-- here, independently of the business transaction. This is the reconciliation
-- backbone: when a deposit is "stuck", we look here first.
-- -----------------------------------------------------------------------------
CREATE TABLE `momoApiCallLog` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `callType`          ENUM('deposit','withdraw','name_lookup','status_query','reversal') NOT NULL,
  `provider`          VARCHAR(10) NOT NULL,
  `endpoint`          VARCHAR(255) NOT NULL,
  `httpMethod`        VARCHAR(10) NOT NULL,
  `requestRef`        VARCHAR(64) DEFAULT NULL COMMENT 'Our refNumber sent to telco',
  `correlationId`     VARCHAR(64) DEFAULT NULL COMMENT 'Telco-side correlation/txn id',
  `relatedTable`      VARCHAR(50) DEFAULT NULL COMMENT 'depositRequest|withdrawalRequest|kycRecord|...',
  `relatedId`         BIGINT UNSIGNED DEFAULT NULL,
  `requestPayload`    JSON DEFAULT NULL,
  `responsePayload`   JSON DEFAULT NULL,
  `httpStatus`        SMALLINT UNSIGNED DEFAULT NULL,
  `responseCode`      VARCHAR(50) DEFAULT NULL,
  `outcome`           ENUM('success','failure','timeout','pending','error') NOT NULL,
  `latencyMs`         INT UNSIGNED DEFAULT NULL,
  `attemptNumber`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `calledAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `respondedAt`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_momo_callType` (`callType`),
  KEY `idx_momo_outcome` (`outcome`),
  KEY `idx_momo_requestRef` (`requestRef`),
  KEY `idx_momo_correlation` (`correlationId`),
  KEY `idx_momo_related` (`relatedTable`, `relatedId`),
  KEY `idx_momo_calledAt` (`calledAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log of every MoMo API call. Reconciliation backbone.';

-- -----------------------------------------------------------------------------
-- nameLookupCache
-- -----------------------------------------------------------------------------
-- Cache of MoMo name lookups by msisdn. Reduces API calls for repeat lookups
-- (e.g. re-registration attempts, withdrawal verifications). TTL enforced at
-- application layer; cached entries older than N days are re-queried.
-- -----------------------------------------------------------------------------
CREATE TABLE `nameLookupCache` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `msisdn`            VARCHAR(15) NOT NULL,
  `provider`          VARCHAR(10) NOT NULL,
  `returnedName`      VARCHAR(150) DEFAULT NULL,
  `lookupStatus`      ENUM('success','not_found','error') NOT NULL,
  `rawResponse`       JSON DEFAULT NULL,
  `lookedUpAt`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expiresAt`         DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nameLookup_msisdn` (`msisdn`),
  KEY `idx_nameLookup_expires` (`expiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cached MoMo name lookups by msisdn.';

-- -----------------------------------------------------------------------------
-- smsLog
-- -----------------------------------------------------------------------------
-- Every SMS sent to a player. Treated as part of the audit trail (player-side
-- proof of every balance-changing event).
-- -----------------------------------------------------------------------------
CREATE TABLE `smsLog` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playerId`          BIGINT UNSIGNED DEFAULT NULL,
  `msisdn`            VARCHAR(15) NOT NULL,
  `category`          ENUM(
                        'otp',
                        'deposit_confirm',
                        'stake_confirm',
                        'win_notify',
                        'withdrawal_confirm',
                        'withdrawal_failed',
                        'kyc_status',
                        'security_alert',
                        'marketing',
                        'system'
                      ) NOT NULL,
  `messageBody`       VARCHAR(500) NOT NULL,
  `relatedTable`      VARCHAR(50) DEFAULT NULL,
  `relatedId`         BIGINT UNSIGNED DEFAULT NULL,
  `provider`          VARCHAR(50) DEFAULT NULL COMMENT 'SMS gateway used',
  `providerMsgId`     VARCHAR(100) DEFAULT NULL,
  `status`            ENUM('queued','sent','delivered','failed') NOT NULL DEFAULT 'queued',
  `failureReason`     VARCHAR(500) DEFAULT NULL,
  `costPesewas`       INT UNSIGNED DEFAULT NULL,
  `queuedAt`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sentAt`            DATETIME DEFAULT NULL,
  `deliveredAt`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sms_player` (`playerId`),
  KEY `idx_sms_msisdn` (`msisdn`),
  KEY `idx_sms_category` (`category`),
  KEY `idx_sms_status` (`status`),
  KEY `idx_sms_related` (`relatedTable`, `relatedId`),
  KEY `idx_sms_queuedAt` (`queuedAt`),
  CONSTRAINT `fk_sms_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Outbound SMS log. Every balance-changing event triggers a row.';

-- =============================================================================
-- 6. COMPLIANCE & AUDIT LAYER
-- =============================================================================

-- -----------------------------------------------------------------------------
-- suspiciousActivityFlag
-- -----------------------------------------------------------------------------
-- Records every system or operator-raised flag for AML review. A flagged
-- player may have multiple open flags. Resolution is logged.
-- -----------------------------------------------------------------------------
CREATE TABLE `suspiciousActivityFlag` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `flagType`          ENUM(
                        'velocity_high',
                        'low_gameplay_ratio',
                        'cumulative_threshold_30d',
                        'multiple_account_link',
                        'name_mismatch',
                        'withdrawal_pattern',
                        'manual',
                        'other'
                      ) NOT NULL,
  `severity`          ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `triggerSource`     ENUM('system','operator') NOT NULL,
  `triggerDetails`    JSON DEFAULT NULL COMMENT 'Metric values that triggered the flag',
  `description`       VARCHAR(1000) DEFAULT NULL,
  `status`            ENUM('open','under_review','escalated','closed_no_action','closed_action_taken','reported_to_fic') NOT NULL DEFAULT 'open',
  `assignedTo`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'institutionUser.id',
  `resolution`        VARCHAR(2000) DEFAULT NULL,
  `resolvedBy`        BIGINT UNSIGNED DEFAULT NULL,
  `resolvedAt`        DATETIME DEFAULT NULL,
  `ficReportRef`      VARCHAR(100) DEFAULT NULL COMMENT 'Financial Intelligence Centre reference if reported',
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_flag_player` (`playerId`),
  KEY `idx_flag_status` (`status`),
  KEY `idx_flag_type` (`flagType`),
  KEY `idx_flag_severity` (`severity`),
  KEY `idx_flag_assigned` (`assignedTo`),
  CONSTRAINT `fk_flag_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_flag_assigned`
    FOREIGN KEY (`assignedTo`) REFERENCES `institutionUser`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_flag_resolvedBy`
    FOREIGN KEY (`resolvedBy`) REFERENCES `institutionUser`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AML suspicious activity flags. One row per investigation case.';

-- -----------------------------------------------------------------------------
-- auditLog
-- -----------------------------------------------------------------------------
-- Generic admin/operator action audit. Logs every meaningful state change
-- performed by an institution user (KYC approval, manual adjustments, freezes).
-- -----------------------------------------------------------------------------
CREATE TABLE `auditLog` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actorType`         ENUM('institutionUser','system','player') NOT NULL,
  `actorId`           BIGINT UNSIGNED DEFAULT NULL,
  `action`            VARCHAR(100) NOT NULL COMMENT 'e.g. KYC_APPROVE, WALLET_FREEZE, MANUAL_ADJUSTMENT',
  `targetTable`       VARCHAR(64) DEFAULT NULL,
  `targetId`          BIGINT UNSIGNED DEFAULT NULL,
  `before`            JSON DEFAULT NULL,
  `after`             JSON DEFAULT NULL,
  `reason`            VARCHAR(1000) DEFAULT NULL,
  `ipAddress`         VARCHAR(45) DEFAULT NULL,
  `userAgent`         VARCHAR(500) DEFAULT NULL,
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor` (`actorType`, `actorId`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_target` (`targetTable`, `targetId`),
  KEY `idx_audit_createdAt` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Generic audit trail for institution user actions and system events.';

-- =============================================================================
-- 7. ANALYTICS / REPORTING TABLES
-- =============================================================================
-- Retained from the POC for revenue reporting, evolved to use BIGINT pesewas.
-- These are populated by scheduled jobs, not by transaction handlers.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- winAnalysisDaily
-- -----------------------------------------------------------------------------
-- Daily roll-up of wins by card-count tier. Replaces POC winAnalysis.
-- -----------------------------------------------------------------------------
CREATE TABLE `winAnalysisDaily` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dateDraw`          DATE NOT NULL,
  `cardCount`         TINYINT UNSIGNED NOT NULL,
  `volumeWins`        INT UNSIGNED NOT NULL DEFAULT 0,
  `valueWinsPesewas`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `volumeStakes`      INT UNSIGNED NOT NULL DEFAULT 0,
  `valueStakesPesewas` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `houseEdgePesewas`  BIGINT NOT NULL DEFAULT 0 COMMENT 'stakes - wins, can be negative on outlier days',
  `computedAt`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_winAnalysis_date_cards` (`dateDraw`, `cardCount`),
  KEY `idx_winAnalysis_date` (`dateDraw`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily win/stake analysis by card-count tier.';

-- -----------------------------------------------------------------------------
-- revenueDaily
-- -----------------------------------------------------------------------------
-- Daily revenue roll-up. Replaces POC winAnalysisRev.
-- -----------------------------------------------------------------------------
CREATE TABLE `revenueDaily` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dateDraw`          DATE NOT NULL,
  `grossStakesPesewas` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `grossWinsPesewas`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `netRevenuePesewas`  BIGINT NOT NULL DEFAULT 0 COMMENT 'gross stakes - gross wins',
  `playerCount`        INT UNSIGNED NOT NULL DEFAULT 0,
  `playCount`          INT UNSIGNED NOT NULL DEFAULT 0,
  `computedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_revenue_date` (`dateDraw`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily gross revenue roll-up.';

-- =============================================================================
-- 8. CONTENT / CMS LAYER
-- =============================================================================
-- Powers the dashboard "Events & Shows" surface and any future marketing
-- placements. Content is editorial; it does not move money. Player
-- registrations for events (e.g. Community Cup) are tracked separately so
-- attendance and engagement can be reported.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- marketingEvent
-- -----------------------------------------------------------------------------
-- A single marketing card displayed on the dashboard or in a dedicated
-- events surface. Editable from the admin panel. Visibility is controlled by
-- status + the publishedFrom/publishedUntil window.
-- -----------------------------------------------------------------------------
CREATE TABLE `marketingEvent` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`              VARCHAR(100) NOT NULL COMMENT 'URL-safe identifier, e.g. detty-rave-may-2026',
  `category`          ENUM('concert','festival','comedy','community','official','promo','other') NOT NULL,
  `title`             VARCHAR(200) NOT NULL,
  `subtitle`          VARCHAR(300) DEFAULT NULL,
  `bodyText`          TEXT DEFAULT NULL COMMENT 'Long-form description for the details view',
  `eventDate`         DATE DEFAULT NULL COMMENT 'When the event happens (NULL for evergreen promos)',
  `eventTime`         TIME DEFAULT NULL,
  `venue`             VARCHAR(200) DEFAULT NULL,
  `city`              VARCHAR(100) DEFAULT NULL,
  `imageUrl`          VARCHAR(500) DEFAULT NULL,
  `imageAltText`      VARCHAR(300) DEFAULT NULL,
  `ctaLabel`          VARCHAR(50)  DEFAULT NULL COMMENT 'e.g. View details, Register now, Get tickets',
  `ctaUrl`            VARCHAR(500) DEFAULT NULL,
  `requiresRegistration` TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT '1 = use playerEventRegistration; 0 = informational only',
  `displayOrder`      INT NOT NULL DEFAULT 0 COMMENT 'Lower number = earlier in slider',
  `status`            ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `publishedFrom`     DATETIME DEFAULT NULL COMMENT 'Inclusive start of visibility window',
  `publishedUntil`    DATETIME DEFAULT NULL COMMENT 'Inclusive end of visibility window',
  `createdBy`         BIGINT UNSIGNED DEFAULT NULL,
  `updatedBy`         BIGINT UNSIGNED DEFAULT NULL,
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deletedAt`         DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_slug` (`slug`),
  KEY `idx_event_status` (`status`),
  KEY `idx_event_displayOrder` (`displayOrder`),
  KEY `idx_event_publishedFrom` (`publishedFrom`),
  KEY `idx_event_eventDate` (`eventDate`),
  CONSTRAINT `fk_event_createdBy`
    FOREIGN KEY (`createdBy`) REFERENCES `institutionUser`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_event_updatedBy`
    FOREIGN KEY (`updatedBy`) REFERENCES `institutionUser`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CMS-managed marketing events shown on the dashboard.';

-- -----------------------------------------------------------------------------
-- playerEventRegistration
-- -----------------------------------------------------------------------------
-- Tracks player sign-ups for events that requireRegistration = 1
-- (e.g. Community Cup). Allows reporting on engagement and attendance.
-- -----------------------------------------------------------------------------
CREATE TABLE `playerEventRegistration` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketingEventId`  BIGINT UNSIGNED NOT NULL,
  `playerId`          BIGINT UNSIGNED NOT NULL,
  `registrationCode`  VARCHAR(32) DEFAULT NULL COMMENT 'Optional human-readable code for door check-in',
  `status`            ENUM('registered','attended','no_show','cancelled') NOT NULL DEFAULT 'registered',
  `metadata`          JSON DEFAULT NULL COMMENT 'Free-form: t-shirt size, party size, etc.',
  `registeredAt`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `statusUpdatedAt`   DATETIME DEFAULT NULL,
  `cancelledAt`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_eventReg_player_event` (`marketingEventId`, `playerId`),
  KEY `idx_eventReg_player` (`playerId`),
  KEY `idx_eventReg_status` (`status`),
  CONSTRAINT `fk_eventReg_event`
    FOREIGN KEY (`marketingEventId`) REFERENCES `marketingEvent`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_eventReg_player`
    FOREIGN KEY (`playerId`) REFERENCES `player`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Player sign-ups for marketing events that require registration.';

-- =============================================================================
-- 9. SYSTEM CONFIGURATION
-- =============================================================================

-- -----------------------------------------------------------------------------
-- systemConfig
-- -----------------------------------------------------------------------------
-- Tunable platform parameters. Anything that the Board or Management may need
-- to change without a code deploy lives here: deposit/withdrawal limits, KYC
-- thresholds, the 50% rule percentage itself, AML velocity thresholds, etc.
--
-- Every change must produce an auditLog row capturing who changed what.
-- Values are stored as VARCHAR but typed via valueType for consumer hints.
-- -----------------------------------------------------------------------------
CREATE TABLE `systemConfig` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `configKey`         VARCHAR(100) NOT NULL,
  `configValue`       VARCHAR(1000) NOT NULL,
  `valueType`         ENUM('integer','decimal','string','boolean','json') NOT NULL DEFAULT 'string',
  `description`       VARCHAR(500) DEFAULT NULL,
  `category`          VARCHAR(50) DEFAULT NULL COMMENT 'e.g. deposit, withdrawal, aml, game, security',
  `editableViaAdmin`  TINYINT(1) NOT NULL DEFAULT 1
                      COMMENT '0 = code-only constants surfaced for visibility; 1 = admin-editable',
  `lastChangedBy`     BIGINT UNSIGNED DEFAULT NULL,
  `createdAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_config_key` (`configKey`),
  KEY `idx_config_category` (`category`),
  CONSTRAINT `fk_config_lastChangedBy`
    FOREIGN KEY (`lastChangedBy`) REFERENCES `institutionUser`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tunable platform parameters. Changes audited via auditLog.';

-- =============================================================================
-- 10. VIEWS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- vw_walletBalance
-- -----------------------------------------------------------------------------
-- Live, derived wallet balance from the ledger. Use this for any reconciliation
-- check. The wallet.cachedBalancePesewas should always equal this view's value.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_walletBalance` AS
SELECT
  w.id                                              AS walletId,
  w.playerId                                        AS playerId,
  w.walletType                                      AS walletType,
  w.cachedBalancePesewas                            AS cachedBalancePesewas,
  COALESCE(SUM(CASE
    WHEN a.normalBalance = 'credit' AND le.side = 'credit' THEN le.amountPesewas
    WHEN a.normalBalance = 'credit' AND le.side = 'debit'  THEN -le.amountPesewas
    WHEN a.normalBalance = 'debit'  AND le.side = 'debit'  THEN le.amountPesewas
    WHEN a.normalBalance = 'debit'  AND le.side = 'credit' THEN -le.amountPesewas
    ELSE 0
  END), 0)                                          AS derivedBalancePesewas,
  COALESCE(SUM(CASE
    WHEN a.normalBalance = 'credit' AND le.side = 'credit' THEN le.amountPesewas
    WHEN a.normalBalance = 'credit' AND le.side = 'debit'  THEN -le.amountPesewas
    WHEN a.normalBalance = 'debit'  AND le.side = 'debit'  THEN le.amountPesewas
    WHEN a.normalBalance = 'debit'  AND le.side = 'credit' THEN -le.amountPesewas
    ELSE 0
  END), 0) - w.cachedBalancePesewas                 AS reconciliationDelta
FROM wallet w
JOIN account a       ON a.id = w.accountId
LEFT JOIN ledgerEntry le ON le.accountId = w.accountId
GROUP BY w.id, w.playerId, w.walletType, w.cachedBalancePesewas;

-- -----------------------------------------------------------------------------
-- vw_playerSummary
-- -----------------------------------------------------------------------------
-- One-row summary per player for admin dashboards.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_playerSummary` AS
SELECT
  p.id                                              AS playerId,
  p.msisdn                                          AS msisdn,
  p.registeredName                                  AS registeredName,
  p.kycStatus                                       AS kycStatus,
  p.accountStatus                                   AS accountStatus,
  COALESCE(wp.cachedBalancePesewas, 0)              AS playBalancePesewas,
  COALESCE(wo.cachedBalancePesewas, 0)              AS payoutBalancePesewas,
  p.createdAt                                       AS registeredAt,
  p.lastLoginAt                                     AS lastLoginAt
FROM player p
LEFT JOIN wallet wp ON wp.playerId = p.id AND wp.walletType = 'PLAY'
LEFT JOIN wallet wo ON wo.playerId = p.id AND wo.walletType = 'PAYOUT'
WHERE p.deletedAt IS NULL;

-- -----------------------------------------------------------------------------
-- vw_walletDualBalance
-- -----------------------------------------------------------------------------
-- Tailored to the dual-balance UI on dashboard, withdraw, and account screens.
-- Returns one row per player with the two balances and pre-computed maximum
-- withdrawable amounts under the BR-AML-001 rules:
--   - Payout Balance: fully withdrawable
--   - Play Balance:   max 50% per request (rounded down to the pesewa)
-- The total balance is also exposed for screens that prefer a single figure.
-- -----------------------------------------------------------------------------
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

-- =============================================================================
-- 11. SEED DATA
-- =============================================================================

-- Default institution roles
INSERT INTO `institutionRole` (`roleCode`, `roleName`, `description`) VALUES
  ('SUPER_ADMIN',  'Super Administrator', 'Full system access. Tightly controlled.'),
  ('ADMIN',        'Administrator',       'Operational admin without destructive privileges.'),
  ('COMPLIANCE',   'Compliance Officer',  'KYC review, AML flag handling, FIC reporting.'),
  ('FINANCE',      'Finance Officer',     'Reconciliation, reporting, settlement oversight.'),
  ('DRAW_OP',      'Draw Operator',       'Draw monitoring and intervention authority.'),
  ('SUPPORT',      'Customer Support',    'Read-only player view + ticketing.');

-- House and system accounts (one-time seed)
INSERT INTO `account` (`accountCode`, `accountType`, `ownerType`, `ownerId`, `normalBalance`) VALUES
  ('HOUSE_REVENUE',     'HOUSE_REVENUE',   'house',  NULL, 'credit'),
  ('HOUSE_LIABILITY',   'HOUSE_LIABILITY', 'house',  NULL, 'credit'),
  ('MOMO_FLOAT_MTN',    'MOMO_FLOAT_MTN',  'momo',   NULL, 'debit'),
  ('MOMO_FLOAT_ATL',    'MOMO_FLOAT_ATL',  'momo',   NULL, 'debit'),
  ('MOMO_FLOAT_TEL',    'MOMO_FLOAT_TEL',  'momo',   NULL, 'debit'),
  ('SUSPENSE',          'SUSPENSE',        'system', NULL, 'debit');

-- Default system configuration
-- Values use pesewas for money. Percentages stored as integers (50 = 50%).
INSERT INTO `systemConfig` (`configKey`, `configValue`, `valueType`, `category`, `description`) VALUES
  ('deposit.minPesewas',                    '200',     'integer', 'deposit',    'Minimum single deposit (GHS 2.00)'),
  ('deposit.maxPesewas',                    '500000',  'integer', 'deposit',    'Maximum single deposit (GHS 5,000.00)'),
  ('withdrawal.minPesewas',                 '200',     'integer', 'withdrawal', 'Minimum single withdrawal (GHS 2.00)'),
  ('withdrawal.playBalancePercentMax',      '50',      'integer', 'aml',        'Maximum percentage of Play Balance per withdrawal (50 = 50%)'),
  ('aml.cumulativeDeposit30dThresholdPesewas', '5000000', 'integer', 'aml',     'Rolling 30-day deposit threshold triggering enhanced due diligence (GHS 50,000)'),
  ('aml.dailyVelocityCount',                '20',      'integer', 'aml',        'Number of deposit+withdrawal events in 24h that trigger a velocity flag'),
  ('game.minStakePesewas',                  '200',     'integer', 'game',       'Minimum stake per play (GHS 2.00)'),
  ('game.cardCountMin',                     '1',       'integer', 'game',       'Minimum cards per play'),
  ('game.cardCountMax',                     '5',       'integer', 'game',       'Maximum cards per play'),
  ('security.passwordResetTtlMinutes',      '30',      'integer', 'security',   'Password reset token validity in minutes'),
  ('security.ussdAuthCodeTtlSeconds',       '120',     'integer', 'security',   'USSD auth code validity in seconds (matches signUp UI 2:00 timer)'),
  ('security.maxFailedLoginsBeforeLock',    '5',       'integer', 'security',   'Failed login attempts before institutionUser lock-out'),
  ('security.lockoutMinutes',               '15',      'integer', 'security',   'Duration of account lock-out after max failed logins'),
  ('platform.appVersion',                   '1.0',     'string',  'platform',   'Current released app version surface');

COMMIT;

-- =============================================================================
-- END OF SCHEMA
-- =============================================================================
