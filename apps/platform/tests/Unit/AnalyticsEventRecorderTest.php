<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AnalyticsEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function player(): Player
    {
        return Player::create([
            'msisdn' => '+2348044' . random_int(100000, 999999),
            'registeredName' => 'Analytics Test Player', 'registrationChannel' => 'web', 'kycTier' => 1,
        ]);
    }

    public function test_it_refuses_a_property_key_that_looks_like_raw_pii(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AnalyticsEventRecorder::class)->record('ticket_purchased', $this->player(), 'web', properties: [
            'msisdn' => '+2348012345678',
        ]);
    }

    /** @return list<string> */
    public static function deniedKeys(): array
    {
        return [['nin'], ['bvn'], ['phone'], ['date_of_birth'], ['dob'], ['latitude'], ['longitude'], ['address']];
    }

    #[DataProvider('deniedKeys')]
    public function test_it_refuses_every_denylisted_key(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AnalyticsEventRecorder::class)->record('ticket_purchased', $this->player(), 'web', properties: [
            $key => 'anything',
        ]);
    }

    public function test_it_records_a_legitimate_non_pii_property(): void
    {
        $event = app(AnalyticsEventRecorder::class)->record('ticket_purchased', $this->player(), 'web', properties: [
            'stake_kobo' => 100_000,
            'won' => true,
        ]);

        $this->assertSame(['stake_kobo' => 100_000, 'won' => true], $event->properties);
    }

    public function test_the_same_player_always_pseudonymises_to_the_same_hash(): void
    {
        $player = $this->player();

        $first = app(AnalyticsEventRecorder::class)->record('sign_in_completed', $player, 'web');
        $second = app(AnalyticsEventRecorder::class)->record('sign_in_completed', $player, 'web');

        $this->assertNotNull($first->playerIdHash);
        $this->assertSame($first->playerIdHash, $second->playerIdHash);
        // Never the raw player id, and never reversible without config('app.key').
        $this->assertNotSame((string) $player->id, $first->playerIdHash);
    }

    public function test_two_different_players_pseudonymise_to_different_hashes(): void
    {
        $a = app(AnalyticsEventRecorder::class)->record('sign_in_completed', $this->player(), 'web');
        $b = app(AnalyticsEventRecorder::class)->record('sign_in_completed', $this->player(), 'web');

        $this->assertNotSame($a->playerIdHash, $b->playerIdHash);
    }

    public function test_an_event_with_no_player_records_a_null_pseudonym(): void
    {
        $event = app(AnalyticsEventRecorder::class)->record('some_anonymous_event', null, 'web');

        $this->assertNull($event->playerIdHash);
    }
}
