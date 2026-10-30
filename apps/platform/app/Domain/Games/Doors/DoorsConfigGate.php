<?php

declare(strict_types=1);

namespace App\Domain\Games\Doors;

use App\Domain\Games\Economics\RtpCeiling;
use App\Models\GameEconomicsConfig;

final class DoorsConfigGate
{
    /** @return list<string> */
    public function validate(GameEconomicsConfig $config): array
    {
        $p = $config->paramsJson;
        $d = $p['doors'] ?? [];
        if (!is_array($d)) { return ['Doors rules must be an object.']; }
        if ($config->activeModel !== 'BALANCED_HYBRID') { return ['Doors requires Model 1 (BALANCED_HYBRID).']; }
        if (($d['board_size'] ?? null) !== 20 || ($d['max_attempts'] ?? null) !== 5 || ($d['durations_seconds'] ?? null) !== [10]) {
            return ['Doors must use 20 doors, five attempts, and a 10-second round.'];
        }
        $expected = ['GOLD' => 3, 'CHEST' => 5, 'CARD' => 2];
        if (!isset($d['treasures']) || !is_array($d['treasures']) || count($d['treasures']) !== 3) { return ['Doors requires three treasure tiers.']; }
        $sum = 0; $weighted = 0; $seen = [];
        foreach ($d['treasures'] as $tier) {
            if (!is_array($tier)) { return ['Each Doors treasure tier must be an object.']; }
            $code = $tier['code'] ?? '';
            $weight = $tier['weight_bps'] ?? null;
            if (!is_string($code) || !isset($expected[$code]) || isset($seen[$code]) || ($tier['multiplier'] ?? null) !== $expected[$code] || !is_int($weight) || $weight <= 0 || $weight > 10_000) {
                return ['Doors requires unique GOLD=3x, CHEST=5x, CARD=2x tiers with positive integer weights.'];
            }
            $seen[$code] = true; $sum += $weight; $weighted += $weight * $tier['multiplier'];
        }
        if (count($seen) !== 3 || $sum !== 10_000) { return ['Doors treasure weights must sum to 10000 basis points.']; }
        // One uniformly placed treasure: P(find it in n distinct picks) = n/board_size.
        if ($weighted * $d['max_attempts'] > RtpCeiling::BASIS_POINTS * $d['board_size']) { return ['Doors RTP exceeds the platform ceiling.']; }
        foreach (['float_max_age_seconds', 'reserve_floor_kobo'] as $field) {
            if (!isset($p[$field]) || !is_int($p[$field]) || $p[$field] < 1) { return ["Doors requires a positive integer {$field}."]; }
        }
        if (!is_string($d['certification_ref'] ?? null)) { return ['Doors certification reference must be text.']; }
        $cert = trim($d['certification_ref']);
        if ($cert === '' || (app()->environment('production') && preg_match('/pending|draft|dev.only|test/i', $cert))) {
            return ['Doors requires a certification reference; placeholders cannot be published in production.'];
        }
        return [];
    }
}
