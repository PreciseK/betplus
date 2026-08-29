<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HeritageCatalogueEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);
    }

    private function signedInToken(string $msisdn = '+2348032234567'): string
    {
        Player::create(['msisdn' => $msisdn, 'registeredName' => 'Adaeze Nwosu', 'registrationChannel' => 'web', 'kycTier' => 1]);
        SignupSession::create([
            'msisdn' => $msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);

        return $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json('access_token');
    }

    public function test_catalogue_returns_all_90_items_honestly_marked_preview_only(): void
    {
        $token = $this->signedInToken();

        $response = $this->withToken($token)->getJson('/v1/heritage/catalogue');

        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(90, $items);

        $first = $items[0];
        $this->assertSame(1, $first['number']);
        $this->assertArrayHasKey('canonical_name', $first);
        $this->assertArrayHasKey('origin', $first);
        $this->assertArrayHasKey('slot', $first);
        // Story 7.4/7.5 (REQ-HG-064) — the seeder deliberately leaves every item
        // unsigned-off; this endpoint must never claim approval it doesn't have.
        $this->assertSame('preview-only', $first['publication_status']);
        $this->assertNull($first['advisor_sign_off_reference']);
    }

    public function test_catalogue_requires_authentication(): void
    {
        $this->getJson('/v1/heritage/catalogue')->assertStatus(401);
    }
}
