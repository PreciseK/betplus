<?php

declare(strict_types=1);

namespace BlackRed\Wallet;

use BlackRed\Config\SystemConfigService;
use BlackRed\Database\Connection;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Logging\Logger;

/**
 * The core game engine.
 *
 * Owns the entire "Stop & Reveal" flow: validate input, debit stake, decide
 * outcome (fair random vs forced loss), pick drawn cards, credit win if any,
 * write audit row. Everything runs in a single DB transaction so partial
 * failures roll back cleanly.
 *
 * Decision rule (the business heart of the engine):
 *
 *   netRevenueToday  = lossesToday - winsToday          (may be negative)
 *   effectiveRevenue = max(netRevenueToday, dailyFloor)
 *
 *   IF (winsToday + potentialPayout) <= effectiveRevenue * thresholdPct / 100
 *       → fair_random  (the player can win or lose by chance)
 *   ELSE
 *       → forced_loss  (guaranteed loss; we pick a losing card sequence)
 *
 * Card representation:
 *   "<rank><suit>" 2-char codes. Rank = A,2-9,T,J,Q,K. Suit = H,D,C,S.
 *   Color: H,D = red. C,S = black.
 *
 * Locked deck contract:
 *   Client sends 12 cards. Engine validates: exactly 12, exactly 6 red and
 *   6 black, no duplicates, valid codes. Anything else → reject, no debit.
 */
final class GameEngineService
{
    /** Multiplier table — must match the marketing copy. */
    private const MULTIPLIERS = [1 => 2, 2 => 10, 3 => 20, 4 => 50, 5 => 100];

    /** Fixed stake floor (200 pesewas = GHS 2). */
    private const MIN_STAKE_PESEWAS = 200;

    /** All days are computed in this timezone (Ghana). */
    private const BUSINESS_TZ = 'Africa/Accra';

    public function __construct(
        private Connection          $db,
        private SystemConfigService $config,
        private Logger              $logger
    ) {}

    /**
     * The single public entry point. Returns a structured result on success,
     * throws HttpException on validation/business errors.
     */
    public function play(
        int    $playerId,
        int    $gameType,            // 1..5
        array  $colorPicks,          // ['red','black','red'] etc, length=gameType
        int    $stakePesewas,
        array  $lockedDeck,          // 12 card codes
        ?string $clientIp = null,
        ?string $userAgent = null
    ): array {
        // ---- Validate engine on/off
        if (!$this->config->getBool('engine.gameEngineEnabled', true)) {
            throw new HttpException(503, 'engine_disabled', 'The game is temporarily unavailable.');
        }

        // ---- Validate inputs (cheap, before any DB write)
        $this->validateInput($gameType, $colorPicks, $stakePesewas, $lockedDeck);

        $multiplier        = self::MULTIPLIERS[$gameType];
        $potentialPayout   = $stakePesewas * $multiplier;
        $maxStake          = $this->config->getInt('game.maxStakePesewas', 200000);
        $dailyStakeLimit   = $this->config->getInt('game.dailyStakeLimitPesewas', 2000000);

        if ($stakePesewas > $maxStake) {
            throw new HttpException(400, 
                'stake_too_high',
                'Maximum stake is GHS ' . number_format($maxStake / 100, 2) . '.'
            );
        }

        // ---- Atomic transaction: balance check, debit, decide, credit, log
        return $this->db->transactional(function (Connection $db) use (
            $playerId, $gameType, $colorPicks, $stakePesewas, $lockedDeck,
            $multiplier, $potentialPayout, $dailyStakeLimit,
            $clientIp, $userAgent
        ) {
            // ---- Daily stake cap check (sum of TODAY's stakes for this player)
            $todayDate = $this->businessDate();
            $todaysStakeSum = (int) ($db->fetchOne(
                "SELECT COALESCE(SUM(stakePesewas), 0) AS s
                   FROM gameRound
                  WHERE playerId = :pid
                    AND createdAt >= :start
                    AND createdAt <  :end",
                [
                    ':pid'   => $playerId,
                    ':start' => $todayDate . ' 00:00:00',
                    ':end'   => $todayDate . ' 23:59:59',
                ]
            )['s'] ?? 0);
            if ($todaysStakeSum + $stakePesewas > $dailyStakeLimit) {
                throw new HttpException(400, 
                    'daily_limit_exceeded',
                    'You have reached today\'s play limit of GHS ' . number_format($dailyStakeLimit / 100, 2) . '.'
                );
            }

            // ---- Look up player's PLAY and PAYOUT accounts
            $playAcc = $db->fetchOne(
                "SELECT id, accountCode FROM account
                  WHERE accountCode = :c LIMIT 1",
                [':c' => 'PLAYER_PLAY:' . $playerId]
            );
            $payoutAcc = $db->fetchOne(
                "SELECT id, accountCode FROM account
                  WHERE accountCode = :c LIMIT 1",
                [':c' => 'PLAYER_PAYOUT:' . $playerId]
            );
            $houseAcc = $db->fetchOne(
                "SELECT id FROM account WHERE accountCode = 'HOUSE_REVENUE' LIMIT 1"
            );
            if (!$playAcc || !$payoutAcc || !$houseAcc) {
                throw new HttpException(500, 'account_missing', 'Wallet not provisioned. Contact support.');
            }
            $playAccId   = (int) $playAcc['id'];
            $payoutAccId = (int) $payoutAcc['id'];
            $houseAccId  = (int) $houseAcc['id'];

            // ---- Check the player has enough in PLAY (lock the wallet row)
            $wallet = $db->fetchOne(
                "SELECT id, cachedBalancePesewas, version
                   FROM wallet
                  WHERE playerId = :pid AND walletType = 'PLAY'
                  FOR UPDATE",
                [':pid' => $playerId]
            );
            if (!$wallet) {
                throw new HttpException(500, 'wallet_missing', 'Play wallet not found.');
            }
            $currentBalance = (int) $wallet['cachedBalancePesewas'];
            if ($currentBalance < $stakePesewas) {
                throw new HttpException(400, 
                    'insufficient_balance',
                    'Insufficient Play balance. Top up to play.'
                );
            }

            // ---- Lock today's revenue summary row (the serialization point)
            $summary = $this->getOrCreateDailySummary($db, $todayDate);

            // ---- Snapshot the engine inputs at decision time
            $thresholdPct       = $this->config->getInt('engine.houseWinThresholdPct', 50);
            $floorPesewas       = (int) $summary['floorPesewas'];
            $winsToday          = (int) $summary['winsPesewas'];
            $lossesToday        = (int) $summary['lossesPesewas'];
            $netRevenueToday    = $lossesToday - $winsToday;
            $effectiveRevenue   = max($netRevenueToday, $floorPesewas);

            // ---- The decision
            // (winsToday + potentialPayout) ≤ effectiveRevenue × pct/100  → fair_random
            // To avoid float math: compare (winsToday + potentialPayout) * 100  vs  effectiveRevenue * pct
            $allowedPayoutCeiling = $effectiveRevenue * $thresholdPct;          // pesewas * pct
            $proposedPayoutScaled = ($winsToday + $potentialPayout) * 100;     // pesewas * 100

            $enginePath = ($proposedPayoutScaled <= $allowedPayoutCeiling)
                ? 'fair_random'
                : 'forced_loss';

            // Additional guard: if netRevenueToday is negative, force loss
            // regardless of the floor calc. This is the "house bleeding"
            // protection clarified in spec: "if netRevenue is negative,
            // every stake becomes a loss til we get positive".
            if ($netRevenueToday < 0) {
                $enginePath = 'forced_loss';
            }

            // ---- Pick drawn cards from the locked deck
            $drawnCards = $this->pickDrawnCards($lockedDeck, $colorPicks, $enginePath);

            // ---- Determine outcome (this is just verification; for fair_random
            //      it's whatever chance gave us; for forced_loss it's guaranteed loss)
            $allMatch = true;
            for ($i = 0; $i < $gameType; $i++) {
                if ($this->cardColor($drawnCards[$i]) !== $colorPicks[$i]) {
                    $allMatch = false;
                    break;
                }
            }
            $outcome      = $allMatch ? 'win' : 'loss';
            $payoutPesewas = $allMatch ? $potentialPayout : 0;

            // ---- Generate refNumber (16 chars, hex)
            $refNumber = 'STK-' . substr(bin2hex(random_bytes(8)), 0, 12);

            // ---- Book the STAKE wallet transaction
            // DEBIT  PLAYER_PLAY      stake
            // CREDIT HOUSE_REVENUE    stake
            $stakeTxnId = $db->insert(
                "INSERT INTO walletTransaction
                    (refNumber, txnType, playerId, amountPesewas, currency,
                     status, metadata, initiatedBy, channel, createdAt, completedAt)
                 VALUES
                    (:ref, 'STAKE', :pid, :amt, 'GHS',
                     'completed', :meta, 'player', 'web', NOW(), NOW())",
                [
                    ':ref' => $refNumber,
                    ':pid' => $playerId,
                    ':amt' => $stakePesewas,
                    ':meta' => json_encode(['gameType' => $gameType, 'multiplier' => $multiplier]),
                ]
            );
            $db->execute(
                "INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                 VALUES (:tx, :acc, 'debit', :amt, 'GHS', 'Game stake')",
                [':tx' => $stakeTxnId, ':acc' => $playAccId, ':amt' => $stakePesewas]
            );
            $db->execute(
                "INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                 VALUES (:tx, :acc, 'credit', :amt, 'GHS', 'Stake into house')",
                [':tx' => $stakeTxnId, ':acc' => $houseAccId, ':amt' => $stakePesewas]
            );
            // Decrement PLAY wallet cached balance
            $db->execute(
                "UPDATE wallet
                    SET cachedBalancePesewas = cachedBalancePesewas - :amt,
                        version = version + 1,
                        updatedAt = NOW()
                  WHERE id = :wid",
                [':amt' => $stakePesewas, ':wid' => $wallet['id']]
            );

            // ---- If win, book the WIN_PAYOUT
            $winTxnId = null;
            if ($outcome === 'win') {
                $winRefNumber = 'WIN-' . substr(bin2hex(random_bytes(8)), 0, 12);
                $winTxnId = $db->insert(
                    "INSERT INTO walletTransaction
                        (refNumber, txnType, playerId, amountPesewas, currency,
                         status, metadata, initiatedBy, channel, createdAt, completedAt)
                     VALUES
                        (:ref, 'WIN_PAYOUT', :pid, :amt, 'GHS',
                         'completed', :meta, 'system', 'web', NOW(), NOW())",
                    [
                        ':ref'  => $winRefNumber,
                        ':pid'  => $playerId,
                        ':amt'  => $payoutPesewas,
                        ':meta' => json_encode([
                            'roundRef'   => $refNumber,
                            'multiplier' => $multiplier,
                        ]),
                    ]
                );
                // DEBIT  HOUSE_REVENUE   payout
                // CREDIT PLAYER_PAYOUT   payout
                $db->execute(
                    "INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                     VALUES (:tx, :acc, 'debit', :amt, 'GHS', 'Game win payout')",
                    [':tx' => $winTxnId, ':acc' => $houseAccId, ':amt' => $payoutPesewas]
                );
                $db->execute(
                    "INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
                     VALUES (:tx, :acc, 'credit', :amt, 'GHS', 'Game win to payout wallet')",
                    [':tx' => $winTxnId, ':acc' => $payoutAccId, ':amt' => $payoutPesewas]
                );
                // Increment PAYOUT wallet cached balance
                $db->execute(
                    "UPDATE wallet
                        SET cachedBalancePesewas = cachedBalancePesewas + :amt,
                            version = version + 1,
                            updatedAt = NOW()
                      WHERE playerId = :pid AND walletType = 'PAYOUT'",
                    [':amt' => $payoutPesewas, ':pid' => $playerId]
                );
            }

            // ---- Update the daily summary
            if ($outcome === 'win') {
                $db->execute(
                    "UPDATE dailyRevenueSummary
                        SET winsPesewas  = winsPesewas + :p,
                            roundsCount  = roundsCount + 1,
                            updatedAt    = NOW()
                      WHERE businessDate = :d",
                    [':p' => $payoutPesewas, ':d' => $todayDate]
                );
            } else {
                $db->execute(
                    "UPDATE dailyRevenueSummary
                        SET lossesPesewas = lossesPesewas + :s,
                            roundsCount   = roundsCount + 1,
                            updatedAt     = NOW()
                      WHERE businessDate = :d",
                    [':s' => $stakePesewas, ':d' => $todayDate]
                );
            }

            // ---- Write audit row
            $db->insert(
                "INSERT INTO gameRound
                    (refNumber, playerId, gameType, multiplier, colorPicks,
                     stakePesewas, potentialPayoutPesewas,
                     enginePath, thresholdPctAtTime, netRevenuePesewas, winsTodayPesewas,
                     lockedDeck, drawnCards, outcome, payoutPesewas,
                     stakeWalletTxnId, winWalletTxnId, clientIp, userAgent, createdAt)
                 VALUES
                    (:ref, :pid, :gt, :mult, :picks,
                     :stake, :pot,
                     :path, :thresh, :netrev, :winstoday,
                     :deck, :drawn, :outcome, :payout,
                     :stxn, :wtxn, :ip, :ua, NOW())",
                [
                    ':ref'        => $refNumber,
                    ':pid'        => $playerId,
                    ':gt'         => $gameType,
                    ':mult'       => $multiplier,
                    ':picks'      => implode(',', $colorPicks),
                    ':stake'      => $stakePesewas,
                    ':pot'        => $potentialPayout,
                    ':path'       => $enginePath,
                    ':thresh'     => $thresholdPct,
                    ':netrev'     => $netRevenueToday,
                    ':winstoday'  => $winsToday,
                    ':deck'       => json_encode($lockedDeck),
                    ':drawn'      => json_encode($drawnCards),
                    ':outcome'    => $outcome,
                    ':payout'     => $payoutPesewas,
                    ':stxn'       => $stakeTxnId,
                    ':wtxn'       => $winTxnId,
                    ':ip'         => $clientIp,
                    ':ua'         => $userAgent !== null ? substr($userAgent, 0, 500) : null,
                ]
            );

            // ---- Read fresh balances to return
            $balances = $db->fetchOne(
                "SELECT
                    (SELECT cachedBalancePesewas FROM wallet WHERE playerId = :pid_a AND walletType = 'PLAY')   AS playBal,
                    (SELECT cachedBalancePesewas FROM wallet WHERE playerId = :pid_b AND walletType = 'PAYOUT') AS payoutBal",
                [':pid_a' => $playerId, ':pid_b' => $playerId]
            );

            $this->logger->info('game.round.completed', [
                'playerId'    => $playerId,
                'refNumber'   => $refNumber,
                'gameType'    => $gameType,
                'stake'       => $stakePesewas,
                'outcome'     => $outcome,
                'payout'      => $payoutPesewas,
                'enginePath'  => $enginePath,
                'thresholdPct'=> $thresholdPct,
                'netRevenue'  => $netRevenueToday,
                'winsToday'   => $winsToday,
            ]);

            return [
                'refNumber'        => $refNumber,
                'outcome'          => $outcome,
                'drawnCards'       => $drawnCards,
                'payoutPesewas'    => $payoutPesewas,
                'stakePesewas'     => $stakePesewas,
                'multiplier'       => $multiplier,
                'gameType'         => $gameType,
                'colorPicks'       => $colorPicks,
                'playBalance'      => (int) ($balances['playBal']   ?? 0),
                'payoutBalance'    => (int) ($balances['payoutBal'] ?? 0),
            ];
        });
    }

    // ---- Internal helpers ---------------------------------------------------

    /**
     * Validates the inputs that don't need a DB call to check.
     * Throws HttpException on the first problem.
     */
    private function validateInput(int $gameType, array $colorPicks, int $stake, array $deck): void
    {
        // Game type
        if (!isset(self::MULTIPLIERS[$gameType])) {
            throw new HttpException(400, 'bad_game_type', 'Invalid game type. Pick 1 to 5 cards.');
        }

        // Picks length
        if (count($colorPicks) !== $gameType) {
            throw new HttpException(400, 'picks_length_mismatch', 'Number of colour picks must match game type.');
        }
        // Picks values
        foreach ($colorPicks as $p) {
            if ($p !== 'red' && $p !== 'black') {
                throw new HttpException(400, 'bad_pick_value', 'Each pick must be red or black.');
            }
        }

        // Stake
        if ($stake < self::MIN_STAKE_PESEWAS) {
            throw new HttpException(400, 'stake_too_low', 'Minimum stake is GHS 2.');
        }

        // Deck size
        if (count($deck) !== 12) {
            throw new HttpException(400, 'deck_wrong_size', 'Deck must be exactly 12 cards.');
        }
        // Deck shape: valid codes, 6 red + 6 black, no duplicates
        $reds = 0; $blacks = 0;
        $seen = [];
        foreach ($deck as $card) {
            if (!is_string($card) || !$this->isValidCardCode($card)) {
                throw new HttpException(400, 'bad_card_code', 'Invalid card in deck.');
            }
            if (isset($seen[$card])) {
                throw new HttpException(400, 'deck_has_duplicates', 'Deck contains duplicate cards.');
            }
            $seen[$card] = true;
            $color = $this->cardColor($card);
            if ($color === 'red')   $reds++;
            else                    $blacks++;
        }
        if ($reds !== 6 || $blacks !== 6) {
            throw new HttpException(400, 'deck_unbalanced', 'Deck must be exactly 6 red and 6 black.');
        }
    }

    /** Card code validation: rank in {A,2,3,4,5,6,7,8,9,T,J,Q,K}, suit in {H,D,C,S}. */
    private function isValidCardCode(string $card): bool
    {
        if (strlen($card) !== 2) return false;
        $rank = $card[0];
        $suit = $card[1];
        return in_array($rank, ['A','2','3','4','5','6','7','8','9','T','J','Q','K'], true)
            && in_array($suit, ['H','D','C','S'], true);
    }

    /** Card color from suit: H,D = red ; C,S = black. */
    private function cardColor(string $card): string
    {
        return ($card[1] === 'H' || $card[1] === 'D') ? 'red' : 'black';
    }

    /**
     * Pick the N drawn cards from the locked deck, given the engine path.
     *
     * Fair random:
     *   Just shuffle the deck cryptographically and take the first N.
     *
     * Forced loss:
     *   Find an arrangement of N cards (drawn from the deck) such that the
     *   sequence does NOT match the player's colorPicks in order. This is
     *   always possible because the deck has 6 of each color (so for any
     *   pick sequence, we can always find at least one different-color card
     *   to insert at some position).
     *
     *   Algorithm:
     *     1. Pick a random "loss position" j from 0..N-1.
     *     2. The card at position j must be the OPPOSITE color of picks[j].
     *     3. The other N-1 positions are filled with random cards from the
     *        deck, drawn without replacement.
     *
     *   This means the player sees a believable loss — they may have got
     *   most picks right, just the one (or more) wrong.
     */
    private function pickDrawnCards(array $lockedDeck, array $colorPicks, string $enginePath): array
    {
        $n = count($colorPicks);

        if ($enginePath === 'fair_random') {
            // Cryptographic shuffle (Fisher-Yates with random_int)
            $deck = $lockedDeck;
            for ($i = count($deck) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                $tmp = $deck[$i]; $deck[$i] = $deck[$j]; $deck[$j] = $tmp;
            }
            return array_slice($deck, 0, $n);
        }

        // forced_loss
        // Group deck cards by color
        $byColor = ['red' => [], 'black' => []];
        foreach ($lockedDeck as $c) {
            $byColor[$this->cardColor($c)][] = $c;
        }
        // Shuffle each color group independently
        self::secureShuffle($byColor['red']);
        self::secureShuffle($byColor['black']);

        // Pick a random loss position
        $lossPos = random_int(0, $n - 1);

        // Build draws: position lossPos = wrong color; others = random pulls
        $drawn  = [];
        $usedRed = 0; $usedBlack = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($i === $lossPos) {
                // Take from the OPPOSITE color of picks[i]
                $opposite = $colorPicks[$i] === 'red' ? 'black' : 'red';
                if ($opposite === 'red') {
                    $drawn[] = $byColor['red'][$usedRed++];
                } else {
                    $drawn[] = $byColor['black'][$usedBlack++];
                }
            } else {
                // Free choice: 50/50 between remaining red/black supplies
                // (preferring whichever has more left to avoid edge cases)
                $remRed   = 6 - $usedRed;
                $remBlack = 6 - $usedBlack;
                if ($remRed > 0 && $remBlack > 0) {
                    $useRed = random_int(0, 1) === 0;
                } else {
                    $useRed = $remRed > 0;
                }
                if ($useRed) {
                    $drawn[] = $byColor['red'][$usedRed++];
                } else {
                    $drawn[] = $byColor['black'][$usedBlack++];
                }
            }
        }
        return $drawn;
    }

    /**
     * Today's business date in Africa/Accra format YYYY-MM-DD.
     */
    private function businessDate(): string
    {
        $tz = new \DateTimeZone(self::BUSINESS_TZ);
        return (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    /**
     * Get today's dailyRevenueSummary row, creating it (with current floor
     * snapshot) if it doesn't exist. The returned row is locked FOR UPDATE.
     */
    private function getOrCreateDailySummary(Connection $db, string $businessDate): array
    {
        $row = $db->fetchOne(
            "SELECT id, businessDate, floorPesewas, lossesPesewas, winsPesewas, roundsCount
               FROM dailyRevenueSummary
              WHERE businessDate = :d
              FOR UPDATE",
            [':d' => $businessDate]
        );
        if ($row) return $row;

        // Create it. Snapshot today's floor so admins can change config without
        // retroactively modifying past days' decisions.
        $floor = $this->config->getInt('engine.dailyRevenueFloorPesewas', 50000);
        $db->execute(
            "INSERT INTO dailyRevenueSummary
                (businessDate, floorPesewas, lossesPesewas, winsPesewas, roundsCount, createdAt, updatedAt)
             VALUES
                (:d, :f, 0, 0, 0, NOW(), NOW())",
            [':d' => $businessDate, ':f' => $floor]
        );
        // Re-fetch with FOR UPDATE
        return $db->fetchOne(
            "SELECT id, businessDate, floorPesewas, lossesPesewas, winsPesewas, roundsCount
               FROM dailyRevenueSummary
              WHERE businessDate = :d
              FOR UPDATE",
            [':d' => $businessDate]
        );
    }

    /** Cryptographic Fisher-Yates shuffle in-place using random_int. */
    private static function secureShuffle(array &$arr): void
    {
        for ($i = count($arr) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $tmp = $arr[$i]; $arr[$i] = $arr[$j]; $arr[$j] = $tmp;
        }
    }
}