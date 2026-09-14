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

    /** @return list<string> validation errors; empty means the config may publish */
    public function validate(GameEconomicsConfig $config): array
    {
        if (!in_array($config->activeModel, self::VALID_MODELS, true)) {
            return ["activeModel '{$config->activeModel}' is not one of: " . implode(', ', self::VALID_MODELS) . '.'];
        }

        if ($config->activeModel === 'BALANCED_HYBRID') {
            return $this->validateBalancedHybrid($config);
        }

        // DAILY_LOSS_STOP and PARI_MUTUEL_POOL params are not validated yet,
        // deliberately — their real param shapes (DailyLossStopParams,
        // PariMutuelPoolParams) and enforcement land in Phase 2 and Phase 3 of
        // docs/superpowers/specs/2026-09-13-admin-gaming-economics-models-design.md.
        // Selecting either model today is allowed; EconomicsModelStrategyFactory
        // resolves both to a no-op strategy until then.
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

        return [];
    }
}
