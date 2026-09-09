<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Games\PrizeTable\CagedPrizeTablePublicationGate;
use App\Models\ExclusionRegistry;
use App\Models\GameRegistry;
use App\Models\PrizeTable;
use App\Models\StateLicence;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The minimum configuration Caged needs to be playable in dev and staging,
 * mirroring BlackRedGameSeeder's shape exactly. Tiers are the doc's "Escape
 * Count" table (docs/caged-ussd-complete-flows.md §3) — positions column
 * reused to mean targetBirds (1-5), same generic prizeTableTier shape
 * BlackRed uses (see CagedPrizeTablePublicationGate's doc comment).
 */
class CagedGameSeeder extends Seeder
{
    public function run(): void
    {
        GameRegistry::updateOrCreate(
            ['gameCode' => 'CAGED'],
            [
                'engineVersion' => 'caged-1.0.0',
                'status' => 'ACTIVE',
                'minStakeKobo' => 10_000,
                'maxStakeKobo' => 2_000_000,
                'enabledChannels' => ['web', 'app', 'ussd'],
                'enabledStates' => ['LAG'],
            ],
        );

        ExclusionRegistry::updateOrCreate(
            ['stateCode' => 'LAG'],
            ['registryName' => 'SafePlay Lagos', 'configuredAt' => now()],
        );

        StateLicence::updateOrCreate(
            ['stateCode' => 'LAG'],
            [
                'licenceNumber' => 'PENDING-LICENCE-NUMBER',
                'issuedAt' => now()->subYear(),
                'expiresAt' => now()->addYear(),
                'rulesetVersion' => (string) config('jurisdiction.ruleset_version', '2026.1'),
            ],
        );

        $table = PrizeTable::updateOrCreate(
            ['gameCode' => 'CAGED', 'stateCode' => null, 'version' => 'CG-NG-2026.1'],
            [
                'status' => 'draft',
                'effectiveAt' => now()->subDay(),
                'actuarialCertRef' => 'PENDING-ACTUARIAL-CERT — placeholder, not a real certification',
            ],
        );

        $table->tiers()->delete();
        $tiers = [
            ['positions' => 1, 'multiplierHundredths' => 125, 'probabilityNumerator' => 7180, 'probabilityDenominator' => 10_000],
            ['positions' => 2, 'multiplierHundredths' => 190, 'probabilityNumerator' => 4620, 'probabilityDenominator' => 10_000],
            ['positions' => 3, 'multiplierHundredths' => 380, 'probabilityNumerator' => 2310, 'probabilityDenominator' => 10_000],
            ['positions' => 4, 'multiplierHundredths' => 750, 'probabilityNumerator' => 1140, 'probabilityDenominator' => 10_000],
            ['positions' => 5, 'multiplierHundredths' => 1800, 'probabilityNumerator' => 480, 'probabilityDenominator' => 10_000],
        ];
        foreach ($tiers as $tier) {
            $table->tiers()->create($tier);
        }
        $table->refresh();

        $errors = app(CagedPrizeTablePublicationGate::class)->validate($table, (int) config('tax.withholding.resident_rate_basis_points'));
        // The only expected failure is the actuarial cert being a placeholder (see
        // class doc). Anything else is a genuine math error in the seeded tiers.
        $unexpected = array_values(array_filter($errors, fn (string $e) => !str_contains($e, 'actuarial')));
        if ($unexpected !== []) {
            throw new RuntimeException('Caged prize table failed the publication gate: ' . implode('; ', $unexpected));
        }

        $table->update(['status' => 'published', 'publishedAt' => now()]);
    }
}
