<?php
/**
 * Game engine — USSD port of the web app's GameEngineService.
 *
 * CRITICAL: the outcome math, the house-protection decision, the card draw,
 * and the ledger entries MUST match the web app exactly. A player who plays
 * the same game on web vs USSD should face identical odds and identical
 * payouts. Any divergence is a fairness defect.
 *
 * The one legitimate difference: on web, the CLIENT generates a "locked deck"
 * of 12 cards and sends it to the server. On USSD there is no client to hold
 * a deck, so the SERVER generates the locked deck. This is strictly safer —
 * a server deck can't be tampered with — and produces identical statistical
 * behaviour because the web server validates the deck is exactly 6 red + 6
 * black anyway. We generate exactly 6 red + 6 black here.
 *
 * Decision rule (verbatim from web):
 *
 *   netRevenueToday  = lossesToday - winsToday          (may be negative)
 *   effectiveRevenue = max(netRevenueToday, dailyFloor)
 *
 *   IF (winsToday + potentialPayout) * 100 <= effectiveRevenue * thresholdPct
 *       → fair_random
 *   ELSE
 *       → forced_loss
 *
 *   PLUS: if netRevenueToday < 0, always forced_loss (house-bleeding guard).
 *
 * Money flow:
 *   Stake (always):  DEBIT PLAYER_PLAY,  CREDIT HOUSE_REVENUE
 *   Win (if win):    DEBIT HOUSE_REVENUE, CREDIT PLAYER_PAYOUT
 *   Winnings land in PAYOUT (not PLAY) — same as web.
 *
 * Card representation (matches web for audit-row reconciliation):
 *   "<rank><suit>" — rank A,2-9,T,J,Q,K ; suit H,D,C,S
 *   Color: H,D = red ; C,S = black.
 *
 * colorPicks stored as "red,black,red" (imploded) — matches web's
 * gameRound.colorPicks format so LastStakeState and web read identically.
 */

const GAME_MULTIPLIERS = [1 => 2, 2 => 10, 3 => 20, 4 => 50, 5 => 100];
const GAME_MIN_STAKE_PESEWAS = 200;       // GHS 2
const GAME_BUSINESS_TZ = 'Africa/Accra';

/**
 * Multiplier for a game type (1-5). Throws on invalid type.
 */
function gameMultiplier(int $gameType): int
{
    if (!isset(GAME_MULTIPLIERS[$gameType])) {
        throw new InvalidArgumentException("Invalid game type: $gameType");
    }
    return GAME_MULTIPLIERS[$gameType];
}

/**
 * Card color from suit. H,D = red ; C,S = black.
 */
function gameCardColor(string $card): string
{
    return ($card[1] === 'H' || $card[1] === 'D') ? 'red' : 'black';
}

/**
 * Generate a locked deck of 12 cards: exactly 6 red + 6 black, no duplicates.
 *
 * We use the full 52-card identity space but only need 12 distinct cards with
 * the right color balance. Pick 6 distinct red cards and 6 distinct black,
 * then shuffle the combined deck.
 */
function gameGenerateLockedDeck(): array
{
    $redCards   = [];  // H, D suits
    $blackCards = [];  // C, S suits
    $ranks = ['A','2','3','4','5','6','7','8','9','T','J','Q','K'];

    foreach (['H','D'] as $suit) {
        foreach ($ranks as $rank) $redCards[] = $rank . $suit;
    }
    foreach (['C','S'] as $suit) {
        foreach ($ranks as $rank) $blackCards[] = $rank . $suit;
    }

    gameSecureShuffle($redCards);
    gameSecureShuffle($blackCards);

    $deck = array_merge(
        array_slice($redCards, 0, 6),
        array_slice($blackCards, 0, 6)
    );
    gameSecureShuffle($deck);

    return $deck;
}

/**
 * Cryptographic Fisher-Yates shuffle in-place using random_int.
 */
function gameSecureShuffle(array &$arr): void
{
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $arr[$i]; $arr[$i] = $arr[$j]; $arr[$j] = $tmp;
    }
}

/**
 * Pick the N drawn cards from the locked deck given the engine path.
 * Verbatim logic from web GameEngineService::pickDrawnCards.
 */
function gamePickDrawnCards(array $lockedDeck, array $colorPicks, string $enginePath): array
{
    $n = count($colorPicks);

    if ($enginePath === 'fair_random') {
        $deck = $lockedDeck;
        gameSecureShuffle($deck);
        return array_slice($deck, 0, $n);
    }

    // forced_loss
    $byColor = ['red' => [], 'black' => []];
    foreach ($lockedDeck as $c) {
        $byColor[gameCardColor($c)][] = $c;
    }
    gameSecureShuffle($byColor['red']);
    gameSecureShuffle($byColor['black']);

    $lossPos = random_int(0, $n - 1);

    $drawn = [];
    $usedRed = 0; $usedBlack = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i === $lossPos) {
            // Opposite color of the player's pick at this position
            $opposite = $colorPicks[$i] === 'red' ? 'black' : 'red';
            if ($opposite === 'red') {
                $drawn[] = $byColor['red'][$usedRed++];
            } else {
                $drawn[] = $byColor['black'][$usedBlack++];
            }
        } else {
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
 * Today's business date (Africa/Accra) as YYYY-MM-DD.
 */
function gameBusinessDate(): string
{
    $tz = new DateTimeZone(GAME_BUSINESS_TZ);
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

/**
 * Validate play inputs that don't need a DB call. Returns null if valid,
 * else a user-safe error string.
 */
function gameValidateInput(int $gameType, array $colorPicks, int $stakePesewas): ?string
{
    if (!isset(GAME_MULTIPLIERS[$gameType])) {
        return "Invalid game. Pick 1 to 5 cards.";
    }
    if (count($colorPicks) !== $gameType) {
        return "Number of picks must match the game.";
    }
    foreach ($colorPicks as $p) {
        if ($p !== 'red' && $p !== 'black') {
            return "Each pick must be Red or Black.";
        }
    }
    if ($stakePesewas < GAME_MIN_STAKE_PESEWAS) {
        return "Minimum stake is GHS 2.";
    }
    return null;
}

/**
 * The single play entry point. Runs the full atomic round.
 *
 * @param  int    $playerId
 * @param  int    $gameType      1..5
 * @param  array  $colorPicks    ['red','black',...] length == gameType
 * @param  int    $stakePesewas
 * @param  string $clientIp
 *
 * @return array {
 *   refNumber, outcome ('win'|'loss'), drawnColors (['red','black',...]),
 *   payoutPesewas, stakePesewas, multiplier, gameType, colorPicks,
 *   playBalance, payoutBalance
 * }
 *
 * @throws InvalidArgumentException on bad input
 * @throws RuntimeException on insufficient balance / limit / ineligible
 */
function gamePlay(int $playerId, int $gameType, array $colorPicks, int $stakePesewas, string $clientIp): array
{
    global $USSD_CONFIG;

    $err = gameValidateInput($gameType, $colorPicks, $stakePesewas);
    if ($err !== null) {
        throw new InvalidArgumentException($err);
    }

    $multiplier      = gameMultiplier($gameType);
    $potentialPayout = $stakePesewas * $multiplier;
    $maxStake        = (int)($USSD_CONFIG['game']['max_stake_pesewas'] ?? 200000);
    $dailyStakeLimit = (int)($USSD_CONFIG['game']['daily_stake_limit_pesewas'] ?? 2000000);

    if ($stakePesewas > $maxStake) {
        throw new InvalidArgumentException(
            sprintf('Maximum stake is GHS %s.', formatPesewas($maxStake))
        );
    }

    // Engine tuning (mirror web's systemConfig defaults)
    $thresholdPct = (int)($USSD_CONFIG['game']['house_win_threshold_pct'] ?? 20);
    $floorPesewas = (int)($USSD_CONFIG['game']['daily_revenue_floor_pesewas'] ?? 50000);

    $player = playerById($playerId);
    if ($player === null
        || $player['accountStatus'] !== 'active'
        || ($player['deletedAt'] ?? null) !== null
    ) {
        throw new RuntimeException("Account not eligible to play.");
    }

    return dbTxn(function (PDO $pdo) use (
        $playerId, $gameType, $colorPicks, $stakePesewas,
        $multiplier, $potentialPayout, $dailyStakeLimit,
        $thresholdPct, $floorPesewas, $clientIp
    ): array {
        $todayDate = gameBusinessDate();

        // ---- Daily stake cap ----
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(stakePesewas), 0) AS s
               FROM gameRound
              WHERE playerId = :pid
                AND createdAt >= :start AND createdAt < :end"
        );
        $stmt->execute([
            ':pid'   => $playerId,
            ':start' => $todayDate . ' 00:00:00',
            ':end'   => $todayDate . ' 23:59:59',
        ]);
        $todaysStakeSum = (int)($stmt->fetch()['s'] ?? 0);
        if ($todaysStakeSum + $stakePesewas > $dailyStakeLimit) {
            throw new RuntimeException(
                sprintf("You've reached today's play limit of GHS %s.", formatPesewas($dailyStakeLimit))
            );
        }

        // ---- Look up accounts ----
        $playAcc = gameAccountByCode($pdo, 'PLAYER_PLAY:' . $playerId);
        $payoutAcc = gameAccountByCode($pdo, 'PLAYER_PAYOUT:' . $playerId);
        $houseAcc = gameAccountByCode($pdo, 'HOUSE_REVENUE');
        if ($playAcc === null || $payoutAcc === null || $houseAcc === null) {
            throw new RuntimeException("Wallet not provisioned. Contact support.");
        }
        $playAccId   = (int)$playAcc['id'];
        $payoutAccId = (int)$payoutAcc['id'];
        $houseAccId  = (int)$houseAcc['id'];

        // ---- Lock PLAY wallet, check balance ----
        $stmt = $pdo->prepare(
            "SELECT id, cachedBalancePesewas FROM wallet
              WHERE playerId = :pid AND walletType = 'PLAY'
              FOR UPDATE"
        );
        $stmt->execute([':pid' => $playerId]);
        $wallet = $stmt->fetch();
        if ($wallet === false) {
            throw new RuntimeException("Play wallet not found.");
        }
        if ((int)$wallet['cachedBalancePesewas'] < $stakePesewas) {
            throw new RuntimeException("Insufficient Play balance. Top up to play.");
        }
        $walletId = (int)$wallet['id'];

        // ---- Lock today's revenue summary (serialization point) ----
        $summary = gameGetOrCreateDailySummary($pdo, $todayDate, $floorPesewas);
        $winsToday       = (int)$summary['winsPesewas'];
        $lossesToday     = (int)$summary['lossesPesewas'];
        $summaryFloor    = (int)$summary['floorPesewas'];
        $netRevenueToday = $lossesToday - $winsToday;
        $effectiveRevenue = max($netRevenueToday, $summaryFloor);

        // ---- The decision (integer math, no floats) ----
        $allowedPayoutCeiling = $effectiveRevenue * $thresholdPct;
        $proposedPayoutScaled = ($winsToday + $potentialPayout) * 100;
        $enginePath = ($proposedPayoutScaled <= $allowedPayoutCeiling)
            ? 'fair_random'
            : 'forced_loss';
        if ($netRevenueToday < 0) {
            $enginePath = 'forced_loss';
        }

        // ---- Generate deck + draw ----
        $lockedDeck = gameGenerateLockedDeck();
        $drawnCards = gamePickDrawnCards($lockedDeck, $colorPicks, $enginePath);

        // ---- Determine outcome ----
        $allMatch = true;
        for ($i = 0; $i < $gameType; $i++) {
            if (gameCardColor($drawnCards[$i]) !== $colorPicks[$i]) {
                $allMatch = false;
                break;
            }
        }
        $outcome       = $allMatch ? 'win' : 'loss';
        $payoutPesewas = $allMatch ? $potentialPayout : 0;

        $refNumber = 'STK-' . substr(bin2hex(random_bytes(8)), 0, 12);

        // ---- Book STAKE: DEBIT PLAY, CREDIT HOUSE ----
        $stmt = $pdo->prepare(
            "INSERT INTO walletTransaction
                (refNumber, txnType, playerId, amountPesewas, currency,
                 status, metadata, initiatedBy, channel, createdAt, completedAt)
             VALUES
                (:ref, 'STAKE', :pid, :amt, 'GHS',
                 'completed', :meta, 'player', 'ussd', NOW(), NOW())"
        );
        $stmt->execute([
            ':ref'  => $refNumber,
            ':pid'  => $playerId,
            ':amt'  => $stakePesewas,
            ':meta' => json_encode(['gameType' => $gameType, 'multiplier' => $multiplier]),
        ]);
        $stakeTxnId = (int)$pdo->lastInsertId();

        $ledger = $pdo->prepare(
            "INSERT INTO ledgerEntry (walletTxnId, accountId, side, amountPesewas, currency, description)
             VALUES (:tx, :acc, :side, :amt, 'GHS', :desc)"
        );
        $ledger->execute([':tx'=>$stakeTxnId, ':acc'=>$playAccId,  ':side'=>'debit',  ':amt'=>$stakePesewas, ':desc'=>'Game stake']);
        $ledger->execute([':tx'=>$stakeTxnId, ':acc'=>$houseAccId, ':side'=>'credit', ':amt'=>$stakePesewas, ':desc'=>'Stake into house']);

        $pdo->prepare(
            "UPDATE wallet
                SET cachedBalancePesewas = cachedBalancePesewas - :amt,
                    version = version + 1, updatedAt = NOW()
              WHERE id = :wid"
        )->execute([':amt' => $stakePesewas, ':wid' => $walletId]);

        // ---- If win: book WIN_PAYOUT: DEBIT HOUSE, CREDIT PAYOUT ----
        $winTxnId = null;
        if ($outcome === 'win') {
            $winRef = 'WIN-' . substr(bin2hex(random_bytes(8)), 0, 12);
            $stmt = $pdo->prepare(
                "INSERT INTO walletTransaction
                    (refNumber, txnType, playerId, amountPesewas, currency,
                     status, metadata, initiatedBy, channel, createdAt, completedAt)
                 VALUES
                    (:ref, 'WIN_PAYOUT', :pid, :amt, 'GHS',
                     'completed', :meta, 'system', 'ussd', NOW(), NOW())"
            );
            $stmt->execute([
                ':ref'  => $winRef,
                ':pid'  => $playerId,
                ':amt'  => $payoutPesewas,
                ':meta' => json_encode(['roundRef' => $refNumber, 'multiplier' => $multiplier]),
            ]);
            $winTxnId = (int)$pdo->lastInsertId();

            $ledger->execute([':tx'=>$winTxnId, ':acc'=>$houseAccId,  ':side'=>'debit',  ':amt'=>$payoutPesewas, ':desc'=>'Game win payout']);
            $ledger->execute([':tx'=>$winTxnId, ':acc'=>$payoutAccId, ':side'=>'credit', ':amt'=>$payoutPesewas, ':desc'=>'Game win to payout wallet']);

            $pdo->prepare(
                "UPDATE wallet
                    SET cachedBalancePesewas = cachedBalancePesewas + :amt,
                        version = version + 1, updatedAt = NOW()
                  WHERE playerId = :pid AND walletType = 'PAYOUT'"
            )->execute([':amt' => $payoutPesewas, ':pid' => $playerId]);
        }

        // ---- Update daily summary ----
        if ($outcome === 'win') {
            $pdo->prepare(
                "UPDATE dailyRevenueSummary
                    SET winsPesewas = winsPesewas + :p, roundsCount = roundsCount + 1, updatedAt = NOW()
                  WHERE businessDate = :d"
            )->execute([':p' => $payoutPesewas, ':d' => $todayDate]);
        } else {
            $pdo->prepare(
                "UPDATE dailyRevenueSummary
                    SET lossesPesewas = lossesPesewas + :s, roundsCount = roundsCount + 1, updatedAt = NOW()
                  WHERE businessDate = :d"
            )->execute([':s' => $stakePesewas, ':d' => $todayDate]);
        }

        // ---- Audit row ----
        // NOTE: column set confirmed against live SHOW COLUMNS. colorPicks stored
        // as "red,black,red" to match the web app's implode(',', ...) format.
        gameInsertRoundRow($pdo, [
            'refNumber'        => $refNumber,
            'playerId'         => $playerId,
            'gameType'         => $gameType,
            'multiplier'       => $multiplier,
            'colorPicks'       => implode(',', $colorPicks),
            'stakePesewas'     => $stakePesewas,
            'potentialPayout'  => $potentialPayout,
            'enginePath'       => $enginePath,
            'thresholdPct'     => $thresholdPct,
            'netRevenue'       => $netRevenueToday,
            'winsToday'        => $winsToday,
            'lockedDeck'       => json_encode($lockedDeck),
            'drawnCards'       => json_encode($drawnCards),
            'outcome'          => $outcome,
            'payoutPesewas'    => $payoutPesewas,
            'stakeTxnId'       => $stakeTxnId,
            'winTxnId'         => $winTxnId,
            'clientIp'         => $clientIp,
        ]);

        // ---- Fresh balances ----
        $stmt = $pdo->prepare(
            "SELECT walletType, cachedBalancePesewas FROM wallet
              WHERE playerId = :pid AND walletType IN ('PLAY','PAYOUT')"
        );
        $stmt->execute([':pid' => $playerId]);
        $bal = ['PLAY' => 0, 'PAYOUT' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $bal[(string)$row['walletType']] = (int)$row['cachedBalancePesewas'];
        }

        $drawnColors = array_map('gameCardColor', $drawnCards);

        ussdLog('GAME_ROUND', [
            'refNumber'  => $refNumber,
            'playerId'   => $playerId,
            'gameType'   => $gameType,
            'stake'      => $stakePesewas,
            'outcome'    => $outcome,
            'payout'     => $payoutPesewas,
            'enginePath' => $enginePath,
        ]);

        return [
            'refNumber'     => $refNumber,
            'outcome'       => $outcome,
            'drawnColors'   => $drawnColors,
            'payoutPesewas' => $payoutPesewas,
            'stakePesewas'  => $stakePesewas,
            'multiplier'    => $multiplier,
            'gameType'      => $gameType,
            'colorPicks'    => $colorPicks,
            'playBalance'   => $bal['PLAY'],
            'payoutBalance' => $bal['PAYOUT'],
        ];
    });
}

/**
 * Account lookup by code, within an open transaction.
 */
function gameAccountByCode(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare("SELECT id, accountCode FROM account WHERE accountCode = :c LIMIT 1");
    $stmt->execute([':c' => $code]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Get-or-create today's dailyRevenueSummary row, locked FOR UPDATE.
 */
function gameGetOrCreateDailySummary(PDO $pdo, string $businessDate, int $floorPesewas): array
{
    $sel = $pdo->prepare(
        "SELECT id, businessDate, floorPesewas, lossesPesewas, winsPesewas, roundsCount
           FROM dailyRevenueSummary WHERE businessDate = :d FOR UPDATE"
    );
    $sel->execute([':d' => $businessDate]);
    $row = $sel->fetch();
    if ($row !== false) return $row;

    $pdo->prepare(
        "INSERT INTO dailyRevenueSummary
            (businessDate, floorPesewas, lossesPesewas, winsPesewas, roundsCount, createdAt, updatedAt)
         VALUES (:d, :f, 0, 0, 0, NOW(), NOW())"
    )->execute([':d' => $businessDate, ':f' => $floorPesewas]);

    $sel->execute([':d' => $businessDate]);
    return $sel->fetch();
}

/**
 * Insert the gameRound audit row. Isolated so the exact column set can be
 * tuned to the live schema without touching gamePlay().
 *
 * Column set verified against live SHOW COLUMNS FROM gameRound.
 */
function gameInsertRoundRow(PDO $pdo, array $d): void
{
    $pdo->prepare(
        "INSERT INTO gameRound
            (refNumber, playerId, gameType, multiplier, colorPicks,
             stakePesewas, potentialPayoutPesewas,
             enginePath, thresholdPctAtTime, netRevenuePesewas, winsTodayPesewas,
             lockedDeck, drawnCards, outcome, payoutPesewas,
             stakeWalletTxnId, winWalletTxnId, clientIp, createdAt)
         VALUES
            (:ref, :pid, :gt, :mult, :picks,
             :stake, :pot,
             :path, :thresh, :netrev, :winstoday,
             :deck, :drawn, :outcome, :payout,
             :stxn, :wtxn, :ip, NOW())"
    )->execute([
        ':ref'       => $d['refNumber'],
        ':pid'       => $d['playerId'],
        ':gt'        => $d['gameType'],
        ':mult'      => $d['multiplier'],
        ':picks'     => $d['colorPicks'],
        ':stake'     => $d['stakePesewas'],
        ':pot'       => $d['potentialPayout'],
        ':path'      => $d['enginePath'],
        ':thresh'    => $d['thresholdPct'],
        ':netrev'    => $d['netRevenue'],
        ':winstoday' => $d['winsToday'],
        ':deck'      => $d['lockedDeck'],
        ':drawn'     => $d['drawnCards'],
        ':outcome'   => $d['outcome'],
        ':payout'    => $d['payoutPesewas'],
        ':stxn'      => $d['stakeTxnId'],
        ':wtxn'      => $d['winTxnId'],
        ':ip'        => $d['clientIp'],
    ]);
}
