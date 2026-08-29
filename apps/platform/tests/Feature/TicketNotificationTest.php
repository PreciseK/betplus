<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SignupSession;
use App\Models\SmsLog;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
        Queue::fake();
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348044234567'): array
    {
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Ticket Notify Player', 'registrationChannel' => 'ussd',
            'kycTier' => 1, 'ninHash' => hash('sha256', $msisdn),
        ]);
        SignupSession::create([
            'msisdn' => $msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        $tokens = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json();

        return [$player, $tokens['access_token']];
    }

    public function test_notify_sms_sends_a_receipt_for_the_callers_own_ticket(): void
    {
        [$player, $token] = $this->signedInPlayer();
        app(\App\Domain\Wallet\WalletService::class)->creditPlayBalanceFromOpay($player, 1_000_000, 'collection', $player->id);
        $purchase = $this->withToken($token)->postJson('/v1/tickets', [
            'prediction' => ['B'], 'stake_kobo' => 100_000, 'idempotency_key' => 'notify-test-1',
        ])->json();

        $response = $this->withToken($token)->postJson("/v1/tickets/{$purchase['reference']}/notify-sms");

        $response->assertOk()->assertJson(['status' => 'sent']);
        $this->assertSame(1, SmsLog::where('playerId', $player->id)->count());
        $this->assertStringContainsString($purchase['reference'], SmsLog::where('playerId', $player->id)->first()->messageBody);
    }

    public function test_notify_sms_refuses_a_ticket_that_does_not_belong_to_the_caller(): void
    {
        [$owner, $ownerToken] = $this->signedInPlayer('+2348044234567');
        app(\App\Domain\Wallet\WalletService::class)->creditPlayBalanceFromOpay($owner, 1_000_000, 'collection', $owner->id);
        $purchase = $this->withToken($ownerToken)->postJson('/v1/tickets', [
            'prediction' => ['B'], 'stake_kobo' => 100_000, 'idempotency_key' => 'notify-test-2',
        ])->json();

        [, $otherToken] = $this->signedInPlayer('+2348044999999');
        $response = $this->withToken($otherToken)->postJson("/v1/tickets/{$purchase['reference']}/notify-sms");

        $response->assertStatus(404);
    }
}
