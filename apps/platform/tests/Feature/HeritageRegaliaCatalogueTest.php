<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HeritageCatalogueItem;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\Data\HeritageRegaliaCatalogueData;
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the 70-piece real regalia set seeded at catalogue numbers 21-90
 * (HeritageGameSeeder::seedCatalogue()) — items 1-20 stay the original
 * unreviewed placeholders, already covered by HeritageCatalogueTest.
 */
final class HeritageRegaliaCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);
    }

    public function test_seeds_exactly_seventy_real_regalia_items_from_number_21_to_90(): void
    {
        $this->assertCount(70, HeritageRegaliaCatalogueData::items());
        $this->assertSame(70, HeritageCatalogueItem::whereBetween('itemNumber', [21, 90])->count());
    }

    public function test_real_regalia_items_have_localName_and_a_served_image_reference(): void
    {
        $items = HeritageCatalogueItem::whereBetween('itemNumber', [21, 90])->get();

        $this->assertCount(70, $items);
        foreach ($items as $item) {
            $this->assertNotNull($item->localName, "item {$item->itemNumber} is missing a local name");
            $this->assertNotNull($item->assetRef, "item {$item->itemNumber} is missing artwork");
            $this->assertStringStartsWith('/games/heritage/regalia/', $item->assetRef);
            $this->assertContains($item->leaderApplicability, ['king', 'queen']);
            $this->assertContains($item->bodySlot, ['head', 'neck', 'torso', 'hand', 'feet']);
        }
    }

    public function test_real_regalia_items_are_signed_off_and_published(): void
    {
        $item = HeritageCatalogueItem::findOrFail(21);

        $this->assertNotNull($item->signOffRef);
        $this->assertNotNull($item->publishedAt);
    }

    public function test_placeholder_items_1_to_20_are_unaffected(): void
    {
        $this->assertSame(20, HeritageCatalogueItem::whereBetween('itemNumber', [1, 20])
            ->whereNull('signOffRef')
            ->whereNull('assetRef')
            ->count());
    }

    public function test_catalogue_endpoint_serves_real_artwork_and_approved_status_for_a_regalia_item(): void
    {
        $token = $this->signedInToken();

        $response = $this->withToken($token)->getJson('/v1/heritage/catalogue');
        $response->assertOk();

        $items = collect($response->json('items'));
        $regaliaItem = $items->firstWhere('number', 21);

        $this->assertNotNull($regaliaItem);
        $this->assertNotNull($regaliaItem['image_url']);
        $this->assertStringStartsWith('/games/heritage/regalia/', $regaliaItem['image_url']);
        $this->assertSame('approved', $regaliaItem['publication_status']);
        $this->assertNotNull($regaliaItem['advisor_sign_off_reference']);

        $placeholderItem = $items->firstWhere('number', 1);
        $this->assertNull($placeholderItem['image_url']);
        $this->assertSame('preview-only', $placeholderItem['publication_status']);
    }

    private function signedInToken(string $msisdn = '+2348032234567'): string
    {
        Player::create(['msisdn' => $msisdn, 'registeredName' => 'Regalia Catalogue Test', 'registrationChannel' => 'web', 'kycTier' => 1]);
        SignupSession::create([
            'msisdn' => $msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);

        return $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json('access_token');
    }
}
