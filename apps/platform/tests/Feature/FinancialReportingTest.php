<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\Reporting\FinancialReportService;
use App\Domain\BackOffice\TotpService;
use App\Domain\Fairness\SeedIssuer;
use App\Models\InstitutionUser;
use App\Models\Player;
use App\Models\ReportExport;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use Carbon\CarbonImmutable;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinancialReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
    }

    private function player(): Player
    {
        return Player::create([
            'msisdn' => '+2348033' . random_int(100000, 999999),
            'registeredName' => 'Report Test Player', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', (string) random_int(1, 999999999)),
        ]);
    }

    /** One-position BLACKRED/LAG ticket at BR-NG-2026.1: stake 100_000 kobo, 1.70x
     *  (recalibrated 2026-09-14 for the 8800bp ceiling — was 1.85x). */
    private function ticket(Player $player, bool $won, int $stakeKobo = 100_000): Ticket
    {
        $seed = app(SeedIssuer::class)->issue();
        $grossPrizeKobo = $won ? (int) round($stakeKobo * 1.70) : 0;
        $taxWithheldKobo = $won ? (int) round($grossPrizeKobo * 0.05) : 0;

        $ticket = Ticket::create([
            'reference' => (string) Str::ulid(),
            'playerId' => $player->id,
            'gameCode' => 'BLACKRED',
            'idempotencyKey' => (string) Str::ulid(),
            'stateCode' => 'LAG',
            'attributionConfidence' => 0.99,
            'jurisdictionRulesetVersion' => '2026.1',
            'stakeKobo' => $stakeKobo,
            'predictionJson' => ['B'],
            'positions' => 1,
            'prizeTableVersion' => 'BR-NG-2026.1',
            'rngSeedRef' => $seed->id,
            'rngAlgorithm' => $seed->algorithm,
            'engineVersion' => 'blackred-1.0.0',
            'status' => 'SETTLED',
        ]);

        TicketOutcome::create([
            'ticketId' => $ticket->id,
            'resultJson' => ['B'],
            'won' => $won,
            'grossPrizeKobo' => $grossPrizeKobo,
            'taxWithheldKobo' => $taxWithheldKobo,
            'netCreditKobo' => $grossPrizeKobo - $taxWithheldKobo,
            'taxRateBasisPoints' => $won ? 500 : 0,
            'taxBasisLabel' => $won ? 'gross' : '',
            'taxRulesetVersion' => $won ? '2026.1' : '',
            'digest' => hash('sha256', (string) $ticket->id),
        ]);

        return $ticket;
    }

    public function test_it_slices_stakes_prizes_ggr_and_rtp_by_game_and_state(): void
    {
        $player = $this->player();
        $this->ticket($player, won: true, stakeKobo: 100_000);
        $this->ticket($player, won: false, stakeKobo: 100_000);
        $this->ticket($player, won: false, stakeKobo: 100_000);

        $report = app(FinancialReportService::class)->generate(
            CarbonImmutable::yesterday(),
            CarbonImmutable::tomorrow(),
        );

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];

        $this->assertSame('BLACKRED', $row['gameCode']);
        $this->assertSame('LAG', $row['stateCode']);
        $this->assertSame(3, $row['ticketCount']);
        $this->assertSame(300_000, $row['stakesKobo']);
        $this->assertSame(1, $row['winningTicketCount']);
        $this->assertSame(170_000, $row['grossPrizesKobo']);
        // GGR = stakes - gross prizes paid out.
        $this->assertSame(300_000 - 170_000, $row['ggrKobo']);
        // RTP actual = grossPrizesKobo / stakesKobo, in basis points.
        $this->assertSame((int) round(170_000 / 300_000 * 10_000), $row['rtpActualBasisPoints']);
        // RTP modelled uses the tier's real fair-coin probability (1/2) and multiplier
        // (1.70x, recalibrated 2026-09-14 for the 8800bp ceiling) against the actual
        // stake mix played — not the actual outcome.
        $this->assertSame(8_500, $row['rtpModelledBasisPoints']);
        // Honestly untracked — no MDR/levy accrual exists anywhere in this codebase.
        $this->assertSame(0, $row['providerFeesKobo']);
        $this->assertSame(0, $row['ggrLevyKobo']);
    }

    public function test_it_excludes_tickets_outside_the_requested_window(): void
    {
        $player = $this->player();
        $this->ticket($player, won: false);

        $report = app(FinancialReportService::class)->generate(
            CarbonImmutable::now()->subDays(10),
            CarbonImmutable::now()->subDays(5),
        );

        $this->assertSame([], $report['rows']);
    }

    // ── Story 6.9: async export + signed download ──────────────────────

    private function institutionToken(string $role): string
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

    public function test_requesting_and_downloading_a_financial_report_export(): void
    {
        Storage::fake('local');
        $player = $this->player();
        $this->ticket($player, won: true);
        $token = $this->institutionToken('finance');

        $requested = $this->withToken($token)->postJson('/backoffice/v1/reports/financial', [
            'from' => CarbonImmutable::yesterday()->toDateString(),
            'to' => CarbonImmutable::tomorrow()->toDateString(),
        ]);
        $requested->assertStatus(202);
        $exportId = $requested->json('id');

        // QUEUE_CONNECTION=sync (phpunit.xml) — the job already ran synchronously.
        $export = ReportExport::findOrFail($exportId);
        $this->assertSame('completed', $export->status);
        $this->assertSame(1, $export->rowCount);
        $this->assertNotNull($export->filePath);
        Storage::disk('local')->assertExists($export->filePath);

        $status = $this->withToken($token)->getJson("/backoffice/v1/reports/exports/{$exportId}");
        $status->assertOk();
        $downloadUrl = $status->json('download_url');
        $this->assertNotNull($downloadUrl);

        $download = $this->get($downloadUrl);
        $download->assertOk();
    }

    public function test_a_tampered_download_link_is_refused(): void
    {
        Storage::fake('local');
        $player = $this->player();
        $this->ticket($player, won: true);
        $token = $this->institutionToken('finance');

        $requested = $this->withToken($token)->postJson('/backoffice/v1/reports/financial', [
            'from' => CarbonImmutable::yesterday()->toDateString(),
            'to' => CarbonImmutable::tomorrow()->toDateString(),
        ]);
        $exportId = $requested->json('id');

        $response = $this->get("/backoffice/v1/reports/exports/{$exportId}/download?signature=not-a-real-signature");
        $response->assertStatus(403);
    }

    public function test_only_finance_compliance_or_admin_may_request_a_financial_report(): void
    {
        $token = $this->institutionToken('support_agent');

        $response = $this->withToken($token)->postJson('/backoffice/v1/reports/financial', [
            'from' => CarbonImmutable::yesterday()->toDateString(),
            'to' => CarbonImmutable::tomorrow()->toDateString(),
        ]);

        $response->assertStatus(403);
    }
}
