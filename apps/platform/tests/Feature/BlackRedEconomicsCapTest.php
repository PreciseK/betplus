<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ticket\CreateTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BlackRedEconomicsCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
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
            'gameCode' => 'BLACKRED', 'version' => 'GEC-BR-1', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => $kellyFactorBasisPoints],
            'effectiveAt' => now()->subMinute(), 'publishedAt' => now(),
        ]);
    }

    public function test_a_stake_within_the_kelly_cap_is_accepted(): void
    {
        // cap = 100,000,000 * 100 / 10,000 = 1,000,000 kobo. Kept below BlackRed's own
        // maxStakeKobo (2,000,000) and the player's default stake-daily limit
        // (1,500,000, see LimitsService::DEFAULT_KOBO) so this exercises the Kelly cap
        // itself rather than tripping an unrelated pre-existing ceiling.
        $this->publishBalancedHybrid(100);
        $player = $this->fundedPlayer();

        $ticket = app(CreateTicket::class)->create($player, ['B'], 800_000, 'idem-accept-1');

        $this->assertSame('SETTLED', $ticket->status);
    }

    public function test_a_stake_over_the_kelly_cap_is_rejected(): void
    {
        $this->publishBalancedHybrid(100); // cap = 1,000,000 kobo
        $player = $this->fundedPlayer();

        $this->expectException(TicketEligibilityException::class);
        try {
            // 1,200,000 is over the Kelly cap but still within BlackRed's maxStakeKobo
            // (2,000,000) and the default stake-daily limit (1,500,000), so it reaches
            // the economics check rather than being rejected by an earlier gate.
            app(CreateTicket::class)->create($player, ['B'], 1_200_000, 'idem-reject-1');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('KELLY_STAKE_CAP', $e->errorCode);
            throw $e;
        }
    }

    public function test_with_no_published_config_the_kelly_cap_does_not_apply(): void
    {
        $player = $this->fundedPlayer();

        // Same 1,200,000 stake that the Kelly cap above would have rejected — with no
        // published config the strategy resolves to FixedRtpStrategy, which is a no-op.
        $ticket = app(CreateTicket::class)->create($player, ['B'], 1_200_000, 'idem-no-config-1');

        $this->assertSame('SETTLED', $ticket->status);
    }
}
