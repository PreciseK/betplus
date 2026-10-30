<?php

declare(strict_types=1);

namespace App\Domain\Games\Doors;

use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
use App\Domain\Games\Economics\GameDailyLedgerService;
use App\Domain\Games\Economics\GameEconomicsModelGate;
use App\Domain\Jurisdiction\AttributionService;
use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\Tax\TaxEngine;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\WalletService;
use App\Jobs\DispatchPrizePayoutJob;
use App\Models\DoorsRound;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\GameRegistry;
use App\Models\Payout;
use App\Models\Player;
use App\Models\PlayerWallet;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DoorsService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly SeedIssuer $seeds,
        private readonly DoorsEngine $engine,
        private readonly EconomicsConfigResolver $configs,
        private readonly EconomicsModelStrategyFactory $strategies,
        private readonly GameEconomicsModelGate $gate,
        private readonly AttributionService $attribution,
        private readonly ProtectionService $protection,
        private readonly RegistryCheckService $registry,
        private readonly LimitsService $limits,
        private readonly TaxEngine $tax,
        private readonly GameDailyLedgerService $daily,
    ) {}

    public function configuration(): GameEconomicsConfig
    {
        $config = $this->configs->resolveFor('DOORS');
        if (!$config || $this->gate->validate($config) !== []) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Doors configuration is awaiting approval.');
        }
        return $config;
    }

    public function start(Player $player, int $stakeKobo, int $seconds, string $key): DoorsRound
    {
        $scopedKey = hash('sha256', $player->id . ':DOORS:' . $key);
        // Potentially remote eligibility checks happen before acquiring database locks.
        // A committed retry can still be resumed when new play is paused.
        $prior = Ticket::where('idempotencyKey', $scopedKey)->first();
        if ($prior) {
            $round = DoorsRound::where('ticketId', $prior->id)->firstOrFail();
            if ($prior->stakeKobo !== $stakeKobo || $round->configJson['duration_seconds'] !== $seconds) {
                throw new TicketEligibilityException('IDEMPOTENCY_MISMATCH', 'This request key was used for a different round.');
            }
            return $round;
        }
        $initialGame = GameRegistry::where('gameCode', 'DOORS')->first();
        if (!config('doors.enabled') || !$initialGame || $initialGame->status !== 'ACTIVE') {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Doors is not accepting new rounds.');
        }
        $this->protection->assertPlayAndDepositAllowed($player);
        $this->registry->assertClear($player);
        $attribution = $this->attribution->attribute($player, $initialGame);
        // Whole-table sums run before the lock; only open Doors exposure needs serializing.
        $headroomKobo = $this->fundingHeadroom($this->configuration()->paramsJson);
        return DB::transaction(function () use ($player, $stakeKobo, $seconds, $scopedKey, $attribution, $headroomKobo): DoorsRound {
            // Serializes Doors acceptance, exposure reservation and release across workers.
            $game = GameRegistry::where('gameCode', 'DOORS')->lockForUpdate()->first();
            $player = Player::whereKey($player->id)->lockForUpdate()->firstOrFail();
            $existing = Ticket::where('idempotencyKey', $scopedKey)->first();
            if ($existing) {
                $round = DoorsRound::where('ticketId', $existing->id)->firstOrFail();
                if ($existing->stakeKobo !== $stakeKobo || $round->configJson['duration_seconds'] !== $seconds) {
                    throw new TicketEligibilityException('IDEMPOTENCY_MISMATCH', 'This request key was used for a different round.');
                }
                return $round;
            }
            if (!config('doors.enabled') || !$game || $game->status !== 'ACTIVE' || !in_array('web', $game->enabledChannels, true)) {
                throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Doors is not accepting new rounds.');
            }
            $config = $this->configuration();
            $params = $config->paramsJson;
            $rules = $params['doors'];
            if (!in_array($seconds, $rules['durations_seconds'], true)) {
                throw new TicketEligibilityException('INVALID_DURATION', 'Doors rounds last ' . implode(' or ', $rules['durations_seconds']) . ' seconds.');
            }
            if ($stakeKobo < $game->minStakeKobo || $stakeKobo > $game->maxStakeKobo) {
                $range = '₦' . number_format($game->minStakeKobo / 100) . ' and ₦' . number_format($game->maxStakeKobo / 100);
                throw new TicketEligibilityException('INVALID_STAKE', "Enter a stake between {$range}.");
            }
            if ($player->kycTier < 1) { throw new TicketEligibilityException('KYC_REQUIRED', 'Verify your identity before playing.'); }
            $this->protection->assertPlayAndDepositAllowed($player);
            if (!in_array($attribution['stateCode'], $game->enabledStates, true)) {
                throw new TicketEligibilityException('STATE_NOT_LICENSED', 'Doors is not enabled for your state.');
            }
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);
            $active = DoorsRound::where('playerId', $player->id)->where('status', 'IN_PLAY')->lockForUpdate()->first();
            if ($active) {
                if ($active->expiresAt->isFuture()) { throw new TicketEligibilityException('ROUND_IN_PROGRESS', 'Resume your current Doors round first.'); }
                $this->settle($active, null);
            }
            $this->strategies->forTicketGame($config)->assertAcceptable('DOORS', $stakeKobo, EconomicsContext::forTicket());
            $maxMultiplier = max(array_column($rules['treasures'], 'multiplier'));
            if ($stakeKobo > intdiv(PHP_INT_MAX, $maxMultiplier)) { throw new TicketEligibilityException('INVALID_STAKE', 'Stake exceeds the supported amount.'); }
            $exposureKobo = $stakeKobo * $maxMultiplier;
            if ($exposureKobo > $headroomKobo - (int) DoorsRound::where('status', 'IN_PLAY')->sum('exposureKobo')) {
                throw new TicketEligibilityException('BANKROLL_EXPOSURE_CAP', 'New rounds are paused because the payout reserve is fully allocated.');
            }
            $seed = $this->seeds->issue();
            $reference = (string) Str::ulid();
            $board = $this->engine->board($seed->seedHex, $reference, $rules);
            $ticket = Ticket::create([
                'reference' => $reference, 'playerId' => $player->id, 'gameCode' => 'DOORS', 'idempotencyKey' => $scopedKey,
                'stateCode' => $attribution['stateCode'], 'attributionConfidence' => $attribution['confidence'],
                'jurisdictionRulesetVersion' => $attribution['rulesetVersion'], 'stakeKobo' => $stakeKobo,
                'predictionJson' => [], 'positions' => $rules['max_attempts'], 'prizeTableVersion' => $config->version,
                'rngSeedRef' => $seed->id, 'rngAlgorithm' => $seed->algorithm, 'engineVersion' => DoorsEngine::VERSION, 'status' => 'IN_PLAY',
            ]);
            $this->wallet->reserveStake($player, $stakeKobo, 'ticket', $ticket->id, $ticket->stateCode);
            $taxAwards = [];
            foreach ($rules['treasures'] as $treasure) {
                $withholding = $this->tax->withhold($stakeKobo * $treasure['multiplier'], $player);
                $taxAwards[$treasure['multiplier']] = [
                    'tax_kobo' => $withholding->taxWithheldKobo, 'net_kobo' => $withholding->netCreditKobo,
                    'rate_bps' => $withholding->rateBasisPoints, 'basis_label' => $withholding->basisLabel, 'ruleset_version' => $withholding->rulesetVersion,
                ];
            }
            return DoorsRound::create([
                'ticketId' => $ticket->id, 'playerId' => $player->id, 'status' => 'IN_PLAY',
                'boardEncrypted' => $board['board'], 'openedJson' => [],
                'configJson' => ['rules' => $rules, 'duration_seconds' => $seconds,
                    'tax_awards' => $taxAwards],
                'economicsVersion' => $config->version, 'commitment' => $board['commitment'],
                'exposureKobo' => $exposureKobo, 'createdAt' => now(), 'expiresAt' => now()->addSeconds($seconds),
            ]);
        }, 3);
    }

    public function read(Player $player, string $reference, ?int $door = null): DoorsRound
    {
        return DB::transaction(function () use ($player, $reference, $door): DoorsRound {
            $ticket = Ticket::where('reference', $reference)->where('playerId', $player->id)->where('gameCode', 'DOORS')->firstOrFail();
            $round = DoorsRound::where('ticketId', $ticket->id)->lockForUpdate()->firstOrFail();
            if ($round->status !== 'IN_PLAY') { return $round; }
            // Server receipt time is authoritative. Never trust the browser countdown.
            if ($round->expiresAt->lessThanOrEqualTo(now())) { return $this->settle($round, null); }
            if ($door === null) { return $round; }
            $opened = $round->openedJson;
            if ($door < 0 || $door >= $round->configJson['rules']['board_size']) { throw new TicketEligibilityException('INVALID_DOOR', 'Choose one of the vault doors.'); }
            if (in_array($door, array_column($opened, 'index'), true)) { return $round; }
            $multiplier = $round->boardEncrypted[$door];
            $opened[] = ['index' => $door, 'multiplier' => $multiplier];
            $round->update(['openedJson' => $opened]);
            if ($multiplier > 0 || count($opened) >= $round->configJson['rules']['max_attempts']) {
                return $this->settle($round, $multiplier > 0 ? $multiplier : null);
            }
            return $round;
        }, 3);
    }

    public function current(Player $player): ?DoorsRound
    {
        $active = DoorsRound::where('playerId', $player->id)->where('status', 'IN_PLAY')->first();
        return $active ? $this->read($player, $active->ticket->reference) : null;
    }

    /** @param array<string, mixed> $params */
    private function fundingHeadroom(array $params): int
    {
        $snapshot = FloatSnapshot::orderByDesc('polledAt')->orderByDesc('id')->first();
        if (!$snapshot || $snapshot->polledAt->lessThan(now()->subSeconds($params['float_max_age_seconds']))) {
            throw new TicketEligibilityException('FLOAT_UNAVAILABLE', 'New rounds are paused while funding is verified.');
        }
        $playerLiabilitiesKobo = (int) PlayerWallet::sum('playBalanceKobo') + (int) PlayerWallet::sum('winningsBalanceKobo');
        $reservedFundsKobo = (int) DB::table('ledgerEntry')->join('ledgerAccount', 'ledgerEntry.accountId', '=', 'ledgerAccount.id')
            ->whereIn('ledgerAccount.type', ['RESERVE_FUND', 'WHT_PAYABLE', 'SUSPENSE', 'PRIZE_LIABILITY', 'PROMO_DRAW_POOL'])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE -CAST(amountKobo AS SIGNED) END), 0) AS balance")->value('balance');
        $recentPaymentsKobo = (int) Payout::where('providerStatus', 'SUCCESS')->where('confirmedAt', '>=', $snapshot->polledAt)->sum('amountKobo');
        $pendingWithdrawalsKobo = (int) Payout::where('kind', 'withdrawal')->whereNull('confirmedAt')->sum('amountKobo');
        return $snapshot->opayBalanceKobo - $playerLiabilitiesKobo - max(0, $reservedFundsKobo) - $recentPaymentsKobo - $pendingWithdrawalsKobo - $params['reserve_floor_kobo'];
    }

    private function settle(DoorsRound $round, ?int $multiplier): DoorsRound
    {
        if ($round->status !== 'IN_PLAY') { return $round; }
        $ticket = $round->ticket;
        $player = Player::findOrFail($round->playerId);
        $grossPrizeKobo = $ticket->stakeKobo * ($multiplier ?? 0);
        // Snapshot the tax configuration at creation; settlement never drifts mid-round.
        $taxAward = $round->configJson['tax_awards'][$multiplier ?? 0] ?? null;
        $taxWithheldKobo = (int) ($taxAward['tax_kobo'] ?? 0);
        $netCreditKobo = (int) ($taxAward['net_kobo'] ?? 0);
        $bonusStakeKobo = $this->wallet->bonusStakeFor('ticket', $ticket->id);
        $result = ['opened' => $round->openedJson, 'multiplier' => $multiplier ?? 0];
        TicketOutcome::create([
            'ticketId' => $ticket->id, 'resultJson' => $result, 'won' => $grossPrizeKobo > 0, 'grossPrizeKobo' => $grossPrizeKobo,
            'taxWithheldKobo' => $taxWithheldKobo, 'netCreditKobo' => $this->wallet->netAfterBonusReclaim($netCreditKobo, $bonusStakeKobo),
            'taxRateBasisPoints' => (int) ($taxAward['rate_bps'] ?? 0), 'taxBasisLabel' => (string) ($taxAward['basis_label'] ?? ''),
            'taxRulesetVersion' => (string) ($taxAward['ruleset_version'] ?? ''), 'digest' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)),
        ]);
        if ($grossPrizeKobo > 0) {
            $this->wallet->settleWin($player, $ticket->stakeKobo, $grossPrizeKobo, $taxWithheldKobo, $netCreditKobo, 'ticket', $ticket->id, $ticket->stateCode, $bonusStakeKobo);
            DispatchPrizePayoutJob::dispatch($ticket->id)->afterCommit();
        } else { $this->wallet->settleLoss($ticket->stakeKobo, 'ticket', $ticket->id, $ticket->stateCode, $bonusStakeKobo); }
        $this->daily->recordSettlement('DOORS', $ticket->stakeKobo, $grossPrizeKobo);
        $ticket->update(['status' => 'SETTLED', 'revealedAt' => now()]);
        $round->update(['status' => 'SETTLED', 'settledAt' => now(), 'exposureKobo' => 0]);
        return $round;
    }
}
