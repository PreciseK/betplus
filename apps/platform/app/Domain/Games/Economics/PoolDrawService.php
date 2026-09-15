<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Models\PoolDraw;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

/**
 * Finds (or opens) the pool a new ticket should join for Model 4 — same
 * find-latest-or-start-fresh shape as RoundLifecycleService::currentOrNextRound(),
 * adapted for a fixed-schedule window instead of a betting/flying/crashed state
 * machine. $poolKey is BlackRed's pick-length (1-5); null for Heritage/Caged.
 */
final class PoolDrawService
{
    public function currentOrNextPool(string $gameCode, ?int $poolKey, int $windowMinutes): PoolDraw
    {
        $latest = PoolDraw::where('gameCode', $gameCode)->where('poolKey', $poolKey)->orderByDesc('id')->first();

        if ($latest === null) {
            return $this->openPool($gameCode, $poolKey, $windowMinutes, rolloverInKobo: 0, tierRolloverJson: null);
        }

        if ($latest->status === 'open' && $latest->closesAt->isFuture()) {
            return $latest;
        }

        if ($latest->status === 'open') {
            // Past closesAt but the draw job hasn't swept it yet — never let a new
            // ticket join a pool that's about to be closed out from under it.
            return $this->openPool($gameCode, $poolKey, $windowMinutes, rolloverInKobo: 0, tierRolloverJson: null);
        }

        // closed or settled — the draw job already opened the next pool as its last
        // step (see PoolDrawSettlementService), so this branch is a defensive
        // fallback for a gap between settlement and the next scheduled open.
        return $this->openPool($gameCode, $poolKey, $windowMinutes, rolloverInKobo: 0, tierRolloverJson: null);
    }

    /**
     * Called both by currentOrNextPool()'s fallback and by settlement once a pool
     * closes, to open the next one with any rollover carried forward. Settlement
     * passes the just-closed pool's own closesAt as $opensAt, so windows are
     * contiguous and — critically — never collide with that pool's own opensAt on
     * the (gameCode, poolKey, opensAt) unique index just because settlement ran
     * moments after the pool opened (same wall-clock second, in a test or a very
     * short configured window). currentOrNextPool()'s bootstrap/gap-filling calls
     * have no prior pool to be contiguous with, so they pass null and get `now()`.
     *
     * ponytail: two concurrent callers opening a pool for the same window with no
     * prior pool to anchor against (both null $opensAt) can still each compute a
     * slightly different `now()` and open two separate pools, fragmenting that
     * window's stakes across both (each still settles and pays out correctly on
     * its own — just diluted). This is the same class of race
     * RoundLifecycleService::startRound() already accepts unmitigated for round
     * creation; here it's rarer still, since settlement (not this fallback) opens
     * the next pool in the normal case. Upgrade path: a per-(gameCode, poolKey)
     * advisory lock around this method, if real duplicate-pool opens ever show up.
     */
    /** @param array<int, int>|null $tierRolloverJson */
    public function openPool(string $gameCode, ?int $poolKey, int $windowMinutes, int $rolloverInKobo, ?array $tierRolloverJson, ?CarbonInterface $opensAt = null): PoolDraw
    {
        $now = $opensAt ?? now();

        try {
            return PoolDraw::create([
                'gameCode' => $gameCode,
                'poolKey' => $poolKey,
                'status' => 'open',
                'opensAt' => $now,
                'closesAt' => $now->clone()->addMinutes($windowMinutes),
                // Explicit, not left to the migration's DB-side default(0) — Eloquent
                // never re-fetches after insert, so an omitted column stays PHP null
                // on this very instance even though the row itself has a real 0.
                'grossStakedKobo' => 0,
                'rakeKobo' => 0,
                'netPoolKobo' => 0,
                'rolloverInKobo' => $rolloverInKobo,
                'tierRolloverJson' => $tierRolloverJson,
            ]);
        } catch (QueryException $e) {
            $existing = PoolDraw::where('gameCode', $gameCode)->where('poolKey', $poolKey)->where('opensAt', $now)->first();
            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
