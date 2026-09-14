<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\PrizeTable\PrizeTablePublicationGate;
use App\Models\PrizeTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PrizeTablePublicationGateTest extends TestCase
{
    use RefreshDatabase;

    private function tableWithTiers(array $tiers, ?string $actuarialCertRef = 'CERT-123'): PrizeTable
    {
        $table = PrizeTable::create([
            'gameCode' => 'BLACKRED',
            'stateCode' => null,
            'version' => 'TEST-1',
            'status' => 'draft',
            'effectiveAt' => now(),
            'actuarialCertRef' => $actuarialCertRef,
        ]);
        foreach ($tiers as $tier) {
            $table->tiers()->create($tier);
        }

        return $table->refresh();
    }

    public function test_the_good_presets_tiers_1_and_2_now_fail_the_8800bp_ceiling(): void
    {
        // Tier 1 = 92.50% gross RTP, tier 2 = 90.00% gross RTP — both cleared the old
        // 9500bp ceiling but exceed the tightened 8800bp ceiling (RtpCeiling::BASIS_POINTS,
        // 2026-09-14). See the ⚠ note on PrizeTablePresetLibrary's "good" preset: a new
        // "good"-preset draft using these tiers must fail here until Finance/actuary
        // recalibrates the multipliers — this is not a bug to silently fix.
        $table = $this->tableWithTiers([
            ['positions' => 1, 'multiplierHundredths' => 185, 'probabilityNumerator' => 1, 'probabilityDenominator' => 2],
            ['positions' => 2, 'multiplierHundredths' => 360, 'probabilityNumerator' => 1, 'probabilityDenominator' => 4],
        ]);

        $errors = app(PrizeTablePublicationGate::class)->validate($table, 500);

        $this->assertSame([
            'Tier 1: gross RTP 9250bp exceeds the 8800bp ceiling.',
            'Tier 2: gross RTP 9000bp exceeds the 8800bp ceiling.',
        ], $errors);
    }

    public function test_rejects_a_tier_whose_probability_is_not_the_fair_coin_value(): void
    {
        // 1-position tier claiming 1-in-4 instead of the correct 1-in-2.
        $table = $this->tableWithTiers([
            ['positions' => 1, 'multiplierHundredths' => 185, 'probabilityNumerator' => 1, 'probabilityDenominator' => 4],
        ]);

        $errors = app(PrizeTablePublicationGate::class)->validate($table, 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('fair-coin', $errors[0]);
    }

    public function test_rejects_a_tier_whose_gross_rtp_exceeds_the_95_percent_ceiling(): void
    {
        // 1-in-2 chance at a 2.5x multiplier is 125% RTP — well above the ceiling.
        $table = $this->tableWithTiers([
            ['positions' => 1, 'multiplierHundredths' => 250, 'probabilityNumerator' => 1, 'probabilityDenominator' => 2],
        ]);

        $errors = app(PrizeTablePublicationGate::class)->validate($table, 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('exceeds the 8800bp ceiling', implode(' ', $errors));
    }

    public function test_rejects_a_table_with_no_actuarial_certification_reference(): void
    {
        $table = $this->tableWithTiers(
            [['positions' => 1, 'multiplierHundredths' => 185, 'probabilityNumerator' => 1, 'probabilityDenominator' => 2]],
            actuarialCertRef: null,
        );

        $errors = app(PrizeTablePublicationGate::class)->validate($table, 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('actuarial', implode(' ', $errors));
    }

    public function test_rejects_a_table_with_no_tiers(): void
    {
        $table = $this->tableWithTiers([]);

        $errors = app(PrizeTablePublicationGate::class)->validate($table, 500);

        $this->assertSame(['Prize table has no tiers.'], $errors);
    }
}
