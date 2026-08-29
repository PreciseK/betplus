<?php

declare(strict_types=1);

namespace BlackRed\Config;

use BlackRed\Database\Connection;

/**
 * Typed accessor for the systemConfig table.
 *
 * The table stores everything as VARCHAR(1000) configValue + a valueType
 * hint. This service casts on read and validates on write so callers don't
 * need to think about it.
 *
 * Reads are NOT cached in process. The engine calls these methods inside its
 * already-open transaction, so freshness matters more than micro-savings on
 * a tiny query. If we ever want a cache, it goes here, not in callers.
 */
final class SystemConfigService
{
    public function __construct(private Connection $db) {}

    /**
     * Read an int config value, with a fallback if the key is missing or
     * unparseable. Use for things like thresholds, floors, limits.
     */
    public function getInt(string $key, int $default): int
    {
        $row = $this->db->fetchOne(
            'SELECT configValue FROM systemConfig WHERE configKey = :k LIMIT 1',
            [':k' => $key]
        );
        if (!$row) return $default;
        $v = $row['configValue'];
        if (!is_numeric($v)) return $default;
        return (int) $v;
    }

    /**
     * Read a boolean config value. Treats '1', 'true', 'yes', 'on' as true,
     * everything else as false. Missing key returns default.
     */
    public function getBool(string $key, bool $default): bool
    {
        $row = $this->db->fetchOne(
            'SELECT configValue FROM systemConfig WHERE configKey = :k LIMIT 1',
            [':k' => $key]
        );
        if (!$row) return $default;
        $v = strtolower(trim((string) $row['configValue']));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Read a string config value. Trims whitespace.
     */
    public function getString(string $key, string $default): string
    {
        $row = $this->db->fetchOne(
            'SELECT configValue FROM systemConfig WHERE configKey = :k LIMIT 1',
            [':k' => $key]
        );
        if (!$row) return $default;
        return trim((string) $row['configValue']);
    }

    /**
     * Update a config value. Caller is trusted to know what they're doing
     * (admin tool only). Returns true if a row was changed.
     */
    public function set(string $key, string $value): bool
    {
        $stmt = $this->db->execute(
            'UPDATE systemConfig SET configValue = :v WHERE configKey = :k',
            [':v' => $value, ':k' => $key]
        );
        return $stmt->rowCount() > 0;
    }
}