<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\CagedCrash\PlaceCrashBet;
use App\Domain\Games\CagedCrash\RoundLifecycleService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\CagedCrashGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CagedCrashEconomicsCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CagedCrashGameSeeder::class);
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        GameEconomicsConfig::create([
            'gameCode' => 'BIRDESCAPE', 'version' => 'GEC-BE-1', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => 500], // cap = 5,000,000 kobo
            'effectiveAt' => now()->subMinute(), 'publishedAt' => now(),
        ]);
    }

    private function fundedPlayer(int $fundedKobo = 10_000_000): Player
    {
        $player = Player::create([
            'msisdn' => '+2348033234567', 'registeredName' => 'Nkem Obi', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', '+2348033234567'),
        ]);
        SignupSession::create([
            'msisdn' => $player->msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        app(\App\Domain\Wallet\WalletService::class)->creditPlayBalanceFromOpay($player, $fundedKobo, 'collection', $player->id);

        return $player;
    }

    public function test_a_bet_within_the_exposure_cap_is_accepted_and_grows_exposure(): void
    {
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $player = $this->fundedPlayer();

        // Worst case for 10,000 kobo = 150,000 kobo (15.00x max multiplier), well under the 5,000,000 cap.
        app(PlaceCrashBet::class)->place($player, $round, 10_000, null, 'idem-be-accept-1');

        $this->assertSame(150_000, $round->refresh()->exposureKobo);
    }

    public function test_a_bet_over_the_exposure_cap_is_rejected(): void
    {
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $player = $this->fundedPlayer();

        // Worst case for 400,000 kobo = 6,000,000 kobo (15.00x max multiplier) > 5,000,000 cap.
        $this->expectException(TicketEligibilityException::class);
        try {
            app(PlaceCrashBet::class)->place($player, $round, 400_000, null, 'idem-be-reject-1');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('ROUND_EXPOSURE_CAP', $e->errorCode);
            throw $e;
        }
    }

    public function test_a_second_bet_that_would_push_cumulative_exposure_over_the_cap_is_rejected(): void
    {
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $player = $this->fundedPlayer();
        // First bet: worst case 3,000,000 kobo (200,000 stake @ 15.00x) — accepted, exposure now 3,000,000.
        app(PlaceCrashBet::class)->place($player, $round, 200_000, null, 'idem-be-first-1');

        // Second bet: worst case 3,000,000 kobo (200,000 stake @ 15.00x) — 3,000,000 + 3,000,000 = 6,000,000 > 5,000,000 cap.
        $this->expectException(TicketEligibilityException::class);
        app(PlaceCrashBet::class)->place($player, $round, 200_000, null, 'idem-be-second-1');
    }
}
