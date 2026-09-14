<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Games\PrizeTable\PrizeTablePublicationGate;
use App\Models\ExclusionRegistry;
use App\Models\GameRegistry;
use App\Models\PrizeTable;
use App\Models\StateLicence;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Story 3.4/3.5 — the minimum configuration BlackRed needs to be playable in dev and
 * staging: a game registry entry, one state's exclusion-registry gate satisfied (Lagos,
 * matching the frontend's own mock stateName default), and a published prize table
 * whose tiers are close to the frontend's certified-looking numbers (1.70x..26.00x).
 * Three frontend files still show the pre-recalibration 1.85x/3.60x for tiers 1-2 as
 * of 2026-09-14 — apps/web/src/mocks/operator-game-registry.ts,
 * apps/web/src/components/operations/GameRegistryConsole/GameRegistryConsole.tsx, and
 * apps/web/src/components/games/BlackRedPlayModal/BlackRedPlayModal.test.tsx — a
 * frontend follow-up is needed to match.
 * Real publication (maker-checker, actuarial cert) is Epic 6; this seeder writes the
 * same shape the gate would accept.
 */
class BlackRedGameSeeder extends Seeder
{
    public function run(): void
    {
        GameRegistry::updateOrCreate(
            ['gameCode' => 'BLACKRED'],
            [
                'engineVersion' => 'blackred-1.0.0',
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

        // Story 6.7 — AttributionService also requires a current stateLicence row
        // (REQ-QA-017: play stops at expiry, checked live). Placeholder licence
        // details, not a real filed licence number.
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
            ['gameCode' => 'BLACKRED', 'stateCode' => null, 'version' => 'BR-NG-2026.1'],
            [
                'status' => 'draft',
                'effectiveAt' => now()->subDay(),
                'actuarialCertRef' => 'PENDING-ACTUARIAL-CERT — placeholder, not a real certification',
            ],
        );

        $table->tiers()->delete();
        // Tiers 1-2 recalibrated 2026-09-14 (185->170, 360->340) to clear the tightened
        // 8800bp RTP ceiling (RtpCeiling::BASIS_POINTS) — the original 92.50%/90.00% RTP
        // values (still referenced in apps/web/src/mocks/blackred.ts, out of scope for
        // this backend change) exceeded it and made this seeder itself throw. This is an
        // interim placeholder pending real Finance/actuarial redesign of the "good"
        // preset, not a finished design (see docs/game-engine-economics.md).
        $tiers = [
            ['positions' => 1, 'multiplierHundredths' => 170, 'probabilityNumerator' => 1, 'probabilityDenominator' => 2],
            ['positions' => 2, 'multiplierHundredths' => 340, 'probabilityNumerator' => 1, 'probabilityDenominator' => 4],
            ['positions' => 3, 'multiplierHundredths' => 700, 'probabilityNumerator' => 1, 'probabilityDenominator' => 8],
            ['positions' => 4, 'multiplierHundredths' => 1350, 'probabilityNumerator' => 1, 'probabilityDenominator' => 16],
            ['positions' => 5, 'multiplierHundredths' => 2600, 'probabilityNumerator' => 1, 'probabilityDenominator' => 32],
        ];
        foreach ($tiers as $tier) {
            $table->tiers()->create($tier);
        }
        $table->refresh();

        $errors = app(PrizeTablePublicationGate::class)->validate($table, (int) config('tax.withholding.resident_rate_basis_points'));
        // The only expected failure is the actuarial cert being a placeholder — a real
        // one doesn't exist yet (see class doc comment). Anything else is a genuine
        // math error in the seeded tiers and should fail the seed run.
        $unexpected = array_values(array_filter($errors, fn (string $e) => !str_contains($e, 'actuarial')));
        if ($unexpected !== []) {
            throw new RuntimeException('BlackRed prize table failed the publication gate: ' . implode('; ', $unexpected));
        }

        $table->update(['status' => 'published', 'publishedAt' => now()]);
    }
}
