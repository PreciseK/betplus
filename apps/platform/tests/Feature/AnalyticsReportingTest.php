<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\DailyRollupService;
use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\TotpService;
use App\Models\AnalyticsDailyRollup;
use App\Models\AnalyticsEvent;
use App\Models\InstitutionUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AnalyticsReportingTest extends TestCase
{
    use RefreshDatabase;

    private function institutionToken(string $role = 'compliance'): string
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = InstitutionUser::create([
            'email' => strtolower($role) . random_int(1000, 9999) . '@betplus.test',
            'displayName' => 'Operator', 'passwordHash' => password_hash('x', PASSWORD_BCRYPT),
            'role' => $role, 'status' => 'active',
            'mfaSecretEncrypted' => app(MfaSecretCipher::class)->encrypt($secret),
        ]);
        $signIn = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'x'])->json();
        $mfa = $this->postJson('/backoffice/v1/auth/mfa', ['challenge_id' => $signIn['challenge_id'], 'code' => app(TotpService::class)->currentCode($secret)])->json();

        return $mfa['access_token'];
    }

    private function event(string $eventName, string $day, ?string $playerIdHash = null): void
    {
        AnalyticsEvent::create([
            'eventName' => $eventName,
            'occurredAt' => CarbonImmutable::parse($day)->addHours(10),
            'playerIdHash' => $playerIdHash ?? hash('sha256', uniqid('', true)),
            'channel' => 'web',
            'gameCode' => in_array($eventName, ['ticket_purchased', 'state_attributed'], true) ? 'BLACKRED' : null,
            'stateCode' => in_array($eventName, ['ticket_purchased', 'state_attributed'], true) ? 'LAG' : null,
        ]);
    }

    public function test_the_rollup_command_pre_aggregates_a_day_of_events(): void
    {
        $this->event('player_registered', '2026-08-10');
        $this->event('player_registered', '2026-08-10');
        $this->event('nin_verified', '2026-08-10');

        $rolled = app(DailyRollupService::class)->rollupDay(CarbonImmutable::parse('2026-08-10'));

        $this->assertSame(2, $rolled); // two distinct (eventName, channel, game, state) groups
        // Not assertDatabaseHas on 'day' directly — sqlite stores a date column as a
        // full "Y-m-d 00:00:00" datetime string, so a raw "2026-08-10" match is
        // driver-specific; comparing through the model's date cast isn't.
        $this->assertSame(2, AnalyticsDailyRollup::where('eventName', 'player_registered')->firstOrFail()->eventCount);
        $this->assertSame(1, AnalyticsDailyRollup::where('eventName', 'nin_verified')->firstOrFail()->eventCount);
    }

    public function test_re_running_the_rollup_for_the_same_day_does_not_duplicate_rows(): void
    {
        $this->event('player_registered', '2026-08-10');
        $service = app(DailyRollupService::class);
        $service->rollupDay(CarbonImmutable::parse('2026-08-10'));
        $service->rollupDay(CarbonImmutable::parse('2026-08-10'));

        $this->assertDatabaseCount('analyticsDailyRollup', 1);
    }

    public function test_the_rollup_command_runs_via_artisan(): void
    {
        $this->event('sign_in_completed', CarbonImmutable::yesterday()->toDateString());

        Artisan::call('analytics:rollup');

        $this->assertDatabaseHas('analyticsDailyRollup', ['eventName' => 'sign_in_completed', 'eventCount' => 1]);
    }

    public function test_the_back_office_can_read_rollups_scoped_to_a_window(): void
    {
        $this->event('player_registered', '2026-08-10');
        $this->event('player_registered', '2026-09-10'); // outside the window below
        app(DailyRollupService::class)->rollupDay(CarbonImmutable::parse('2026-08-10'));
        app(DailyRollupService::class)->rollupDay(CarbonImmutable::parse('2026-09-10'));

        $response = $this->withToken($this->institutionToken())
            ->getJson('/backoffice/v1/analytics/rollups?from=2026-08-01&to=2026-08-31');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('player_registered', $rows[0]['event_name']);
    }

    public function test_funnels_report_real_measurable_steps_and_flag_the_unmeasurable_ones(): void
    {
        $this->event('player_registered', '2026-08-10');
        $this->event('player_registered', '2026-08-10');
        $this->event('nin_verified', '2026-08-10');
        $this->event('ticket_purchased', '2026-08-10');
        $this->event('state_attributed', '2026-08-10');
        app(DailyRollupService::class)->rollupDay(CarbonImmutable::parse('2026-08-10'));

        $response = $this->withToken($this->institutionToken())
            ->getJson('/backoffice/v1/analytics/funnels?from=2026-08-01&to=2026-08-31');

        $response->assertOk();
        $funnels = $response->json('funnels');

        $this->assertTrue($funnels['acquisition_to_first_paid_play']['measurable']);
        $this->assertSame(2, $funnels['acquisition_to_first_paid_play']['steps'][0]['eventCount']);
        $this->assertSame(1, $funnels['acquisition_to_first_paid_play']['steps'][1]['eventCount']);

        $this->assertTrue($funnels['geo_attribution']['measurable']);
        $this->assertTrue($funnels['payout']['measurable']);
        $this->assertTrue($funnels['funding']['measurable']);

        // These two genuinely cannot be measured yet (no USSD channel, one game only)
        // — reported as such rather than a fabricated number.
        $this->assertFalse($funnels['ussd_play']['measurable']);
        $this->assertFalse($funnels['cross_game']['measurable']);
    }
}
