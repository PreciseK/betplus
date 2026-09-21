<?php

declare(strict_types=1);

namespace App\Domain\ResponsibleGaming;

use App\Domain\Ticket\TicketEligibilityException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Player;
use App\Models\PlayerLimit;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Story 5.1 (REQ-RG-002/003). Limits apply across every game combined — there is no
 * per-game scoping anywhere in this class. A reduction takes effect on currentValue
 * immediately; an increase is held in pendingValue/pendingEffectiveAt for 24 hours so
 * the decision to raise a limit is never made in the moment (REQ-RG-003).
 */
final class LimitsService
{
    private const KEYS = ['deposit-daily', 'deposit-weekly', 'deposit-monthly', 'stake-daily', 'stake-weekly', 'session-time'];
    private const DEFAULT_KOBO = [
        'deposit-daily' => 250_000_000,     // ₦2,500,000 (2.5 million Naira)
        'deposit-weekly' => 1_000_000_000,  // ₦10,000,000 (10 million Naira)
        'deposit-monthly' => 2_500_000_000, // ₦25,000,000 (25 million Naira)
        'stake-daily' => 250_000_000,       // ₦2,500,000 (2.5 million Naira)
        'stake-weekly' => 1_000_000_000,    // ₦10,000,000 (10 million Naira)
    ];
    private const DEFAULT_SESSION_MINUTES = 60;
    private const INCREASE_DELAY_HOURS = 24;

    /** @return list<PlayerLimit> */
    public function limitsFor(Player $player): array
    {
        return array_map(fn (string $key) => $this->limitOrDefault($player, $key), self::KEYS);
    }

    public function updateLimit(Player $player, string $key, int $value): PlayerLimit
    {
        if (!in_array($key, self::KEYS, true) || $value <= 0) {
            throw new InvalidArgumentException('LIMIT_INVALID');
        }

        $limit = $this->limitOrDefault($player, $key);

        // REQ-RG-003 — compare the REQUESTED value against the currently-active
        // (already-effective) value, never against a stale pending one, so two
        // successive reductions each apply immediately.
        if ($value <= $limit->currentValue) {
            $limit->currentValue = $value;
            $limit->pendingValue = null;
            $limit->pendingEffectiveAt = null;
        } else {
            $limit->pendingValue = $value;
            $limit->pendingEffectiveAt = now()->addHours(self::INCREASE_DELAY_HOURS);
        }
        $limit->save();

        return $limit;
    }

    /**
     * Applies any pending increase whose delay has elapsed. Called opportunistically
     * (on read and before enforcement) rather than by a scheduled sweep — a limit that
     * only matters when the player is actually active doesn't need a background job.
     */
    private function limitOrDefault(Player $player, string $key): PlayerLimit
    {
        $limit = PlayerLimit::firstOrCreate(
            ['playerId' => $player->id, 'limitKey' => $key],
            [
                'unit' => $key === 'session-time' ? 'minutes' : 'kobo',
                'currentValue' => $key === 'session-time' ? self::DEFAULT_SESSION_MINUTES : self::DEFAULT_KOBO[$key],
            ],
        );

        if ($limit->pendingEffectiveAt !== null && $limit->pendingEffectiveAt->isPast()) {
            $limit->currentValue = $limit->pendingValue;
            $limit->pendingValue = null;
            $limit->pendingEffectiveAt = null;
            $limit->save();
        }

        return $limit;
    }

    /**
     * Throws TicketEligibilityException('LIMIT_REACHED', ...) if adding $amountKobo
     * would breach any active window — LIMIT_REACHED matches apps/web's existing
     * BlackRedEligibilityCode (mocks/blackred.ts); REQ-RG-002's own error code
     * (RG_LIMIT_EXCEEDED) has no entry in BlackRedGameFlow's copy map.
     */
    public function assertStakeWithinLimits(Player $player, int $amountKobo): void
    {
        $this->assertWithinWindow($player, 'stake-daily', now()->startOfDay(), $amountKobo);
        $this->assertWithinWindow($player, 'stake-weekly', now()->startOfWeek(), $amountKobo);
    }

    public function assertDepositWithinLimits(Player $player, int $amountKobo): void
    {
        $this->assertWithinWindow($player, 'deposit-daily', now()->startOfDay(), $amountKobo);
        $this->assertWithinWindow($player, 'deposit-weekly', now()->startOfWeek(), $amountKobo);
        $this->assertWithinWindow($player, 'deposit-monthly', now()->startOfMonth(), $amountKobo);
    }

    private function assertWithinWindow(Player $player, string $key, CarbonInterface $since, int $amountKobo): void
    {
        $limit = $this->limitOrDefault($player, $key);
        $spentSoFar = $this->spentSince($player, $key, $since);

        if ($spentSoFar + $amountKobo > $limit->currentValue) {
            $limitNaira = number_format($limit->currentValue / 100, 0);
            $friendlyName = match ($key) {
                'stake-daily' => 'daily stake limit',
                'stake-weekly' => 'weekly stake limit',
                'deposit-daily' => 'daily deposit limit',
                'deposit-weekly' => 'weekly deposit limit',
                'deposit-monthly' => 'monthly deposit limit',
                default => "$key limit",
            };
            throw new TicketEligibilityException(
                'LIMIT_REACHED',
                "You have reached your {$friendlyName} of ₦{$limitNaira}. You can review or adjust your limits anytime in Account Settings."
            );
        }
    }

    /**
     * Public so PlayerProtectionOverviewService (back-office "near/at threshold" view)
     * reuses the exact same windowed-spend computation as real enforcement, rather than
     * a second copy that could silently drift from what actually blocks a stake/deposit.
     *
     * @return array{spent_kobo: int, limit_value: int, unit: string}
     */
    public function usageFor(Player $player, string $key): array
    {
        $limit = $this->limitOrDefault($player, $key);

        return [
            'spent_kobo' => $this->spentSince($player, $key, $this->windowStartFor($key)),
            'limit_value' => $limit->currentValue,
            'unit' => $limit->unit,
        ];
    }

    private function windowStartFor(string $key): CarbonInterface
    {
        return match ($key) {
            'deposit-weekly', 'stake-weekly' => now()->startOfWeek(),
            'deposit-monthly' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };
    }

    private function spentSince(Player $player, string $key, CarbonInterface $since): int
    {
        $referenceType = str_starts_with($key, 'deposit') ? 'collection' : 'ticket';
        $direction = str_starts_with($key, 'deposit') ? 'credit' : 'debit';

        $account = LedgerAccount::where('type', 'PLAYER_PLAY')->where('scope', (string) $player->id)->first();

        return $account === null ? 0 : (int) LedgerEntry::where('accountId', $account->id)
            ->where('direction', $direction)->where('referenceType', $referenceType)
            ->where('createdAt', '>=', $since)->sum('amountKobo');
    }
}
