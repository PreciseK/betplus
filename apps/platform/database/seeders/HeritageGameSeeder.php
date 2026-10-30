<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Games\PrizeTable\HeritagePrizeTablePublicationGate;
use App\Models\ExclusionRegistry;
use App\Models\GameRegistry;
use App\Models\HeritageCatalogueItem;
use App\Models\PrizeTable;
use App\Models\StateLicence;
use Database\Seeders\Data\HeritageRegaliaCatalogueData;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Epic 7 — the minimum configuration Heritage needs to be playable in dev and
 * staging, mirroring BlackRedGameSeeder's shape exactly (same Lagos exclusion
 * registry / licence, same "write the shape the gate would accept" approach).
 *
 * Catalogue items 1-20 remain numbered, unreviewed placeholders exactly as
 * before — generic naming, no signOffRef, 'Unreviewed' tradition of origin,
 * genuinely unpublishable through HeritageCatalogueService::publish().
 *
 * Items 21-90 are the 70-piece Heritage regalia set (7 tribes x king/queen x
 * 5 slots, see HeritageRegaliaCatalogueData) confirmed reviewed by the Betplus
 * product team (2026-10-20): real names, real artwork (assetRef), and a
 * recorded signOffRef so they publish through the REQ-HG-064 gate honestly —
 * as a team review, not a fabricated named cultural-advisor citation.
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
                'minStakeKobo' => 20_000,
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
            ['tierName' => 'TIER_HIGH', 'probabilityBasisPoints' => 600, 'multiplierHundredths' => 50, 'outcomeType' => 'cash'],
            ['tierName' => 'TIER_LOSS', 'probabilityBasisPoints' => 9_200, 'multiplierHundredths' => 0, 'outcomeType' => 'none'],
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

    private const REAL_REGALIA_START_NUMBER = 21;
    private const REAL_REGALIA_SIGN_OFF_REF = 'BETPLUS-PRODUCT-REVIEW-2026-10-20';
    private const SLOT_PRIORITY = ['head' => 1, 'neck' => 2, 'torso' => 3, 'hand' => 4, 'feet' => 5];

    private function seedCatalogue(): void
    {
        $slots = ['head', 'neck', 'torso', 'waist', 'wrist', 'hand', 'feet'];

        for ($number = 1; $number < self::REAL_REGALIA_START_NUMBER; $number++) {
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

        $number = self::REAL_REGALIA_START_NUMBER;
        foreach (HeritageRegaliaCatalogueData::items() as $piece) {
            $leaderLabel = $piece['leader'] === 'king' ? 'King' : 'Queen';
            HeritageCatalogueItem::updateOrCreate(
                ['itemNumber' => $number],
                [
                    'canonicalName' => "{$piece['label']} {$leaderLabel} — {$piece['english']}",
                    'localName' => $piece['localName'],
                    'traditionOfOrigin' => $piece['label'],
                    'leaderApplicability' => $piece['leader'],
                    'bodySlot' => $piece['slot'],
                    'layerPriority' => self::SLOT_PRIORITY[$piece['slot']],
                    'culturalDescription' => $piece['description'],
                    'signOffRef' => self::REAL_REGALIA_SIGN_OFF_REF,
                    'depictionConstraint' => 'none',
                    'assetRef' => $piece['src'],
                    'publishedAt' => now(),
                ],
            );
            $number++;
        }
    }
}
