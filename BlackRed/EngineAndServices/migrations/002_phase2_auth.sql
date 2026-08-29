-- =============================================================================
-- BlackRed Raffle - Migration 002 (Phase 2 — Signup + Auth)
-- =============================================================================
--
-- Apply this migration AFTER 001_initial_schema.sql.
-- Idempotent: safe to run multiple times (uses IF NOT EXISTS / DROP+CREATE).
--
-- IMPORTANT: This migration assumes the `authtoken` table already exists,
-- created and populated by the live USSD endpoint at *920*995#:
--
--   CREATE TABLE authtoken (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     phonenumber VARCHAR(20) NOT NULL,
--     codehash VARCHAR(255) NOT NULL,
--     expirydate DATE NOT NULL,
--     expirytime TIME NOT NULL,
--     ip_address VARCHAR(45) NULL,
--     device_id VARCHAR(100) NULL,
--     request_time DATETIME DEFAULT CURRENT_TIMESTAMP,
--     INDEX idx_phone (phonenumber),
--     INDEX idx_expiry (expirydate, expirytime)
--   );
--
-- The USSD stores phone numbers as "0244XXXXXX" (10 digits with leading 0)
-- and hashes the 4-character alphanumeric OTP with SHA-512.
-- Our verify endpoint normalises 233-prefixed phones back to 0-prefixed for
-- the lookup, and uses SHA-512 on the entered code for comparison.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- rateLimitBucket  (per-key, per-minute counters)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rateLimitBucket` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucketKey`    VARCHAR(150) NOT NULL,
  `windowStart`  DATETIME     NOT NULL,
  `attempts`     INT UNSIGNED NOT NULL DEFAULT 1,
  `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rateLimit_bucket_window` (`bucketKey`, `windowStart`),
  KEY `idx_rateLimit_window` (`windowStart`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sliding-window per-key rate-limit counters.';


-- -----------------------------------------------------------------------------
-- csrfToken  (server-side CSRF tokens, one per session)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `csrfToken` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sessionId`  BIGINT UNSIGNED NOT NULL,
  `token`      VARCHAR(64)  NOT NULL,
  `expiresAt`  DATETIME     NOT NULL,
  `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_csrf_token` (`token`),
  KEY `idx_csrf_session` (`sessionId`),
  KEY `idx_csrf_expires` (`expiresAt`),
  CONSTRAINT `fk_csrf_session`
    FOREIGN KEY (`sessionId`) REFERENCES `playerSession`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Server-side CSRF tokens, one or more per session.';


-- -----------------------------------------------------------------------------
-- signupSession  (state across the 3-step signup wizard)
-- -----------------------------------------------------------------------------
-- One row per signup-in-progress, keyed by msisdn (canonical 233 form).
-- Tracks which step has been completed:
--   - lookupCompletedAt: step 1 done, registeredName cached from ANM
--   - otpVerifiedAt:     step 2 done, code matched against authtoken
--
-- When step 3 (password) creates the player, we soft-delete this row by
-- setting consumedAt, then can clean up old rows daily.
--
-- We DROP any pre-existing signupOtp table if present (was created by an
-- earlier draft of this migration and is no longer used).
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `signupOtp`;

CREATE TABLE IF NOT EXISTS `signupSession` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `msisdn`              VARCHAR(15)  NOT NULL COMMENT '233XXXXXXXXX canonical form',
  `paymentProvider`     VARCHAR(10)  NOT NULL COMMENT 'MTN | ATL | TEL',
  `registeredName`      VARCHAR(150) DEFAULT NULL COMMENT 'Set by step 1 from ANM lookup',
  `nameLookupRaw`       JSON         DEFAULT NULL COMMENT 'Full ANM response',
  `lookupCompletedAt`   DATETIME     DEFAULT NULL,
  `otpVerifiedAt`       DATETIME     DEFAULT NULL,
  `ipAddress`           VARCHAR(45)  DEFAULT NULL,
  `consumedAt`          DATETIME     DEFAULT NULL COMMENT 'Set when step 3 creates the player',
  `expiresAt`           DATETIME     NOT NULL COMMENT 'Whole signup session lifetime, e.g. 30 minutes',
  `createdAt`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signupSession_msisdn` (`msisdn`),
  KEY `idx_signupSession_expires` (`expiresAt`),
  KEY `idx_signupSession_consumed` (`consumedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks the 3-step signup wizard state per phone number.';


-- -----------------------------------------------------------------------------
-- Stored procedure: createPlayerWithWallet
-- -----------------------------------------------------------------------------
-- Atomic creation of a player + their PLAY and PAYOUT accounts + matching
-- wallet rows. Called from the signup complete endpoint (step 3).
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS createPlayerWithWallet;

DELIMITER $$

CREATE PROCEDURE createPlayerWithWallet (
    IN  p_msisdn          VARCHAR(15),
    IN  p_paymentProvider VARCHAR(10),
    IN  p_registeredName  VARCHAR(150),
    IN  p_passwordHash    VARCHAR(255),
    IN  p_email           VARCHAR(150),
    IN  p_channel         VARCHAR(10),
    OUT p_playerId        BIGINT UNSIGNED
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_playAccountId   BIGINT UNSIGNED;
    DECLARE v_payoutAccountId BIGINT UNSIGNED;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    INSERT INTO player (
        msisdn, paymentProvider, registeredName, passwordHash, email,
        kycStatus, accountStatus, registrationChannel, createdAt, updatedAt
    ) VALUES (
        p_msisdn, p_paymentProvider, p_registeredName, p_passwordHash, p_email,
        'pending', 'active', p_channel, NOW(), NOW()
    );
    SET p_playerId = LAST_INSERT_ID();

    INSERT INTO account (
        accountCode, accountType, ownerType, ownerId, currency, normalBalance,
        status, createdAt, updatedAt
    ) VALUES (
        CONCAT('PLAYER_PLAY:', p_playerId), 'PLAYER_PLAY', 'player', p_playerId,
        'GHS', 'debit', 'active', NOW(), NOW()
    );
    SET v_playAccountId = LAST_INSERT_ID();

    INSERT INTO account (
        accountCode, accountType, ownerType, ownerId, currency, normalBalance,
        status, createdAt, updatedAt
    ) VALUES (
        CONCAT('PLAYER_PAYOUT:', p_playerId), 'PLAYER_PAYOUT', 'player', p_playerId,
        'GHS', 'debit', 'active', NOW(), NOW()
    );
    SET v_payoutAccountId = LAST_INSERT_ID();

    INSERT INTO wallet (
        playerId, walletType, accountId, cachedBalancePesewas, version,
        status, createdAt, updatedAt
    ) VALUES (
        p_playerId, 'PLAY', v_playAccountId, 0, 0, 'active', NOW(), NOW()
    );

    INSERT INTO wallet (
        playerId, walletType, accountId, cachedBalancePesewas, version,
        status, createdAt, updatedAt
    ) VALUES (
        p_playerId, 'PAYOUT', v_payoutAccountId, 0, 0, 'active', NOW(), NOW()
    );

    COMMIT;
END$$

DELIMITER ;
