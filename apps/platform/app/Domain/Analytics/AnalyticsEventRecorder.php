<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\Player;
use InvalidArgumentException;

/**
 * Story 6.10 (REQ-ANL-001..003/005). Every call site is inside Domain/ — this is what
 * makes money/outcome events "emitted server-side" (REQ-ANL-002) true by construction
 * rather than by convention; nothing in apps/web calls this, because apps/web can't
 * reach it (it's not part of any player-facing API response).
 */
final class AnalyticsEventRecorder
{
    /**
     * REQ-ANL-003 — a hard stop, not a style guideline: these keys can never appear in
     * a properties payload, because a caller passing one is the exact mistake this
     * exists to catch before it becomes a stored, 25-month-retained record.
     */
    private const DENIED_PROPERTY_KEYS = ['msisdn', 'phone', 'nin', 'bvn', 'date_of_birth', 'dob', 'latitude', 'longitude', 'lat', 'lng', 'address'];

    /** @param array<string, mixed> $properties */
    public function record(
        string $eventName,
        ?Player $player,
        string $channel,
        ?string $sessionId = null,
        ?string $gameCode = null,
        ?string $stateCode = null,
        array $properties = [],
        ?string $appVersion = null,
    ): AnalyticsEvent {
        foreach (array_keys($properties) as $key) {
            if (in_array(strtolower((string) $key), self::DENIED_PROPERTY_KEYS, true)) {
                throw new InvalidArgumentException("Analytics property '$key' looks like raw PII and is refused (REQ-ANL-003).");
            }
        }

        return AnalyticsEvent::create([
            'eventName' => $eventName,
            'occurredAt' => now(),
            'playerIdHash' => $player === null ? null : $this->pseudonymise($player),
            'sessionId' => $sessionId,
            'channel' => $channel,
            'appVersion' => $appVersion,
            'gameCode' => $gameCode,
            'stateCode' => $stateCode,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }

    /**
     * A stable, non-reversible pseudonym — the same player always hashes to the same
     * value (so a funnel can be computed across events for "the same anonymous
     * someone"), but the hash alone cannot be turned back into a player id without the
     * app key, and never carries a name, MSISDN or any other identifier itself.
     */
    private function pseudonymise(Player $player): string
    {
        return hash_hmac('sha256', (string) $player->id, (string) config('app.key'));
    }
}
