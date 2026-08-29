<?php

declare(strict_types=1);

namespace App\Domain\ResponsibleGaming;

use App\Domain\Ticket\TicketEligibilityException;
use App\Models\Player;
use App\Models\PlayerProtectionEvent;
use InvalidArgumentException;

/**
 * Stories 5.2/5.3 — a cool-off (24h/7d/30d) and self-exclusion (minimum 6 months) are
 * both "block play and deposit for a period; withdrawal stays available" mechanically,
 * differing only in duration options and irreversibility framing. self-exclusion's
 * "irreversible for the period" (REQ-RG-005) is enforced by construction here: nothing
 * in this class — or anywhere else in the codebase — exposes a way to shorten or
 * cancel an active protection event.
 */
final class ProtectionService
{
    /** @return list<array{id:string,label:string,detail:string,durationHours:int}> */
    public function coolOffOptions(): array
    {
        return [
            ['id' => 'cool-off-24h', 'label' => '24 hours', 'detail' => 'Until this time tomorrow', 'durationHours' => 24],
            ['id' => 'cool-off-7d', 'label' => '7 days', 'detail' => 'One full week', 'durationHours' => 24 * 7],
            ['id' => 'cool-off-30d', 'label' => '30 days', 'detail' => 'Thirty full days', 'durationHours' => 24 * 30],
        ];
    }

    /** @return list<array{id:string,label:string,detail:string,durationHours:int}> */
    public function selfExclusionOptions(): array
    {
        // REQ-RG-005 — minimum 6 months; the option set never offers less.
        return [
            ['id' => 'exclude-6m', 'label' => '6 months', 'detail' => 'Minimum exclusion period', 'durationHours' => 24 * 183],
            ['id' => 'exclude-1y', 'label' => '1 year', 'detail' => 'Twelve months', 'durationHours' => 24 * 365],
            ['id' => 'exclude-5y', 'label' => '5 years', 'detail' => 'Long-term exclusion', 'durationHours' => 24 * 365 * 5],
        ];
    }

    public function startCoolOff(Player $player, string $optionId): PlayerProtectionEvent
    {
        return $this->start($player, 'cool-off', $this->findOption($this->coolOffOptions(), $optionId));
    }

    public function selfExclude(Player $player, string $optionId): PlayerProtectionEvent
    {
        return $this->start($player, 'self-exclusion', $this->findOption($this->selfExclusionOptions(), $optionId));
    }

    /** Null means no active protection — the caller falls through to other status checks (registry, velocity). */
    public function activeEventFor(Player $player): ?PlayerProtectionEvent
    {
        return PlayerProtectionEvent::where('playerId', $player->id)
            ->where('endsAt', '>', now())
            ->orderByDesc('endsAt')
            ->first();
    }

    /**
     * REQ-RG-004/005 — both cool-off and self-exclusion block play and deposit, so
     * both map to the frontend's single "player-protection status" error code
     * (BlackRedEligibilityCode's EXCLUDED) — LIMIT_REACHED is reserved for a numeric
     * stake/deposit limit (LimitsService), a distinct condition from a timed block.
     */
    public function assertPlayAndDepositAllowed(Player $player): void
    {
        if ($this->activeEventFor($player) !== null) {
            throw new TicketEligibilityException('EXCLUDED', 'A cool-off or self-exclusion is currently active on this account.');
        }
    }

    /** @param array{id:string,durationHours:int} $option */
    private function start(Player $player, string $type, array $option): PlayerProtectionEvent
    {
        $startedAt = now();

        return PlayerProtectionEvent::create([
            'playerId' => $player->id,
            'type' => $type,
            'startedAt' => $startedAt,
            'endsAt' => $startedAt->clone()->addHours($option['durationHours']),
        ]);
    }

    /**
     * @param list<array{id:string,label:string,detail:string,durationHours:int}> $options
     * @return array{id:string,durationHours:int}
     */
    private function findOption(array $options, string $optionId): array
    {
        foreach ($options as $option) {
            if ($option['id'] === $optionId) {
                return $option;
            }
        }

        throw new InvalidArgumentException('DURATION_INVALID');
    }
}
