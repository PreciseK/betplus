<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Heritage\HeritageCatalogueService;
use App\Models\FairnessSeed;
use App\Models\HeritageCatalogueItem;
use App\Models\Player;
use App\Models\Ticket;
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class HeritageCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);
    }

    public function test_the_seeder_produces_exactly_90_items_numbered_1_to_90(): void
    {
        $this->assertSame(90, HeritageCatalogueItem::count());
        $this->assertSame(1, HeritageCatalogueItem::min('itemNumber'));
        $this->assertSame(90, HeritageCatalogueItem::max('itemNumber'));
    }

    public function test_a_seeded_placeholder_item_cannot_be_published_without_a_sign_off_reference(): void
    {
        $errors = app(HeritageCatalogueService::class)->publish(1);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('sign-off', $errors[0]);
        $this->assertNull(HeritageCatalogueItem::find(1)->publishedAt);
    }

    public function test_an_item_with_a_recorded_sign_off_reference_publishes(): void
    {
        HeritageCatalogueItem::where('itemNumber', 1)->update(['signOffRef' => 'ADVISOR-YORUBA-2026-001']);

        $errors = app(HeritageCatalogueService::class)->publish(1);

        $this->assertSame([], $errors);
        $this->assertNotNull(HeritageCatalogueItem::find(1)->publishedAt);
    }

    public function test_the_number_to_item_mapping_may_be_edited_before_any_live_ticket_exists(): void
    {
        app(HeritageCatalogueService::class)->updateItem(1, ['canonicalName' => 'A corrected placeholder name']);

        $this->assertSame('A corrected placeholder name', HeritageCatalogueItem::find(1)->canonicalName);
    }

    public function test_the_number_to_item_mapping_is_refused_once_a_live_heritage_ticket_exists(): void
    {
        $this->createLiveHeritageTicket();

        $this->expectException(RuntimeException::class);
        app(HeritageCatalogueService::class)->updateItem(1, ['canonicalName' => 'A different item entirely']);
    }

    public function test_non_identity_fields_may_still_be_edited_after_a_live_ticket_exists(): void
    {
        $this->createLiveHeritageTicket();

        // Fixing a typo in the cultural description isn't "renumbering" — REQ-HG-061
        // protects the number<->item mapping, not every column on the row.
        app(HeritageCatalogueService::class)->updateItem(1, ['culturalDescription' => 'A corrected description.']);

        $this->assertSame('A corrected description.', HeritageCatalogueItem::find(1)->culturalDescription);
    }

    private function createLiveHeritageTicket(): void
    {
        $player = Player::create([
            'msisdn' => '+2348055' . random_int(100000, 999999),
            'registeredName' => 'Catalogue Test Player', 'registrationChannel' => 'web', 'kycTier' => 1,
        ]);
        Ticket::create([
            'reference' => (string) Str::ulid(),
            'playerId' => $player->id,
            'gameCode' => 'HERITAGE',
            'idempotencyKey' => (string) Str::ulid(),
            'stateCode' => 'LAG',
            'attributionConfidence' => 0.99,
            'jurisdictionRulesetVersion' => '2026.1',
            'stakeKobo' => 100_000,
            'predictionJson' => [0, 1, 2, 3, 4],
            'positions' => 5,
            'prizeTableVersion' => 'HG-NG-2026.1',
            'rngSeedRef' => FairnessSeed::create([
                'seedHex' => bin2hex(random_bytes(32)), 'algorithm' => 'test', 'hash' => bin2hex(random_bytes(32)), 'issuedAt' => now(),
            ])->id,
            'rngAlgorithm' => 'test',
            'engineVersion' => 'heritage-1.0.0',
            'status' => 'SETTLED',
        ]);
    }
}
