<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\PrizeTable\HeritagePrizeTablePublicationGate;
use App\Models\PrizeTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HeritagePrizeTablePublicationGateTest extends TestCase
{
    use RefreshDatabase;

    private function table(array $tiers, ?string $actuarialCertRef = 'CERT-1'): PrizeTable
    {
        $table = PrizeTable::create([
            'gameCode' => 'HERITAGE', 'stateCode' => null, 'version' => 'test',
            'status' => 'draft', 'effectiveAt' => now(), 'actuarialCertRef' => $actuarialCertRef,
        ]);
        foreach ($tiers as $tier) {
            $table->heritageTiers()->create($tier);
        }

        return $table->fresh(['heritageTiers']);
    }

    private function launchTiers(): array
    {
        return [
            ['tierName' => 'TIER_JACKPOT', 'probabilityBasisPoints' => 200, 'multiplierHundredths' => 2_500, 'outcomeType' => 'cash'],
            ['tierName' => 'TIER_HIGH', 'probabilityBasisPoints' => 600, 'multiplierHundredths' => 50, 'outcomeType' => 'cash'],
            ['tierName' => 'TIER_LOSS', 'probabilityBasisPoints' => 9_200, 'multiplierHundredths' => 0, 'outcomeType' => 'none'],
        ];
    }

    public function test_the_launch_table_passes_with_no_errors(): void
    {
        $errors = app(HeritagePrizeTablePublicationGate::class)->validate($this->table($this->launchTiers()), 500);

        $this->assertSame([], $errors);
    }

    public function test_it_rejects_a_missing_tier(): void
    {
        $tiers = array_slice($this->launchTiers(), 0, 2);
        $errors = app(HeritagePrizeTablePublicationGate::class)->validate($this->table($tiers), 500);

        $this->assertNotEmpty($errors);
    }

    public function test_it_rejects_probabilities_not_summing_to_10000_basis_points(): void
    {
        $tiers = $this->launchTiers();
        $tiers[0]['probabilityBasisPoints'] = 199;
        $errors = app(HeritagePrizeTablePublicationGate::class)->validate($this->table($tiers), 500);

        $this->assertNotEmpty($errors);
    }

    public function test_it_computes_gross_rtp(): void
    {
        // 5000 + 300 = 5300bp (53.0%) — well under the 9500bp ceiling.
        $errors = app(HeritagePrizeTablePublicationGate::class)->validate($this->table($this->launchTiers()), 0);

        $this->assertSame([], $errors);
    }

    public function test_it_rejects_a_table_whose_gross_rtp_exceeds_the_ceiling(): void
    {
        $tiers = $this->launchTiers();
        $tiers[0]['multiplierHundredths'] = 40_000; // an absurdly rich jackpot multiplier
        $errors = app(HeritagePrizeTablePublicationGate::class)->validate($this->table($tiers), 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ceiling', $errors[0]);
    }

    public function test_it_rejects_a_table_with_no_actuarial_certification_reference(): void
    {
        $errors = app(HeritagePrizeTablePublicationGate::class)->validate($this->table($this->launchTiers(), null), 500);

        $this->assertContains('No actuarial certification reference recorded (REQ-GEC-025).', $errors);
    }
}
