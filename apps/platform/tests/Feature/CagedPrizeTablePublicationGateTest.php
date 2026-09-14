<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\PrizeTable\CagedPrizeTablePublicationGate;
use App\Models\PrizeTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CagedPrizeTablePublicationGateTest extends TestCase
{
    use RefreshDatabase;

    private function table(array $tiers, ?string $actuarialCertRef = 'CERT-1'): PrizeTable
    {
        $table = PrizeTable::create([
            'gameCode' => 'CAGED', 'stateCode' => null, 'version' => 'test',
            'status' => 'draft', 'effectiveAt' => now(), 'actuarialCertRef' => $actuarialCertRef,
        ]);
        foreach ($tiers as $tier) {
            $table->tiers()->create($tier);
        }

        return $table->fresh(['tiers']);
    }

    private function launchTiers(): array
    {
        return [
            ['positions' => 1, 'multiplierHundredths' => 125, 'probabilityNumerator' => 7180, 'probabilityDenominator' => 10_000],
            ['positions' => 2, 'multiplierHundredths' => 190, 'probabilityNumerator' => 4620, 'probabilityDenominator' => 10_000],
            ['positions' => 3, 'multiplierHundredths' => 380, 'probabilityNumerator' => 2310, 'probabilityDenominator' => 10_000],
            ['positions' => 4, 'multiplierHundredths' => 750, 'probabilityNumerator' => 1140, 'probabilityDenominator' => 10_000],
            ['positions' => 5, 'multiplierHundredths' => 1800, 'probabilityNumerator' => 480, 'probabilityDenominator' => 10_000],
        ];
    }

    public function test_the_launch_tables_tier_1_now_fails_the_8800bp_ceiling(): void
    {
        // Tier 1 gross RTP = 71.80% x 1.25x = 89.75% — cleared the old 9500bp ceiling
        // but exceeds the tightened 8800bp ceiling (RtpCeiling::BASIS_POINTS,
        // 2026-09-14). Tiers 2-5 (87.78%, 87.78%, 85.50%, 86.40%) stay under it.
        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($this->launchTiers()), 500);

        $this->assertSame([
            'Tier 1: gross RTP 8975bp exceeds the 8800bp ceiling.',
        ], $errors);
    }

    public function test_it_rejects_a_missing_tier(): void
    {
        $tiers = array_slice($this->launchTiers(), 0, 4); // drop target 5
        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($tiers), 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Missing tier for target 5 birds', implode(' ', $errors));
    }

    public function test_it_rejects_a_tier_probability_that_does_not_match_the_documented_value(): void
    {
        $tiers = $this->launchTiers();
        $tiers[0]['probabilityNumerator'] = 7000; // should be 7180

        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($tiers), 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not match the documented', implode(' ', $errors));
    }

    public function test_it_rejects_the_launch_tables_tier_1_rtp_now_over_the_8800bp_ceiling(): void
    {
        // Same launch tiers as above — tier 1's gross RTP (89.75%) exceeds the
        // tightened 8800bp ceiling (RtpCeiling::BASIS_POINTS, 2026-09-14).
        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($this->launchTiers()), 500);

        $this->assertSame([
            'Tier 1: gross RTP 8975bp exceeds the 8800bp ceiling.',
        ], $errors);
    }

    public function test_it_rejects_a_table_whose_gross_rtp_exceeds_the_ceiling(): void
    {
        $tiers = $this->launchTiers();
        $tiers[4]['multiplierHundredths'] = 40_000; // an absurdly rich 5-bird multiplier

        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($tiers), 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ceiling', implode(' ', $errors));
    }

    public function test_it_rejects_a_table_with_no_actuarial_certification_reference(): void
    {
        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($this->launchTiers(), null), 500);

        $this->assertContains('No actuarial certification reference recorded (REQ-GEC-025).', $errors);
    }
}
