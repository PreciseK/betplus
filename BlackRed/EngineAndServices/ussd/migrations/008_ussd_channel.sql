-- =============================================================================
-- USSD migration 008 — ussdSession table
--
-- Creates the FSM session table for the USSD app. The web app's deposit and
-- withdrawal tables already have a `channel` ENUM('web','app','ussd') column;
-- we use that for channel attribution rather than adding a new column.
--
-- Run order: after the web app's migrations 001-007.
-- Idempotent: re-running against an up-to-date schema is a no-op.
--
-- Historical note: an earlier version of this file also added a `source`
-- column to depositRequest and withdrawalRequest. That was a mistake (the
-- `channel` column already existed for the same purpose). Migration 009
-- drops the redundant `source` column. Run 009 after 008 if you have a
-- database where the old 008 ran.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `ussdSession` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `naloSessionId`   VARCHAR(64)     NOT NULL  COMMENT 'Nalo SESSIONID, unique per dial',
  `msisdn`          VARCHAR(15)     NOT NULL  COMMENT 'Canonical 233XXXXXXXXX form',
  `network`         VARCHAR(20)     DEFAULT NULL COMMENT 'MTN | TELECEL | AIRTELTIGO',
  `playerId`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL until registered',
  `state`           VARCHAR(40)     NOT NULL  COMMENT 'Current FSM state name',
  `data`            JSON            NOT NULL  COMMENT 'Accumulated session fields',
  `pwRetries`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `createdAt`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_naloSessionId` (`naloSessionId`),
  KEY `idx_msisdn` (`msisdn`),
  KEY `idx_updated` (`updatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
