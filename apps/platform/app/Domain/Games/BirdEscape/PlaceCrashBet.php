<?php

declare(strict_types=1);

namespace App\Domain\Games\BirdEscape;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Jurisdiction\AttributionService;
use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\ResponsibleGaming\VelocityService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\WalletService;
use App\Models\CrashBet;
use App\Models\CrashRound;
use App\Models\GameRegistry;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Two-phase like CreateTicket, but thinner — the round's crash point was already
 * resolved at round start (RoundLifecycleService::startRound), so there is no engine
 * call here, only eligibility gates plus the money-moving commit.
 */
final class PlaceCrashBet
{
    private const REQUIRED_KYC_TIER = 1;
    private const MIN_AUTO_CASHOUT_HUNDREDTHS = 200; // must be at least 2.00x

    public function __construct(
        private readonly AttributionService $attribution,
        private readonly WalletService $wallet,
        private readonly LimitsService $limits,
        private readonly ProtectionService $protection,
        private readonly RegistryCheckService $registry,
        private readonly VelocityService $velocity,
        private readonly AnalyticsEventRecorder $analytics,
    ) {
    }

    public function place(Player $player, CrashRound $round, int $stakeKobo, ?int $autoCashoutMultiplierHundredths, string $idempotencyKey): CrashBet
    {
        $existing = CrashBet::where('idempotencyKey', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        // ── ELIGIBILITY — no database locks held ──
        $game = GameRegistry::where('gameCode', $round->gameCode)->first();
        if ($game === null || $game->status !== 'ACTIVE') {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Caged is not currently available.');
        }
        if ($round->status !== 'BETTING') {
            throw new TicketEligibilityException('ROUND_NOT_ACCEPTING_BETS', 'This round is no longer accepting bets.');
        }

        $this->assertKycTier($player);
        $this->protection->assertPlayAndDepositAllowed($player);
        $this->registry->assertClear($player);

        $attribution = $this->attribution->attribute($player, $game);

        if ($stakeKobo < $game->minStakeKobo || $stakeKobo > $game->maxStakeKobo) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', "Stake must be between {$game->minStakeKobo} and {$game->maxStakeKobo} kobo.");
        }
        if ($autoCashoutMultiplierHundredths !== null && $autoCashoutMultiplierHundredths < self::MIN_AUTO_CASHOUT_HUNDREDTHS) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Auto cash-out cannot be less than 2.00x.');
        }
        $this->limits->assertStakeWithinLimits($player, $stakeKobo);

        // ── COMMITMENT — single transaction, locks held briefly ──
        $bet = DB::transaction(function () use ($player, $round, $stakeKobo, $autoCashoutMultiplierHundredths, $idempotencyKey, $attribution) {
            // REQ-TKT-012-style re-assertion inside the transaction.
            $player->refresh();
            $this->assertKycTier($player);
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);

            // The round can flip BETTING -> FLYING between the eligibility read above
            // and this commit, on a slow request racing the loop's tick — re-check.
            $freshRound = CrashRound::where('id', $round->id)->where('status', 'BETTING')->lockForUpdate()->first();
            if ($freshRound === null) {
                throw new TicketEligibilityException('ROUND_NOT_ACCEPTING_BETS', 'This round is no longer accepting bets.');
            }

            $bet = CrashBet::create([
                'roundId' => $round->id,
                'playerId' => $player->id,
                'idempotencyKey' => $idempotencyKey,
                'stateCode' => $attribution['stateCode'],
                'stakeKobo' => $stakeKobo,
                'autoCashoutMultiplierHundredths' => $autoCashoutMultiplierHundredths,
                'status' => 'PLACED',
            ]);

            $this->wallet->reserveStake($player, $stakeKobo, 'crash_bet', $bet->id, $attribution['stateCode']);

            return $bet;
        });

        // Informational only, never gates the response the player just got.
        $this->velocity->evaluateAfterTicket($player, $round->gameCode, $stakeKobo);
        $this->analytics->record('crash_bet_placed', $player, 'web', gameCode: $round->gameCode, stateCode: $attribution['stateCode'], properties: [
            'stake_kobo' => $stakeKobo,
            'round_number' => $round->roundNumber,
        ]);

        return $bet;
    }

    private function assertKycTier(Player $player): void
    {
        if ($player->kycTier < self::REQUIRED_KYC_TIER) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Account has not reached the KYC tier required to play.');
        }
    }
}
