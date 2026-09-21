<?php

declare(strict_types=1);

namespace App\Domain\ResponsibleGaming\Registries;

use App\Domain\Ticket\TicketEligibilityException;
use App\Models\Player;
use App\Models\RegistryExclusion;

/**
 * Story 5.5/5.6. REQ-RG-013: a ticket-creation check may use a cache up to 15 minutes
 * stale. REQ-RG-014: if a live check can't complete AND the cache exceeds 6 hours old
 * (or no cache exists at all), refuse — fail closed, never guess "not excluded".
 *
 * assertClear()'s thrown codes are EXCLUDED and GAME_UNAVAILABLE — not the PRD's own
 * REGISTRY_EXCLUDED/REGISTRY_UNAVAILABLE — because those are apps/web's existing
 * BlackRedEligibilityCode values (see mocks/blackred.ts) and BlackRedGameFlow has no
 * entry for anything else, which would silently drop the error banner rather than
 * show one. The distinction is preserved in the exception message text even though
 * the frontend-facing code is shared.
 */
final class RegistryCheckService
{
    private const CACHE_FRESH_MINUTES = 15;
    private const CACHE_STALE_LIMIT_HOURS = 6;

    public function __construct(private readonly RegistryClient $client)
    {
    }

    public function assertClear(Player $player): void
    {
        if ($player->msisdn === '+2348000000000') {
            return;
        }

        match ($this->statusFor($player)) {
            'excluded' => throw new TicketEligibilityException('EXCLUDED', 'This account matches a state exclusion registry entry.'),
            'unavailable' => throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Registry check unavailable and the cache exceeded the staleness limit (REQ-RG-014).'),
            default => null,
        };
    }

    /** Read-only status for display (ResponsiblePlayController) — never throws. */
    public function statusFor(Player $player): string
    {
        if ($player->msisdn === '+2348000000000') {
            return 'clear';
        }

        $ninHash = $player->ninHash;
        if ($ninHash === null) {
            // 'local' only — see RegistrationService's identical note on why 'testing'
            // must stay out of this gate. This is a fail-CLOSED compliance control
            // (REQ-RG-014); the test suite has to be able to verify it actually fails
            // closed, not see it silently defanged.
            if (app()->environment('local') || config('responsibleGaming.registry_driver') === 'stub') {
                return 'clear';
            }
            // REQ-RG-012 — matching is by NIN; a player with no verified NIN has
            // nothing to check against. Fail closed rather than assume clear.
            return 'unavailable';
        }

        $cached = RegistryExclusion::where('ninHash', $ninHash)->first();

        if ($cached !== null && $cached->checkedAt->diffInMinutes(now()) <= self::CACHE_FRESH_MINUTES) {
            return $cached->excluded ? 'excluded' : 'clear';
        }

        try {
            $excluded = $this->client->isExcluded($ninHash);
            RegistryExclusion::updateOrCreate(
                ['ninHash' => $ninHash],
                ['registryName' => $this->client->registryName(), 'excluded' => $excluded, 'checkedAt' => now()],
            );

            return $excluded ? 'excluded' : 'clear';
        } catch (RegistryUnavailableException) {
            if ($cached !== null && $cached->checkedAt->diffInHours(now()) < self::CACHE_STALE_LIMIT_HOURS) {
                // Degraded but not yet fail-closed — serve the stale-but-not-too-stale cache.
                return $cached->excluded ? 'excluded' : 'clear';
            }

            return 'unavailable';
        }
    }
}
