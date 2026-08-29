<?php

declare(strict_types=1);

namespace App\Domain\ResponsibleGaming\Registries;

/**
 * §7.9.2 (REQ-RG-010..018) — one interface per state registry, matched by NIN
 * (REQ-RG-012). "Written for N registries, not one" (REQ-RG-017): RegistryCheckService
 * resolves which implementation to call per state via config, not a hardcoded class.
 */
interface RegistryClient
{
    public function registryName(): string;

    /** $ninHash is the SHA-256 already on player.ninHash — the raw NIN never leaves the vault. */
    public function isExcluded(string $ninHash): bool;
}
