<?php

declare(strict_types=1);

namespace App\Domain\ResponsibleGaming\Registries;

/**
 * No state registry is contracted yet (mirrors the identity-vendor and geolocation-
 * vendor situations elsewhere in this codebase) — always reports "not excluded" so the
 * rest of the pipeline (caching, staleness, fail-closed refusal) is genuinely
 * exercised in dev and staging without a real integration.
 */
final class StubRegistryClient implements RegistryClient
{
    public function registryName(): string
    {
        return 'SafePlay Lagos';
    }

    public function isExcluded(string $ninHash): bool
    {
        return false;
    }
}
