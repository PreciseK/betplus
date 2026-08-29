<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Games\PrizeTable\HeritagePrizeTablePublicationGate;
use App\Models\ExclusionRegistry;
use App\Models\GameRegistry;
use App\Models\HeritageCatalogueItem;
use App\Models\PrizeTable;
use App\Models\StateLicence;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Epic 7 — the minimum configuration Heritage needs to be playable in dev and
 * staging, mirroring BlackRedGameSeeder's shape exactly (same Lagos exclusion
 * registry / licence, same "write the shape the gate would accept" approach).
 *
 * The 90 catalogue items seeded here are NOT real content. REQ-HG-064 requires a
 * named cultural advisor's sign-off before any item may publish, and no such review
 * has happened — inventing 90 culturally-labelled items and pretending they're
 * vetted would risk the exact harm that requirement exists to prevent (PRD §9.7's
 * point about sacred regalia). Every item here is a numbered placeholder with no
 * signOffRef, generic naming, and 'Unreviewed' tradition of origin — genuinely
 * unpublishable through HeritageCatalogueService::publish() until real cultural
 * research and advisor sign-off exists.
 */
class HeritageGameSeeder extends Seeder
{
    public function run(): void
    {
        GameRegistry::updateOrCreate(
            ['gameCode' => 'HERITAGE'],
            [
                'engineVersion' => 'heritage-1.0.0',
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

        $this->seedPrizeTable();
        $this->seedCatalogue();
    }

    private function seedPrizeTable(): void
    {
        $table = PrizeTable::updateOrCreate(
            ['gameCode' => 'HERITAGE', 'stateCode' => null, 'version' => 'HG-NG-2026.1'],
            [
                'status' => 'draft',
                'effectiveAt' => now()->subDay(),
                'actuarialCertRef' => 'PENDING-ACTUARIAL-CERT — placeholder, not a real certification',
            ],
        );

        $table->heritageTiers()->delete();
        // PRD §9.4's exact launch table. Modelled RTP 81.6% (5000 + 3000 + 160bp).
        $tiers = [
            ['tierName' => 'TIER_JACKPOT', 'probabilityBasisPoints' => 200, 'multiplierHundredths' => 2_500, 'outcomeType' => 'cash'],
            ['tierName' => 'TIER_HIGH', 'probabilityBasisPoints' => 600, 'multiplierHundredths' => 500, 'outcomeType' => 'cash'],
            ['tierName' => 'TIER_SECOND_CHANCE', 'probabilityBasisPoints' => 1_600, 'multiplierHundredths' => 0, 'outcomeType' => 'draw_entry'],
            ['tierName' => 'TIER_LOSS', 'probabilityBasisPoints' => 7_600, 'multiplierHundredths' => 0, 'outcomeType' => 'none'],
        ];
        foreach ($tiers as $tier) {
            $table->heritageTiers()->create($tier);
        }
        $table->refresh();

        $errors = app(HeritagePrizeTablePublicationGate::class)->validate($table, (int) config('tax.withholding.resident_rate_basis_points'));
        $unexpected = array_values(array_filter($errors, fn (string $e) => !str_contains($e, 'actuarial')));
        if ($unexpected !== []) {
            throw new RuntimeException('Heritage prize table failed the publication gate: ' . implode('; ', $unexpected));
        }

        $table->update(['status' => 'published', 'publishedAt' => now()]);
    }

    private function seedCatalogue(): void
    {
        $slots = ['head', 'neck', 'torso', 'waist', 'wrist', 'hand', 'feet'];

        for ($number = 1; $number <= 90; $number++) {
            HeritageCatalogueItem::updateOrCreate(
                ['itemNumber' => $number],
                [
                    'canonicalName' => "Regalia Item #{$number} (placeholder — not real content)",
                    'localName' => null,
                    'traditionOfOrigin' => 'Unreviewed',
                    'leaderApplicability' => 'both',
                    'bodySlot' => $slots[$number % count($slots)],
                    'layerPriority' => $number % 10,
                    'culturalDescription' => null,
                    'signOffRef' => null, // REQ-HG-064 — deliberately absent; see class doc
                    'depictionConstraint' => null,
                    'assetRef' => null, // artwork deferred, PRD §9.4 scope note
                    'publishedAt' => null,
                ],
            );
        }
    }
}
