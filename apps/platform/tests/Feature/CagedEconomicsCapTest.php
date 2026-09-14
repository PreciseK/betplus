<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ticket\CreateCagedTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\CagedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CagedEconomicsCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CagedGameSeeder::class);
        Queue::fake();
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
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

    private function publishBalancedHybrid(int $kellyFactorBasisPoints): void
    {
        GameEconomicsConfig::create([
            'gameCode' => 'CAGED', 'version' => 'GEC-CG-1', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => $kellyFactorBasisPoints],
            'effectiveAt' => now()->subMinute(), 'publishedAt' => now(),
        ]);
    }

    public function test_a_stake_over_the_kelly_cap_is_rejected(): void
    {
        // Kelly factor recalibrated 2026-09-14 (500bp→100bp) to isolate Kelly cap gate.
        // With 100bp, cap = 10,000,000 kobo * 100 / 10,000 = 1,000,000 kobo.
        // Stake 1,200,000 exceeds cap but not game maxStakeKobo (2M) or daily limit (1.5M).
        $this->publishBalancedHybrid(100);
        $player = $this->fundedPlayer();

        $this->expectException(TicketEligibilityException::class);
        try {
            app(CreateCagedTicket::class)->create($player, 2, 1_200_000, 'idem-cg-reject-1');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('KELLY_STAKE_CAP', $e->errorCode);
            throw $e;
        }
    }

    public function test_a_stake_within_the_kelly_cap_is_accepted(): void
    {
        // Kelly factor recalibrated 2026-09-14 (500bp→100bp) to isolate Kelly cap gate.
        // Stake 800,000 is under cap (1M) and under other limits.
        $this->publishBalancedHybrid(100);
        $player = $this->fundedPlayer();

        $ticket = app(CreateCagedTicket::class)->create($player, 2, 800_000, 'idem-cg-accept-1');

        $this->assertSame('SETTLED', $ticket->status);
    }
}
