<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ticket\CreateHeritageTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class HeritageEconomicsCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);
        Queue::fake();
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        Http::fake(['*/engine/v1/resolve' => Http::response([
            'outcome_tier' => 'TIER_LOSS',
            'gross_prize_kobo' => 0,
            'engine_version' => 'HG-TEST-1',
            'digest' => 'test-digest',
            'engine_state' => [
                'board' => range(0, 8),
                'winning_positions' => [0, 1, 2, 3, 4],
                'selected_positions' => [0, 1, 2, 3, 4],
                'match_count' => 0,
                'tradition' => 'yoruba',
                'leader_type' => 'king',
                'second_chance_stake_kobo' => null,
            ],
        ], 200)]);
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
            'gameCode' => 'HERITAGE', 'version' => 'GEC-HG-1', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => $kellyFactorBasisPoints],
            'effectiveAt' => now()->subMinute(), 'publishedAt' => now(),
        ]);
    }

    public function test_a_stake_over_the_kelly_cap_is_rejected(): void
    {
        // cap = 100,000,000 * 100 / 10,000 = 1,000,000 kobo. Kept below Heritage's own
        // maxStakeKobo (2,000,000) and the player's default stake-daily limit
        // (1,500,000, see LimitsService::DEFAULT_KOBO) so this exercises the Kelly cap
        // itself rather than tripping an unrelated pre-existing ceiling.
        $this->publishBalancedHybrid(100);
        $player = $this->fundedPlayer();

        $this->expectException(TicketEligibilityException::class);
        try {
            app(CreateHeritageTicket::class)->create($player, [0, 1, 2, 3, 4], 1_200_000, 'idem-hg-reject-1', 'yoruba', 'king');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('KELLY_STAKE_CAP', $e->errorCode);
            throw $e;
        }
    }

    public function test_a_stake_within_the_kelly_cap_is_accepted(): void
    {
        $this->publishBalancedHybrid(100);
        $player = $this->fundedPlayer();

        $ticket = app(CreateHeritageTicket::class)->create($player, [0, 1, 2, 3, 4], 800_000, 'idem-hg-accept-1', 'yoruba', 'king');

        $this->assertSame('SETTLED', $ticket->status);
    }
}
