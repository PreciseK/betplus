<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Models\GameEconomicsConfig;

/**
 * The structural half of GameEconomicsConfig's publication gate, same spirit as
 * PrizeTablePublicationGate/CrashConfigPublicationGate. This config doesn't carry
 * odds itself (the RTP ceiling is enforced where odds actually live — the four
 * PrizeTable/CrashConfig gates), so this only validates that the chosen model's
 * param shape is sane.
 */
final class GameEconomicsModelGate
{
    private const VALID_MODELS = ['FIXED_RTP', 'BALANCED_HYBRID', 'DAILY_LOSS_STOP', 'PARI_MUTUEL_POOL'];
    private const MIN_KELLY_FACTOR_BASIS_POINTS = 1;
    // 20% of the current float — generous headroom above the 1-5% (100-500bp) the
    // design spec suggests as a working default, without leaving the field unbounded.
    private const MAX_KELLY_FACTOR_BASIS_POINTS = 2_000;
    private const MIN_DAILY_LOSS_CAP_KOBO = 1;
    // 50% of a day's net GGR — a hard ceiling on the reserve siphon so a misconfigured
    // value can't starve HOUSE_REVENUE.
    private const MAX_RESERVE_SIPHON_BPS = 5_000;
    // A pari-mutuel pool pays out exactly pool x (1 - rake%) every draw, with no
    // per-draw variance in the aggregate — so the 8800bp RTP ceiling is enforced as a
    // floor on the rake instead of a ceiling on a designed multiplier.
    private const MIN_PARI_MUTUEL_RAKE_BPS = 10_000 - RtpCeiling::BASIS_POINTS;
    private const MIN_POOL_WINDOW_MINUTES = 1;
    private const TIER_ALLOCATION_TOTAL_BPS = 10_000;

    /** @return list<string> validation errors; empty means the config may publish */
    public function validate(GameEconomicsConfig $config): array
    {
        if (!in_array($config->activeModel, self::VALID_MODELS, true)) {
            return ["activeModel '{$config->activeModel}' is not one of: " . implode(', ', self::VALID_MODELS) . '.'];
        }

        if ($config->activeModel === 'BALANCED_HYBRID') {
            return $this->validateBalancedHybrid($config);
        }

        if ($config->activeModel === 'DAILY_LOSS_STOP') {
            return $this->validateDailyLossStop($config);
        }

        if ($config->activeModel === 'PARI_MUTUEL_POOL') {
            return $this->validatePariMutuelPool($config);
        }

        return [];
    }

    /** @return list<string> */
    private function validateBalancedHybrid(GameEconomicsConfig $config): array
    {
        $kellyFactorBasisPoints = $config->paramsJson['kelly_factor_basis_points'] ?? null;

        if (!is_int($kellyFactorBasisPoints)) {
            return ['BALANCED_HYBRID requires an integer kelly_factor_basis_points param.'];
        }

        if ($kellyFactorBasisPoints < self::MIN_KELLY_FACTOR_BASIS_POINTS || $kellyFactorBasisPoints > self::MAX_KELLY_FACTOR_BASIS_POINTS) {
            return ["kelly_factor_basis_points {$kellyFactorBasisPoints} is outside the sane " . self::MIN_KELLY_FACTOR_BASIS_POINTS . '-' . self::MAX_KELLY_FACTOR_BASIS_POINTS . ' range.'];
        }

        // reserve_siphon_bps is optional (0 / absent means no siphon); only its
        // range is validated when present.
        if (array_key_exists('reserve_siphon_bps', $config->paramsJson)) {
            $reserveSiphonBps = $config->paramsJson['reserve_siphon_bps'];

            if (!is_int($reserveSiphonBps)) {
                return ['reserve_siphon_bps must be an integer when present.'];
            }

            if ($reserveSiphonBps < 0 || $reserveSiphonBps > self::MAX_RESERVE_SIPHON_BPS) {
                return ["reserve_siphon_bps {$reserveSiphonBps} is outside the sane 0-" . self::MAX_RESERVE_SIPHON_BPS . ' range.'];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function validateDailyLossStop(GameEconomicsConfig $config): array
    {
        $dailyLossCapKobo = $config->paramsJson['daily_loss_cap_kobo'] ?? null;

        if (!is_int($dailyLossCapKobo)) {
            return ['DAILY_LOSS_STOP requires an integer daily_loss_cap_kobo param.'];
        }

        if ($dailyLossCapKobo < self::MIN_DAILY_LOSS_CAP_KOBO) {
            return ["daily_loss_cap_kobo {$dailyLossCapKobo} must be at least " . self::MIN_DAILY_LOSS_CAP_KOBO . '.'];
        }

        return [];
    }

    /** @return list<string> */
    private function validatePariMutuelPool(GameEconomicsConfig $config): array
    {
        $rakeBps = $config->paramsJson['rake_bps'] ?? null;
        if (!is_int($rakeBps)) {
            return ['PARI_MUTUEL_POOL requires an integer rake_bps param.'];
        }
        if ($rakeBps < self::MIN_PARI_MUTUEL_RAKE_BPS || $rakeBps > 10_000) {
            return ["rake_bps {$rakeBps} must be at least " . self::MIN_PARI_MUTUEL_RAKE_BPS . " (the RTP ceiling's floor) and at most 10000."];
        }

        $poolWindowMinutes = $config->paramsJson['pool_window_minutes'] ?? null;
        if (!is_int($poolWindowMinutes) || $poolWindowMinutes < self::MIN_POOL_WINDOW_MINUTES) {
            return ['pool_window_minutes must be an integer of at least ' . self::MIN_POOL_WINDOW_MINUTES . '.'];
        }

        // Heritage keeps its tiered payout structure as pool sub-allocations; BlackRed
        // and Caged have no tiers and never validate this key.
        if ($config->gameCode === 'HERITAGE') {
            $tiers = $config->paramsJson['tier_allocation_bps'] ?? null;
            if (!is_array($tiers)) {
                return ['HERITAGE PARI_MUTUEL_POOL requires a tier_allocation_bps object (keys "2".."5").'];
            }

            $sum = 0;
            foreach (['2', '3', '4', '5'] as $tierKey) {
                $value = $tiers[$tierKey] ?? null;
                if (!is_int($value) || $value < 0) {
                    return ["tier_allocation_bps.{$tierKey} must be a non-negative integer."];
                }
                $sum += $value;
            }

            if ($sum !== self::TIER_ALLOCATION_TOTAL_BPS) {
                return ["tier_allocation_bps must sum to " . self::TIER_ALLOCATION_TOTAL_BPS . ", got {$sum}."];
            }
        }

        return [];
    }
}
