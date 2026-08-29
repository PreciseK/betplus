-- =============================================================================
-- USSD migration 009 — drop redundant `source` columns
--
-- Migration 008 added `source` ENUM('WEB','USSD') to depositRequest and
-- withdrawalRequest. That was a mistake — the live schema already had
-- `channel` ENUM('web','app','ussd') with the same purpose. The USSD app
-- has not yet written to these tables, so dropping `source` is safe.
--
-- Going forward, all USSD-initiated deposits/withdrawals will write
-- channel='ussd' (matching what the web app does with channel='web').
--
-- Idempotent: uses information_schema to check before dropping. Re-running
-- against an already-cleaned schema is a no-op.
-- =============================================================================

-- depositRequest.source
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'depositRequest'
    AND COLUMN_NAME = 'source'
);
SET @sql := IF(@col_exists > 0,
  "ALTER TABLE `depositRequest` DROP COLUMN `source`",
  "SELECT 'depositRequest.source already absent; skipping' AS msg");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- withdrawalRequest.source
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawalRequest'
    AND COLUMN_NAME = 'source'
);
SET @sql := IF(@col_exists > 0,
  "ALTER TABLE `withdrawalRequest` DROP COLUMN `source`",
  "SELECT 'withdrawalRequest.source already absent; skipping' AS msg");
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
