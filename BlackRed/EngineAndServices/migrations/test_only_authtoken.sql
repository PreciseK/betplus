-- =============================================================================
-- TEST-ONLY MIGRATION
-- =============================================================================
--
-- This file recreates the `authtoken` table that exists in production but
-- might not exist in a fresh test database. Only run this against test DBs.
--
-- The schema mirrors the live USSD endpoint's CREATE statement exactly.
-- DO NOT apply this to production — the USSD already created it there.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `authtoken` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `phonenumber` VARCHAR(20) NOT NULL,
  `codehash` VARCHAR(255) NOT NULL,
  `expirydate` DATE NOT NULL,
  `expirytime` TIME NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `device_id` VARCHAR(100) NULL,
  `request_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_phone` (`phonenumber`),
  INDEX `idx_expiry` (`expirydate`, `expirytime`)
);
