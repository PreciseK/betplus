<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SignupSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerNetworkCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_include_unrecorded_accounts_but_not_incomplete_or_deleted_accounts(): void
    {
        foreach (['mtn', 'mtn', 'glo', 'airtel', 't2', null] as $index => $network) {
            Player::create([
                'msisdn' => '+234803123456'.$index, 'registeredName' => 'Test Player',
                'registrationChannel' => 'web', 'mobileNetwork' => $network,
            ]);
        }
        Player::create([
            'msisdn' => '+2348031234599', 'registeredName' => 'Deleted Player',
            'registrationChannel' => 'web', 'mobileNetwork' => 'mtn', 'deletedAt' => now(),
        ]);
        SignupSession::create(['msisdn' => '+2348031234598', 'mobileNetwork' => 'mtn', 'expiresAt' => now()->addMinutes(5)]);
        $this->artisan('players:network-counts')->expectsTable(
            ['Mobile network', 'Player accounts'],
            [['MTN', 2], ['Glo', 1], ['Airtel', 1], ['T2', 1], ['Not recorded', 1], ['Total', 6]],
        )->assertSuccessful();
    }
}
