<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\BackOffice\MakerChecker\MakerCheckerService;
use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\TotpService;
use App\Domain\Games\PrizeTable\PrizeTablePublicationGate;
use App\Domain\Wallet\WalletService;
use App\Models\InstitutionUser;
use App\Models\Player;
use App\Models\PrizeTable;
use App\Models\ReviewableChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class MakerCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function institutionUser(string $role = 'game_ops'): InstitutionUser
    {
        $secret = app(TotpService::class)->generateSecret();

        return InstitutionUser::create([
            'email' => strtolower($role) . random_int(1000, 9999) . '@betplus.test',
            'displayName' => 'Test Operator',
            'passwordHash' => password_hash('x', PASSWORD_BCRYPT),
            'role' => $role,
            'status' => 'active',
            'mfaSecretEncrypted' => app(MfaSecretCipher::class)->encrypt($secret),
            'mfaConfirmedAt' => now(),
        ]);
    }

    public function test_proposing_an_unregistered_change_type_is_refused(): void
    {
        $maker = $this->institutionUser();

        $this->expectException(RuntimeException::class);
        app(MakerCheckerService::class)->propose('not_a_real_type', [], null, $maker, 'A justification long enough.');
    }

    public function test_a_maker_cannot_approve_their_own_change(): void
    {
        $maker = $this->institutionUser();
        $player = Player::create(['msisdn' => '+2348011112222', 'registeredName' => 'Test', 'registrationChannel' => 'web']);
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);

        $change = app(MakerCheckerService::class)->propose(
            'manual_credit_debit',
            ['player_id' => $player->id, 'direction' => 'credit', 'balance' => 'PLAY', 'amount_kobo' => 5_000],
            null,
            $maker,
            'Compensating a support ticket.',
        );

        $this->expectException(RuntimeException::class);
        app(MakerCheckerService::class)->approve($change, $maker);
    }

    public function test_a_different_checker_can_approve_and_the_applier_actually_runs(): void
    {
        $maker = $this->institutionUser();
        $checker = $this->institutionUser('finance');
        $player = Player::create(['msisdn' => '+2348011113333', 'registeredName' => 'Test', 'registrationChannel' => 'web']);
        app(WalletService::class)->creditPlayBalanceFromOpay($player, 100_000, 'collection', 1);

        $change = app(MakerCheckerService::class)->propose(
            'manual_credit_debit',
            ['player_id' => $player->id, 'direction' => 'credit', 'balance' => 'PLAY', 'amount_kobo' => 5_000],
            null,
            $maker,
            'Compensating a support ticket.',
        );

        $applied = app(MakerCheckerService::class)->approve($change, $checker);

        $this->assertSame('APPLIED', $applied->status);
        $this->assertSame(105_000, app(WalletService::class)->walletFor($player)->playBalanceKobo);
    }

    public function test_rejecting_a_change_preserves_the_draft_and_requires_a_reason(): void
    {
        $maker = $this->institutionUser();
        $checker = $this->institutionUser('finance');
        $player = Player::create(['msisdn' => '+2348011114444', 'registeredName' => 'Test', 'registrationChannel' => 'web']);

        $change = app(MakerCheckerService::class)->propose(
            'manual_credit_debit',
            ['player_id' => $player->id, 'direction' => 'credit', 'balance' => 'PLAY', 'amount_kobo' => 5_000],
            null,
            $maker,
            'Compensating a support ticket.',
        );

        $rejected = app(MakerCheckerService::class)->reject($change, $checker, 'Insufficient evidence.');

        $this->assertSame('REJECTED', $rejected->status);
        $this->assertSame('Insufficient evidence.', $rejected->rejectionReason);
        // Draft preserved — the original payload is untouched.
        $this->assertSame($player->id, $rejected->payload['player_id']);
    }

    public function test_a_prize_table_publish_is_gated_by_the_publication_rules_at_approval_time(): void
    {
        $maker = $this->institutionUser();
        $checker = $this->institutionUser('finance');

        $table = PrizeTable::create([
            'gameCode' => 'BLACKRED', 'stateCode' => null, 'version' => 'TEST-BAD-1',
            'status' => 'draft', 'effectiveAt' => now(), 'actuarialCertRef' => 'CERT-1',
        ]);
        // A tier whose probability is NOT the fair-coin value — must fail the gate.
        $table->tiers()->create(['positions' => 1, 'multiplierHundredths' => 185, 'probabilityNumerator' => 1, 'probabilityDenominator' => 4]);

        $change = app(MakerCheckerService::class)->propose('prize_table_publish', ['prize_table_id' => $table->id], null, $maker, 'Publishing a new table.');

        $this->expectException(RuntimeException::class);
        app(MakerCheckerService::class)->approve($change, $checker);
    }

    public function test_a_valid_prize_table_publish_actually_publishes(): void
    {
        $maker = $this->institutionUser();
        $checker = $this->institutionUser('finance');

        $table = PrizeTable::create([
            'gameCode' => 'BLACKRED', 'stateCode' => null, 'version' => 'TEST-GOOD-1',
            'status' => 'draft', 'effectiveAt' => now(), 'actuarialCertRef' => 'CERT-2',
        ]);
        $table->tiers()->create(['positions' => 1, 'multiplierHundredths' => 170, 'probabilityNumerator' => 1, 'probabilityDenominator' => 2]);

        $change = app(MakerCheckerService::class)->propose('prize_table_publish', ['prize_table_id' => $table->id], null, $maker, 'Publishing a new table.');
        app(MakerCheckerService::class)->approve($change, $checker);

        $this->assertSame('published', $table->refresh()->status);
    }
}
