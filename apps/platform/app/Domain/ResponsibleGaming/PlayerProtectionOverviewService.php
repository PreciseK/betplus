<?php

declare(strict_types=1);

namespace App\Domain\ResponsibleGaming;

use App\Models\Player;
use App\Models\PlayerLimit;
use App\Models\PlayerProtectionEvent;
use App\Models\VelocityFlag;

/**
 * Back-office cross-player reads for the three PlayerProtectionConsole tabs. Story
 * 5.x's domain services (LimitsService, ProtectionService) are all per-player by
 * design — this is the first place any of it gets queried across every player.
 */
final class PlayerProtectionOverviewService
{
    /** Money-based limit keys only — session-time has no ledger-based usage to compare against. */
    private const LIMIT_KEYS = ['deposit-daily', 'deposit-weekly', 'deposit-monthly', 'stake-daily', 'stake-weekly'];
    private const NEAR_THRESHOLD_RATIO = 0.8;

    public function __construct(private readonly LimitsService $limits)
    {
    }

    /** @return list<array<string, mixed>> */
    public function reviews(string $status = 'open'): array
    {
        $flags = VelocityFlag::where('status', $status)->orderByDesc('createdAt')->limit(200)->get();
        $players = Player::whereIn('id', $flags->pluck('playerId'))->get()->keyBy('id');

        return $flags->map(fn (VelocityFlag $flag) => [
            'id' => $flag->id,
            'player_id' => $flag->playerId,
            'player_reference' => 'BP-' . $flag->playerId,
            'registered_name' => $players->get($flag->playerId)?->registeredName,
            'game_code' => $flag->gameCode,
            'flag_type' => $flag->flagType,
            'detail' => $flag->detail,
            'status' => $flag->status,
            'created_at' => $flag->createdAt->toIso8601String(),
            'resolved_at' => $flag->resolvedAt?->toIso8601String(),
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function exclusions(): array
    {
        $events = PlayerProtectionEvent::where('endsAt', '>', now())->orderByDesc('startedAt')->limit(200)->get();
        $players = Player::whereIn('id', $events->pluck('playerId'))->get()->keyBy('id');

        return $events->map(fn (PlayerProtectionEvent $event) => [
            'id' => $event->id,
            'player_id' => $event->playerId,
            'player_reference' => 'BP-' . $event->playerId,
            'registered_name' => $players->get($event->playerId)?->registeredName,
            'type' => $event->type,
            'started_at' => $event->startedAt->toIso8601String(),
            'ends_at' => $event->endsAt->toIso8601String(),
        ])->values()->all();
    }

    /**
     * N+1 by design at this data volume (per-player windowed ledger sum has no
     * pre-materialized rollup) — only iterates players who already have a playerLimit
     * row for the key, i.e. have actually touched responsible-gaming limits before.
     *
     * @return list<array<string, mixed>>
     */
    public function limitsNearOrAtThreshold(): array
    {
        $rows = [];

        foreach (self::LIMIT_KEYS as $key) {
            $limits = PlayerLimit::where('limitKey', $key)->get();
            $players = Player::whereIn('id', $limits->pluck('playerId'))->get()->keyBy('id');

            foreach ($limits as $limit) {
                $player = $players->get($limit->playerId);
                if ($player === null || $limit->currentValue <= 0) {
                    continue;
                }

                $usage = $this->limits->usageFor($player, $key);
                $ratio = $usage['spent_kobo'] / $limit->currentValue;
                if ($ratio < self::NEAR_THRESHOLD_RATIO) {
                    continue;
                }

                $rows[] = [
                    'player_id' => $player->id,
                    'player_reference' => 'BP-' . $player->id,
                    'registered_name' => $player->registeredName,
                    'limit_key' => $key,
                    'limit_value' => $limit->currentValue,
                    'spent_kobo' => $usage['spent_kobo'],
                    'unit' => $usage['unit'],
                    'status' => $ratio >= 1.0 ? 'reached' : 'near_threshold',
                ];
            }
        }

        return $rows;
    }
}
