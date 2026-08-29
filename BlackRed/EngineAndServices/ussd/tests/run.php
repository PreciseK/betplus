<?php
/**
 * USSD app — test runner.
 *
 * Run: php tests/run.php
 *
 * Uses in-memory SQLite. We monkey-patch db() before loading any lib file
 * so the cached PDO points at SQLite, then load lib/* on top of that.
 *
 * SQLite-vs-MariaDB caveats handled:
 *   - FOR UPDATE: stripped from session.php at load time below
 *   - JSON column: SQLite treats as TEXT — fine for our queries
 */

declare(strict_types=1);

const TEST_DSN = 'sqlite::memory:';

$GLOBALS['USSD_CONFIG'] = [
    'db'      => ['host' => '', 'port' => 0, 'name' => '', 'user' => '', 'pass' => '', 'charset' => 'utf8mb4'],
    'log'     => ['path' => '/tmp/ussd_test.log'],
    'env'     => 'test',
    'deposit' => ['min_pesewas' => 200, 'max_pesewas' => 500000, 'reference' => 'BlackRed'],
    'anm'     => ['callback_url' => 'http://test/cb', 'base_url' => 'http://test'],
    'game'    => [
        'min_stake_pesewas' => 200, 'max_stake_pesewas' => 200000,
        'daily_stake_limit_pesewas' => 2000000,
        'house_win_threshold_pct' => 20, 'daily_revenue_floor_pesewas' => 50000,
    ],
];

// -----------------------------------------------------------------------------
// Stub db() with SQLite + the test schema
// -----------------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO(TEST_DSN);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Shim MariaDB date functions so production SQL works unchanged in SQLite
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('DATE_ADD', function ($base, $offset) {
        // Strip the "INTERVAL N UNIT" wrapping that the SQL has — we hack this
        // by intercepting the function as-called; SQLite doesn't preserve the
        // INTERVAL keyword, so this is invoked with already-extracted numeric/text
        // We won't actually exercise the date math in tests; the cache logic
        // is tested separately. Returning $base is safe.
        return $base;
    });

    $pdo->exec("
        CREATE TABLE ussdSession (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            naloSessionId TEXT UNIQUE NOT NULL,
            msisdn TEXT NOT NULL,
            network TEXT,
            playerId INTEGER,
            state TEXT NOT NULL,
            data TEXT NOT NULL DEFAULT '[]',
            pwRetries INTEGER NOT NULL DEFAULT 0,
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
            updatedAt TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE player (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            msisdn TEXT UNIQUE NOT NULL,
            paymentProvider TEXT NOT NULL,
            registeredName TEXT NOT NULL,
            displayName TEXT,
            email TEXT,
            passwordHash TEXT,
            pinHash TEXT,
            kycStatus TEXT NOT NULL DEFAULT 'pending',
            accountStatus TEXT NOT NULL DEFAULT 'active',
            registrationChannel TEXT NOT NULL,
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
            updatedAt TEXT DEFAULT CURRENT_TIMESTAMP,
            deletedAt TEXT
        );
        CREATE TABLE wallet (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            playerId INTEGER NOT NULL,
            walletType TEXT NOT NULL,
            accountId INTEGER NOT NULL,
            cachedBalancePesewas INTEGER NOT NULL DEFAULT 0,
            version INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
            updatedAt TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE gameRound (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            refNumber TEXT NOT NULL,
            playerId INTEGER NOT NULL,
            gameType INTEGER NOT NULL,
            multiplier INTEGER NOT NULL,
            colorPicks TEXT NOT NULL,
            stakePesewas INTEGER NOT NULL,
            potentialPayoutPesewas INTEGER,
            enginePath TEXT,
            thresholdPctAtTime REAL,
            netRevenuePesewas INTEGER,
            winsTodayPesewas INTEGER,
            lockedDeck TEXT,
            drawnCards TEXT,
            outcome TEXT NOT NULL,
            payoutPesewas INTEGER NOT NULL DEFAULT 0,
            stakeWalletTxnId INTEGER,
            winWalletTxnId INTEGER,
            clientIp TEXT,
            userAgent TEXT,
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE dailyRevenueSummary (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            businessDate TEXT UNIQUE NOT NULL,
            floorPesewas INTEGER NOT NULL,
            lossesPesewas INTEGER NOT NULL DEFAULT 0,
            winsPesewas INTEGER NOT NULL DEFAULT 0,
            roundsCount INTEGER NOT NULL DEFAULT 0,
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
            updatedAt TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE account (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            accountCode TEXT UNIQUE NOT NULL,
            accountType TEXT NOT NULL,
            ownerType TEXT NOT NULL,
            ownerId INTEGER,
            currency TEXT NOT NULL DEFAULT 'GHS',
            normalBalance TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
            updatedAt TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE nameLookupCache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            msisdn TEXT NOT NULL,
            provider TEXT NOT NULL,
            returnedName TEXT,
            lookupStatus TEXT NOT NULL,
            rawResponse TEXT,
            lookedUpAt TEXT NOT NULL,
            expiresAt TEXT
        );
        CREATE TABLE depositRequest (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            refNumber TEXT UNIQUE NOT NULL,
            playerId INTEGER NOT NULL,
            msisdn TEXT NOT NULL,
            paymentProvider TEXT NOT NULL,
            amountPesewas INTEGER NOT NULL,
            feePesewas INTEGER NOT NULL DEFAULT 0,
            currency TEXT NOT NULL DEFAULT 'GHS',
            momoTxnId TEXT,
            momoRequestPayload TEXT,
            momoResponsePayload TEXT,
            status TEXT NOT NULL DEFAULT 'initiated',
            failureCode TEXT,
            failureReason TEXT,
            walletTxnId INTEGER,
            channel TEXT NOT NULL,
            initiatedAt TEXT DEFAULT CURRENT_TIMESTAMP,
            confirmedAt TEXT,
            lastPolledAt TEXT,
            expiresAt TEXT,
            clientIp TEXT,
            callbackIp TEXT
        );
        CREATE TABLE withdrawalRequest (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            refNumber TEXT UNIQUE NOT NULL,
            playerId INTEGER NOT NULL,
            msisdn TEXT NOT NULL,
            paymentProvider TEXT NOT NULL,
            destination TEXT NOT NULL DEFAULT 'MOMO',
            sourceWallet TEXT NOT NULL,
            amountPesewas INTEGER NOT NULL,
            feePesewas INTEGER NOT NULL DEFAULT 0,
            currency TEXT NOT NULL DEFAULT 'GHS',
            playBalanceAtRequestPesewas INTEGER,
            fiftyPercentRuleApplied INTEGER NOT NULL DEFAULT 0,
            fiftyPercentRulePassed INTEGER NOT NULL DEFAULT 0,
            momoTxnId TEXT,
            momoRequestPayload TEXT,
            momoResponsePayload TEXT,
            status TEXT NOT NULL DEFAULT 'initiated',
            blockReason TEXT,
            failureCode TEXT,
            failureReason TEXT,
            walletTxnId INTEGER,
            channel TEXT NOT NULL,
            initiatedAt TEXT DEFAULT CURRENT_TIMESTAMP,
            expiresAt TEXT,
            clientIp TEXT,
            callbackIp TEXT,
            approvedAt TEXT,
            completedAt TEXT,
            lastPolledAt TEXT
        );
        CREATE TABLE walletTransaction (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            refNumber TEXT UNIQUE NOT NULL,
            txnType TEXT NOT NULL,
            playerId INTEGER,
            amountPesewas INTEGER NOT NULL,
            currency TEXT NOT NULL DEFAULT 'GHS',
            status TEXT NOT NULL DEFAULT 'pending',
            failureReason TEXT,
            relatedTxnId INTEGER,
            metadata TEXT,
            initiatedBy TEXT,
            channel TEXT,
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP,
            completedAt TEXT
        );
        CREATE TABLE ledgerEntry (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            walletTxnId INTEGER NOT NULL,
            accountId INTEGER NOT NULL,
            side TEXT NOT NULL,
            amountPesewas INTEGER NOT NULL,
            currency TEXT NOT NULL DEFAULT 'GHS',
            description TEXT,
            postedAt TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE passwordResetRequest (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            playerId INTEGER NOT NULL,
            status TEXT NOT NULL,
            usedAt TEXT
        );
    ");

    // Seed system accounts that deposit settlement needs to find
    $pdo->exec("
        INSERT INTO account (accountCode, accountType, ownerType, currency, normalBalance, status)
        VALUES
            ('MOMO_FLOAT_MTN',   'MOMO_FLOAT_MTN',   'momo',   'GHS', 'debit',  'active'),
            ('MOMO_FLOAT_TEL',   'MOMO_FLOAT_TEL',   'momo',   'GHS', 'debit',  'active'),
            ('MOMO_FLOAT_ATL',   'MOMO_FLOAT_ATL',   'momo',   'GHS', 'debit',  'active'),
            ('MOMO_FEE_EXPENSE', 'MOMO_FEE_EXPENSE', 'house',  'GHS', 'debit',  'active'),
            ('HOUSE_REVENUE',    'HOUSE_REVENUE',    'house',  'GHS', 'credit', 'active')
    ");

    return $pdo;
}

function dbTxn(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// -----------------------------------------------------------------------------
// Load lib files. session.php has FOR UPDATE in its query — SQLite doesn't
// understand it, so we either strip it or override the driver-check inside
// the file. We strip via eval to keep the production file pristine.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../lib/log.php';
require_once __DIR__ . '/../lib/msisdn.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/player.php';
require_once __DIR__ . '/../lib/password.php';

// Stub anmNameLookup() before any state file loads it. The states call
// anmNameLookup($phoneLocal, $bankCode); we control the response via
// $GLOBALS['testAnmStub'].
$GLOBALS['testAnmStub'] = ['name' => 'KOFI MENSAH', 'shouldThrow' => false, 'errorMsg' => ''];
function anmNameLookup(string $phoneLocal, string $bankCode): string
{
    $stub = $GLOBALS['testAnmStub'];
    if ($stub['shouldThrow']) {
        throw new RuntimeException($stub['errorMsg']);
    }
    return $stub['name'];
}
function anmNetworkToBankCode(string $naloNetwork): ?string
{
    return match (strtoupper(trim($naloNetwork))) {
        'MTN'                       => 'MTN',
        'TELECEL', 'VODAFONE', 'VOD' => 'VOD',
        'AIRTELTIGO', 'AIR', 'ATL'   => 'AIR',
        default => null,
    };
}
function anmBankCodeToProvider(string $bankCode): string
{
    return match (strtoupper($bankCode)) {
        'MTN' => 'MTN',
        'VOD' => 'TEL',
        'AIR' => 'ATL',
        default => throw new InvalidArgumentException("Unknown bank_code: $bankCode"),
    };
}

// Stub Hubtel SMS — record calls in $GLOBALS so tests can assert.
$GLOBALS['testSmsLog'] = [];
$GLOBALS['testSmsShouldFail'] = false;
function hubtelSendSms(string $to, string $content): bool
{
    $GLOBALS['testSmsLog'][] = ['to' => $to, 'content' => $content];
    return !$GLOBALS['testSmsShouldFail'];
}
function hubtelSendWelcomeSms(string $to, string $firstName): bool
{
    $content = "Hello $firstName, Welcome to BlackRed! Your account has been registered successfully. Place your stakes now and stand a chance to win big!";
    return hubtelSendSms($to, $content);
}

// Stub anmInitiateCtm. Controlled via $GLOBALS['testAnmCtmStub'].
// Default = accepted with resp_code 015 (the CTM happy path).
$GLOBALS['testAnmCtmStub'] = [
    'accepted'    => true,
    'respCode'    => '015',
    'respDesc'    => 'Request successfully received for processing',
    'shouldThrow' => false,
    'errorMsg'    => '',
];
function anmInitiateCtm(
    string $exttrid, string $phoneLocal, string $bankCode,
    string $amountGhs, string $reference, string $callbackUrl
): array {
    $stub = $GLOBALS['testAnmCtmStub'];
    if ($stub['shouldThrow']) {
        throw new RuntimeException($stub['errorMsg']);
    }
    return [
        'accepted' => (bool)$stub['accepted'],
        'respCode' => $stub['respCode'],
        'respDesc' => $stub['respDesc'],
        'raw'      => ['resp_code' => $stub['respCode'], 'resp_desc' => $stub['respDesc']],
    ];
}

// Same shape for MTC. Default = accepted.
$GLOBALS['testAnmMtcStub'] = [
    'accepted'    => true,
    'respCode'    => '015',
    'respDesc'    => 'Request successfully received for processing',
    'shouldThrow' => false,
    'errorMsg'    => '',
];
function anmInitiateMtc(
    string $exttrid, string $phoneLocal, string $bankCode,
    string $amountGhs, string $reference, string $callbackUrl
): array {
    $stub = $GLOBALS['testAnmMtcStub'];
    if ($stub['shouldThrow']) {
        throw new RuntimeException($stub['errorMsg']);
    }
    return [
        'accepted' => (bool)$stub['accepted'],
        'respCode' => $stub['respCode'],
        'respDesc' => $stub['respDesc'],
        'raw'      => ['resp_code' => $stub['respCode'], 'resp_desc' => $stub['respDesc']],
    ];
}

$sessionSource = file_get_contents(__DIR__ . '/../lib/session.php');
$sessionSource = preg_replace('/^<\?php/', '', $sessionSource);
$sessionSource = str_replace('FOR UPDATE', '', $sessionSource);
eval($sessionSource);

// Load deposit.php with FOR UPDATE stripped (SQLite doesn't support it)
$depositSource = file_get_contents(__DIR__ . '/../lib/deposit.php');
$depositSource = preg_replace('/^<\?php/', '', $depositSource);
$depositSource = str_replace('FOR UPDATE', '', $depositSource);
eval($depositSource);

// Load withdrawal.php with FOR UPDATE stripped
$withdrawalSource = file_get_contents(__DIR__ . '/../lib/withdrawal.php');
$withdrawalSource = preg_replace('/^<\?php/', '', $withdrawalSource);
$withdrawalSource = str_replace('FOR UPDATE', '', $withdrawalSource);
eval($withdrawalSource);

// Load game.php with FOR UPDATE stripped
$gameSource = file_get_contents(__DIR__ . '/../lib/game.php');
$gameSource = preg_replace('/^<\?php/', '', $gameSource);
$gameSource = str_replace('FOR UPDATE', '', $gameSource);
eval($gameSource);

require_once __DIR__ . '/../lib/states.php';

// -----------------------------------------------------------------------------
// Seed helpers
// -----------------------------------------------------------------------------
function seedPlayer(string $msisdn, string $name, string $status = 'active'): int
{
    db()->prepare(
        "INSERT INTO player (msisdn, paymentProvider, registeredName, accountStatus, registrationChannel)
         VALUES (:m, 'MTN', :n, :s, 'web')"
    )->execute([':m' => $msisdn, ':n' => $name, ':s' => $status]);
    $id = (int) db()->lastInsertId();

    // Create real account rows so depositSettleSuccess() can find the PLAY account
    db()->prepare(
        "INSERT INTO account (accountCode, accountType, ownerType, ownerId, currency, normalBalance, status)
         VALUES (:c1, 'PLAYER_PLAY', 'player', :p, 'GHS', 'credit', 'active'),
                (:c2, 'PLAYER_PAYOUT', 'player', :p, 'GHS', 'credit', 'active')"
    )->execute([':c1' => "PLAYER_PLAY:$id", ':c2' => "PLAYER_PAYOUT:$id", ':p' => $id]);
    $playAccId = (int) db()->query("SELECT id FROM account WHERE accountType='PLAYER_PLAY' AND ownerId=$id")->fetchColumn();
    $payoutAccId = (int) db()->query("SELECT id FROM account WHERE accountType='PLAYER_PAYOUT' AND ownerId=$id")->fetchColumn();

    db()->prepare(
        "INSERT INTO wallet (playerId, walletType, accountId, cachedBalancePesewas) VALUES
         (:pid, 'PLAY', :a1, 0), (:pid, 'PAYOUT', :a2, 0)"
    )->execute([':pid' => $id, ':a1' => $playAccId, ':a2' => $payoutAccId]);
    return $id;
}

function setBalance(int $playerId, string $type, int $pesewas): void
{
    db()->prepare(
        "UPDATE wallet SET cachedBalancePesewas = :b WHERE playerId = :p AND walletType = :t"
    )->execute([':b' => $pesewas, ':p' => $playerId, ':t' => $type]);
}

function seedGameRound(int $playerId, int $cards, int $mult, string $picks, int $stake, string $outcome, int $payout, ?string $drawn = null): void
{
    db()->prepare(
        "INSERT INTO gameRound (refNumber, playerId, gameType, multiplier, colorPicks, stakePesewas, outcome, payoutPesewas, drawnCards)
         VALUES (:r, :p, :g, :m, :pk, :s, :o, :py, :d)"
    )->execute([':r' => 'STK-' . bin2hex(random_bytes(4)), ':p' => $playerId,
                ':g' => $cards, ':m' => $mult, ':pk' => $picks, ':s' => $stake,
                ':o' => $outcome, ':py' => $payout, ':d' => $drawn]);
}

// -----------------------------------------------------------------------------
// Test framework
// -----------------------------------------------------------------------------
class TestRunner
{
    public int $passed = 0;
    public int $failed = 0;
    public function run(string $name, callable $test): void {
        try {
            $test();
            $this->passed++;
            echo "  \033[32m✓\033[0m {$name}\n";
        } catch (Throwable $e) {
            $this->failed++;
            echo "  \033[31m✗\033[0m {$name}\n      \033[31m{$e->getMessage()}\033[0m\n";
        }
    }
    public function summary(): int {
        $total = $this->passed + $this->failed;
        echo "\n─────────────────────────────────────\n";
        echo "Tests: {$total} | \033[32mPassed: {$this->passed}\033[0m";
        if ($this->failed > 0) { echo " | \033[31mFailed: {$this->failed}\033[0m\n"; return 1; }
        echo "\n";
        return 0;
    }
}
function br_assert(bool $c, string $m): void { if (!$c) throw new AssertionError($m); }
function br_assert_equals(mixed $e, mixed $a, string $m = ''): void {
    if ($e !== $a) {
        $exp = is_scalar($e) ? var_export($e, true) : gettype($e);
        $act = is_scalar($a) ? var_export($a, true) : gettype($a);
        throw new AssertionError(($m ?: 'mismatch') . " (expected: $exp, actual: $act)");
    }
}
$r = new TestRunner();

// =============================================================================
echo "\n  MSISDN\n";
$r->run('233xxx → 233xxx',           fn() => br_assert_equals('233244000000', normalizeMsisdn('233244000000')));
$r->run('+233xxx → 233xxx',          fn() => br_assert_equals('233244000000', normalizeMsisdn('+233244000000')));
$r->run('0xxx → 233xxx',             fn() => br_assert_equals('233244000000', normalizeMsisdn('0244000000')));
$r->run('9-digit → 233xxx',          fn() => br_assert_equals('233244000000', normalizeMsisdn('244000000')));
$r->run('with spaces and dashes',    fn() => br_assert_equals('233244000000', normalizeMsisdn('233-244-000-000')));
$r->run('reject too-short',          function () {
    try { normalizeMsisdn('123'); throw new Exception('!'); } catch (InvalidArgumentException) {}
});
$r->run('reject non-Ghana prefix',   function () {
    try { normalizeMsisdn('233344000000'); throw new Exception('!'); } catch (InvalidArgumentException) {}
});
$r->run('display strips 233',        fn() => br_assert_equals('0244000000', displayMsisdn('233244000000')));

echo "\n  Response\n";
$r->run('respEnd',  function () { $r = respEnd('Bye'); br_assert_equals(false, $r['continue']); br_assert_equals('Bye', $r['message']); });
$r->run('respStay', function () { $r = respStay('Retry'); br_assert_equals(true, $r['continue']); });
$r->run('respNext', function () { $r = respNext('X'); br_assert_equals('X', $r['nextState']); });
$r->run('toNaloPayload', function () {
    $p = toNaloPayload(respEnd('Hi'), 'BlRdGame', '233244000000');
    br_assert_equals('BlRdGame', $p['USERID']);
    br_assert_equals(false, $p['MSGTYPE']);
});

echo "\n  Session\n";
$r->run('sessionCreate', function () {
    $s = sessionCreate('s-1', '233244111000', 'MTN', 'ENTRY');
    br_assert($s['id'] > 0, 'id'); br_assert_equals('ENTRY', $s['state']);
});
$r->run('sessionFindForUpdate', function () {
    sessionCreate('s-find', '233244111001', 'MTN', 'ENTRY');
    dbTxn(function () { br_assert(sessionFindForUpdate('s-find') !== null, 'found'); });
});
$r->run('sessionSave persists data', function () {
    $s = sessionCreate('s-save', '233244111002', 'MTN', 'ENTRY');
    $s['playerId'] = 99; $s['data']['key'] = 'val';
    sessionSave($s);
    dbTxn(function () {
        $l = sessionFindForUpdate('s-save');
        br_assert_equals(99, $l['playerId']);
        br_assert_equals('val', $l['data']['key']);
    });
});
$r->run('sessionTransition resets pwRetries', function () {
    $s = ['state'=>'OLD','pwRetries'=>2];
    sessionTransition($s, 'NEW');
    br_assert_equals('NEW', $s['state']); br_assert_equals(0, $s['pwRetries']);
});

echo "\n  Player\n";
$r->run('playerByMsisdn null when unregistered', function () {
    br_assert(playerByMsisdn('233244999999') === null, 'null');
});
$r->run('playerByMsisdn returns row', function () {
    seedPlayer('233244200000', 'KWAME MENSAH');
    $p = playerByMsisdn('233244200000');
    br_assert($p !== null, 'found'); br_assert_equals('KWAME MENSAH', $p['registeredName']);
});
$r->run('playerFirstName title-cases CAPS', function () {
    br_assert_equals('Akosua', playerFirstName(['registeredName'=>'AKOSUA BOATENG','displayName'=>null]));
});
$r->run('playerFirstName prefers displayName', function () {
    br_assert_equals('Kosi', playerFirstName(['registeredName'=>'AKOSUA BOATENG','displayName'=>'Kosi']));
});
$r->run('playerFirstName fallback empty', function () {
    br_assert_equals('there', playerFirstName(['registeredName'=>'','displayName'=>null]));
});
$r->run('playerBalances reads both wallets', function () {
    $pid = seedPlayer('233244210000', 'TEST');
    setBalance($pid, 'PLAY', 12345);
    setBalance($pid, 'PAYOUT', 9999);
    $bal = playerBalances($pid);
    br_assert_equals(12345, $bal['play']);
    br_assert_equals(9999, $bal['payout']);
});
$r->run('playerBalances throws when missing', function () {
    try { playerBalances(999999); throw new Exception('!'); } catch (RuntimeException) {}
});
$r->run('formatPesewas', function () {
    br_assert_equals('GHS 0.00', formatPesewas(0));
    br_assert_equals('GHS 1.00', formatPesewas(100));
    br_assert_equals('GHS 123.45', formatPesewas(12345));
    br_assert_equals('GHS 0.05', formatPesewas(5));
});
$r->run('playerLastStake null when no plays', function () {
    $pid = seedPlayer('233244220000', 'NO PLAYS');
    br_assert(playerLastStake($pid) === null, 'null');
});
$r->run('playerLastStake returns most recent', function () {
    $pid = seedPlayer('233244221000', 'TWO PLAYS');
    seedGameRound($pid, 2, 10, 'BR', 1000, 'loss', 0);
    seedGameRound($pid, 3, 20, 'BRB', 2000, 'win', 40000, '["7H","2C","KS"]');
    $stake = playerLastStake($pid);
    br_assert_equals(3, $stake['gameType']);
    br_assert_equals('win', $stake['outcome']);
});

echo "\n  EntryState\n";
$r->run('unregistered → UNREGISTERED_MENU', function () {
    $s = sessionCreate('s-e1', '233244300000', 'MTN', 'ENTRY');
    $resp = renderState('ENTRY', $s);
    br_assert_equals('UNREGISTERED_MENU', $resp['nextState']);
});
$r->run('registered active → MAIN_MENU + attach playerId', function () {
    seedPlayer('233244300001', 'KOFI ANIPA');
    $s = sessionCreate('s-e2', '233244300001', 'MTN', 'ENTRY');
    $resp = renderState('ENTRY', $s);
    br_assert_equals('MAIN_MENU', $resp['nextState']);
    br_assert($s['playerId'] !== null, 'playerId attached');
    br_assert_equals('Kofi', $s['data']['firstName']);
});
$r->run('frozen account ENDs', function () {
    seedPlayer('233244300002', 'FROZEN U', 'frozen');
    $s = sessionCreate('s-e3', '233244300002', 'MTN', 'ENTRY');
    $resp = renderState('ENTRY', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'frozen'), 'mentions frozen');
});

echo "\n  UnregisteredMenu\n";
$r->run('renders menu with msisdn', function () {
    $s = sessionCreate('s-u1', '233244400000', 'MTN', 'UNREGISTERED_MENU');
    $resp = renderState('UNREGISTERED_MENU', $s);
    br_assert(str_contains($resp['message'], '1. Register'), 'option 1');
    br_assert(str_contains($resp['message'], '0244400000'), 'shows display msisdn');
});
$r->run('input 1 with MTN network → ANM lookup → REG_CONFIRM_NAME', function () {
    $GLOBALS['testAnmStub'] = ['name' => 'KOFI MENSAH', 'shouldThrow' => false, 'errorMsg' => ''];
    $s = sessionCreate('s-u1a', '233244400001', 'MTN', 'UNREGISTERED_MENU');
    $resp = handle_UNREGISTERED_MENU('1', $s);
    br_assert_equals('REG_CONFIRM_NAME', $resp['nextState']);
    br_assert_equals('MTN', $s['data']['regBankCode']);
    br_assert_equals('KOFI MENSAH', $s['data']['regName']);
});
$r->run('input 1 with Telecel network → bank_code VOD', function () {
    $GLOBALS['testAnmStub'] = ['name' => 'AMA T', 'shouldThrow' => false, 'errorMsg' => ''];
    $s = sessionCreate('s-u1b', '233244400002', 'TELECEL', 'UNREGISTERED_MENU');
    $resp = handle_UNREGISTERED_MENU('1', $s);
    br_assert_equals('REG_CONFIRM_NAME', $resp['nextState']);
    br_assert_equals('VOD', $s['data']['regBankCode']);
});
$r->run('input 1 with unknown network → falls back to REG_PICK_PROVIDER', function () {
    $s = sessionCreate('s-u1c', '233244400003', 'GLO', 'UNREGISTERED_MENU');
    $resp = handle_UNREGISTERED_MENU('1', $s);
    br_assert_equals('REG_PICK_PROVIDER', $resp['nextState']);
});
$r->run('input 1 with ANM failure → END with error', function () {
    $GLOBALS['testAnmStub'] = ['name' => '', 'shouldThrow' => true, 'errorMsg' => 'Number not on MTN.'];
    $s = sessionCreate('s-u1d', '233244400004', 'MTN', 'UNREGISTERED_MENU');
    $resp = handle_UNREGISTERED_MENU('1', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Number not on MTN'), 'shows ANM message');
});
$r->run('input 2 → exit', function () {
    $s = ['state'=>'UNREGISTERED_MENU','msisdn'=>'233244400002','data'=>[]];
    $resp = handle_UNREGISTERED_MENU('2', $s);
    br_assert_equals(false, $resp['continue']);
});
$r->run('input 9 → retry', function () {
    $s = ['state'=>'UNREGISTERED_MENU','msisdn'=>'233244400003','data'=>[]];
    $resp = handle_UNREGISTERED_MENU('9', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Invalid'), 'invalid');
});

echo "\n  MainMenu\n";
$r->run('renders 5 options with name', function () {
    $s = ['state'=>'MAIN_MENU','msisdn'=>'233244500000','playerId'=>1,'data'=>['firstName'=>'Yaw']];
    $resp = renderState('MAIN_MENU', $s);
    br_assert(str_contains($resp['message'], 'Hello Yaw'), 'greets');
    foreach (['1. Play Now','2. Deposit','3. Withdraw','4. Check Balance','5. Check Last Stake'] as $o) {
        br_assert(str_contains($resp['message'], $o), "has $o");
    }
});
$r->run('input 4 → BALANCE', function () {
    $s = []; $resp = handle_MAIN_MENU('4', $s);
    br_assert_equals('BALANCE', $resp['nextState']);
});
$r->run('input 5 → LAST_STAKE', function () {
    $s = []; $resp = handle_MAIN_MENU('5', $s);
    br_assert_equals('LAST_STAKE', $resp['nextState']);
});
$r->run('input 1 → PLAY_PICK_TYPE', function () {
    $s = []; $resp = handle_MAIN_MENU('1', $s);
    br_assert_equals('PLAY_PICK_TYPE', $resp['nextState']);
});
$r->run('input 2 → DEP_ENTER_AMOUNT', function () {
    $s = []; $resp = handle_MAIN_MENU('2', $s);
    br_assert_equals('DEP_ENTER_AMOUNT', $resp['nextState']);
});
$r->run('input 3 → WD_PICK_DESTINATION', function () {
    $s = []; $resp = handle_MAIN_MENU('3', $s);
    br_assert_equals('WD_PICK_DESTINATION', $resp['nextState']);
});
$r->run('invalid input → retry', function () {
    $s = []; $resp = handle_MAIN_MENU('9', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Invalid'), 'invalid');
});

echo "\n  BalanceState\n";
$r->run('shows both balances', function () {
    $pid = seedPlayer('233244600000', 'KOFI BAL');
    setBalance($pid, 'PLAY', 5000);
    setBalance($pid, 'PAYOUT', 12340);
    $s = ['msisdn'=>'233244600000','playerId'=>$pid,'data'=>['firstName'=>'Kofi']];
    $resp = renderState('BALANCE', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'GHS 50.00'), 'play balance');
    br_assert(str_contains($resp['message'], 'GHS 123.40'), 'payout balance');
    br_assert(str_contains($resp['message'], 'Hello Kofi'), 'greets');
});
$r->run('handles missing playerId', function () {
    $s = ['msisdn'=>'233244600001','playerId'=>null,'data'=>[]];
    $resp = renderState('BALANCE', $s);
    br_assert_equals(false, $resp['continue']);
});

echo "\n  LastStakeState\n";
$r->run('shows last stake', function () {
    $pid = seedPlayer('233244700000', 'AMA');
    // Web app stores colorPicks as comma-separated full words
    seedGameRound($pid, 3, 20, 'black,red,black', 1000, 'win', 20000, '["2H","3D","4S"]');
    $s = ['msisdn'=>'233244700000','playerId'=>$pid,'data'=>[]];
    $resp = renderState('LAST_STAKE', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Win'), 'outcome');
    br_assert(str_contains($resp['message'], '3 Cards(x20)'), 'game type');
    br_assert(str_contains($resp['message'], 'GHS 10.00'), 'stake');
    br_assert(str_contains($resp['message'], 'Your Selection: BRB'), 'picks compressed to letters');
    br_assert(str_contains($resp['message'], 'The Result: RRB'), 'result');
    br_assert(strlen($resp['message']) <= 146, 'fits 146 chars (got ' . strlen($resp['message']) . ')');
});
$r->run('shows "not played yet"', function () {
    $pid = seedPlayer('233244700001', 'NEW USER');
    $s = ['msisdn'=>'233244700001','playerId'=>$pid,'data'=>[]];
    $resp = renderState('LAST_STAKE', $s);
    br_assert(str_contains($resp['message'], 'not played yet'), 'message');
});
$r->run('drawnCardsToLetters H/D=R, C/S=B', function () {
    br_assert_equals('RRBB', drawnCardsToLetters('["2H","KD","3C","4S"]'));
    br_assert_equals('(unavailable)', drawnCardsToLetters(null));
});
$r->run('colorPicksToLetters converts web app format', function () {
    br_assert_equals('RBR', colorPicksToLetters('red,black,red'));
    br_assert_equals('BBRBR', colorPicksToLetters('black,black,red,black,red'));
    // Tolerant of casing and whitespace
    br_assert_equals('RBR', colorPicksToLetters('Red, Black, Red'));
    br_assert_equals('RBR', colorPicksToLetters(' R , B , R '));
    // Idempotent on already-compressed input
    br_assert_equals('RBR', colorPicksToLetters('RBR'));
    // Edge cases
    br_assert_equals('(unavailable)', colorPicksToLetters(''));
    br_assert_equals('(unavailable)', colorPicksToLetters(null));
    br_assert_equals('(unavailable)', colorPicksToLetters('  '));
});
$r->run('worst case (5 cards x100 GHS 2000) fits 146 chars', function () {
    $pid = seedPlayer('233244700099', 'WORST');
    seedGameRound($pid, 5, 100, 'black,black,red,black,red', 200000, 'loss', 0, '["2H","3D","4S","5C","6S"]');
    $s = ['msisdn'=>'233244700099','playerId'=>$pid,'data'=>[]];
    $resp = renderState('LAST_STAKE', $s);
    br_assert(strlen($resp['message']) <= 146, 'must fit 146 chars (got ' . strlen($resp['message']) . ')');
    br_assert(str_contains($resp['message'], '5 Cards(x100)'), 'shows 5-card label');
    br_assert(str_contains($resp['message'], 'GHS 2000.00'), 'shows max stake');
    br_assert(str_contains($resp['message'], 'Your Selection: BBRBR'), 'compressed picks');
});
$r->run('formatGameType', function () {
    br_assert_equals('1 Card(x2)', formatGameType(1, 2));
    br_assert_equals('5 Cards(x100)', formatGameType(5, 100));
});

echo "\n  End-to-end\n";
$r->run('registered user: ENTRY → MAIN_MENU → BALANCE', function () {
    $pid = seedPlayer('233244800000', 'E2E');
    setBalance($pid, 'PLAY', 7700);
    setBalance($pid, 'PAYOUT', 2200);

    $session = sessionCreate('e2e-1', '233244800000', 'MTN', 'ENTRY');
    $r1 = renderState('ENTRY', $session);
    br_assert_equals('MAIN_MENU', $r1['nextState'], 'routes to main');
    sessionTransition($session, 'MAIN_MENU');
    $r2 = renderState('MAIN_MENU', $session);
    br_assert(str_contains($r2['message'], '1. Play Now'), 'menu rendered');
    sessionSave($session);

    $r3 = handle_MAIN_MENU('4', $session);
    br_assert_equals('BALANCE', $r3['nextState']);
    sessionTransition($session, 'BALANCE');
    $r4 = renderState('BALANCE', $session);
    br_assert_equals(false, $r4['continue']);
    br_assert(str_contains($r4['message'], 'GHS 77.00'), 'play');
    br_assert(str_contains($r4['message'], 'GHS 22.00'), 'payout');
});
$r->run('unregistered user: ENTRY → UNREGISTERED_MENU', function () {
    $session = sessionCreate('e2e-2', '233244800099', 'MTN', 'ENTRY');
    $r1 = renderState('ENTRY', $session);
    br_assert_equals('UNREGISTERED_MENU', $r1['nextState']);
    sessionTransition($session, 'UNREGISTERED_MENU');
    $r2 = renderState('UNREGISTERED_MENU', $session);
    br_assert(str_contains($r2['message'], '1. Register'), 'menu rendered');
});

// =============================================================================
// PR 3: Password validation
// =============================================================================
echo "\n  Password\n";
$r->run('rejects too short',     fn() => br_assert(validatePassword('Ab1!') !== null, 'short'));
$r->run('rejects too long',      fn() => br_assert(validatePassword(str_repeat('A1!a', 30)) !== null, 'long'));
$r->run('rejects no uppercase',  fn() => br_assert(str_contains(validatePassword('kofi2024!'), 'capital'), 'missing capital'));
$r->run('rejects no digit',      fn() => br_assert(str_contains(validatePassword('Kofiana!'), 'number'), 'missing number'));
$r->run('rejects no symbol',     fn() => br_assert(str_contains(validatePassword('Kofi2024'), 'symbol'), 'missing symbol'));
$r->run('accepts valid',         fn() => br_assert(validatePassword('Kofi2024!') === null, 'valid'));
$r->run('accepts long with all', fn() => br_assert(validatePassword('Akosua_Loves_PHP_123!') === null, 'valid long'));
$r->run('reports multiple missing parts', function () {
    $err = validatePassword('kofianaplus');  // 11 chars, missing capital+digit+symbol
    br_assert(str_contains($err, 'capital'), 'capital');
    br_assert(str_contains($err, 'number'), 'number');
    br_assert(str_contains($err, 'symbol'), 'symbol');
});

echo "\n  Password hashing\n";
$r->run('hash and verify roundtrip', function () {
    $h = hashPassword('Kofi2024!');
    br_assert(verifyPassword('Kofi2024!', $h), 'correct password verifies');
    br_assert(!verifyPassword('Wrong2024!', $h), 'wrong password rejected');
});
$r->run('verify rejects empty', function () {
    br_assert(!verifyPassword('', 'somehash'), 'empty input');
    br_assert(!verifyPassword('Pass1!', ''), 'empty hash');
});
$r->run('hash uses argon2id', function () {
    $h = hashPassword('Test1234!');
    br_assert(str_starts_with($h, '$argon2id$'), 'argon2id prefix');
});

// =============================================================================
// PR 3: ANM network/bankcode/provider mapping
// =============================================================================
echo "\n  ANM mappings\n";
$r->run('Nalo MTN → bank_code MTN', fn() => br_assert_equals('MTN', anmNetworkToBankCode('MTN')));
$r->run('Nalo TELECEL → bank_code VOD', fn() => br_assert_equals('VOD', anmNetworkToBankCode('TELECEL')));
$r->run('Nalo AIRTELTIGO → bank_code AIR', fn() => br_assert_equals('AIR', anmNetworkToBankCode('AIRTELTIGO')));
$r->run('Nalo VODAFONE → bank_code VOD', fn() => br_assert_equals('VOD', anmNetworkToBankCode('VODAFONE')));
$r->run('Nalo lowercase mtn → MTN', fn() => br_assert_equals('MTN', anmNetworkToBankCode('mtn')));
$r->run('Nalo GLO (unknown) → null', fn() => br_assert(anmNetworkToBankCode('GLO') === null, 'null'));
$r->run('Nalo empty → null', fn() => br_assert(anmNetworkToBankCode('') === null, 'null'));

$r->run('bank_code MTN → provider MTN', fn() => br_assert_equals('MTN', anmBankCodeToProvider('MTN')));
$r->run('bank_code VOD → provider TEL', fn() => br_assert_equals('TEL', anmBankCodeToProvider('VOD')));
$r->run('bank_code AIR → provider ATL', fn() => br_assert_equals('ATL', anmBankCodeToProvider('AIR')));
$r->run('bank_code unknown → throws', function () {
    try { anmBankCodeToProvider('GCB'); throw new Exception('!'); }
    catch (InvalidArgumentException) {}
});

// =============================================================================
// PR 3: playerCreate
// =============================================================================
echo "\n  Player creation\n";
$r->run('creates player + accounts + wallets atomically', function () {
    $hash = hashPassword('Pass1234!');
    $pid = playerCreate('233244900000', 'MTN', 'KOFI MENSAH', $hash);

    br_assert($pid > 0, 'player id returned');

    // Verify player
    $p = playerByMsisdn('233244900000');
    br_assert($p !== null, 'player exists');
    br_assert_equals('KOFI MENSAH', $p['registeredName']);
    br_assert_equals('MTN', $p['paymentProvider']);
    br_assert_equals('active', $p['accountStatus']);
    br_assert(verifyPassword('Pass1234!', $p['passwordHash']), 'password hash verifies');

    // Verify accounts
    $accs = db()->query("SELECT accountType, normalBalance FROM account WHERE ownerId = $pid ORDER BY accountType")->fetchAll();
    br_assert_equals(2, count($accs), 'two accounts');
    br_assert_equals('PLAYER_PAYOUT', $accs[0]['accountType']);
    br_assert_equals('PLAYER_PLAY', $accs[1]['accountType']);
    br_assert_equals('credit', $accs[0]['normalBalance']);

    // Verify wallets
    $bal = playerBalances($pid);
    br_assert_equals(0, $bal['play']);
    br_assert_equals(0, $bal['payout']);
});
$r->run('duplicate msisdn raises constraint violation', function () {
    $hash = hashPassword('Pass1234!');
    playerCreate('233244900001', 'MTN', 'FIRST USER', $hash);
    try {
        playerCreate('233244900001', 'MTN', 'SECOND USER', $hash);
        throw new Exception('should have failed');
    } catch (PDOException $e) {
        // SQLite uses '23000' for UNIQUE violations; same as MariaDB
        br_assert($e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE'),
            'expected uniqueness violation, got: ' . $e->getCode() . ' / ' . $e->getMessage());
    }
});
$r->run('registrationChannel is ussd', function () {
    $hash = hashPassword('Pass1234!');
    $pid = playerCreate('233244900002', 'TEL', 'TELECEL USER', $hash);
    $stmt = db()->prepare("SELECT registrationChannel FROM player WHERE id = :id");
    $stmt->execute([':id' => $pid]);
    br_assert_equals('ussd', $stmt->fetchColumn());
});

// =============================================================================
// PR 3: RegPickProviderState (fallback when Nalo's network is unmapped)
// =============================================================================
echo "\n  RegPickProvider\n";
$r->run('renders 3 provider options', function () {
    $s = sessionCreate('s-rp1', '233244910000', 'MTN', 'REG_PICK_PROVIDER');
    $resp = renderState('REG_PICK_PROVIDER', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], '1. MTN'), 'MTN');
    br_assert(str_contains($resp['message'], '2. Telecel'), 'Telecel');
    br_assert(str_contains($resp['message'], '3. AirtelTigo'), 'AirtelTigo');
});
$r->run('input 1 → bank_code MTN → REG_CONFIRM_NAME', function () {
    $GLOBALS['testAnmStub'] = ['name' => 'KOFI', 'shouldThrow' => false, 'errorMsg' => ''];
    $s = sessionCreate('s-rp2', '233244910001', 'MTN', 'REG_PICK_PROVIDER');
    $resp = handle_REG_PICK_PROVIDER('1', $s);
    br_assert_equals('REG_CONFIRM_NAME', $resp['nextState']);
    br_assert_equals('MTN', $s['data']['regBankCode']);
    br_assert_equals('KOFI', $s['data']['regName']);
});
$r->run('input 2 → bank_code VOD', function () {
    $GLOBALS['testAnmStub'] = ['name' => 'AMA TELECEL', 'shouldThrow' => false, 'errorMsg' => ''];
    $s = sessionCreate('s-rp3', '233244910002', 'MTN', 'REG_PICK_PROVIDER');
    $resp = handle_REG_PICK_PROVIDER('2', $s);
    br_assert_equals('VOD', $s['data']['regBankCode']);
});
$r->run('input 3 → bank_code AIR', function () {
    $GLOBALS['testAnmStub'] = ['name' => 'KWAME AIR', 'shouldThrow' => false, 'errorMsg' => ''];
    $s = sessionCreate('s-rp4', '233244910003', 'MTN', 'REG_PICK_PROVIDER');
    $resp = handle_REG_PICK_PROVIDER('3', $s);
    br_assert_equals('AIR', $s['data']['regBankCode']);
});
$r->run('ANM failure → END with error message', function () {
    $GLOBALS['testAnmStub'] = ['name' => '', 'shouldThrow' => true, 'errorMsg' => 'Not registered on MTN.'];
    $s = sessionCreate('s-rp5', '233244910004', 'MTN', 'REG_PICK_PROVIDER');
    $resp = handle_REG_PICK_PROVIDER('1', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Not registered'), 'shows error');
});
$r->run('invalid input → re-render', function () {
    $s = sessionCreate('s-rp6', '233244910005', 'MTN', 'REG_PICK_PROVIDER');
    $resp = handle_REG_PICK_PROVIDER('9', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Invalid'), 'invalid');
});

// =============================================================================
// PR 3: RegConfirmNameState
// =============================================================================
echo "\n  RegConfirmName\n";
$r->run('renders the looked-up name', function () {
    $s = ['msisdn'=>'233244920000', 'data'=>['regName'=>'AKOSUA BOATENG', 'regBankCode'=>'MTN']];
    $resp = renderState('REG_CONFIRM_NAME', $s);
    br_assert(str_contains($resp['message'], 'AKOSUA BOATENG'), 'shows name');
    br_assert(str_contains($resp['message'], '1. Yes'), 'yes');
    br_assert(str_contains($resp['message'], '2. No'), 'no');
});
$r->run('input 1 → REG_SET_PASSWORD', function () {
    $s = ['data'=>['regName'=>'X', 'regBankCode'=>'MTN']];
    $resp = handle_REG_CONFIRM_NAME('1', $s);
    br_assert_equals('REG_SET_PASSWORD', $resp['nextState']);
});
$r->run('input 2 → cancel/END', function () {
    $s = ['data'=>['regName'=>'X', 'regBankCode'=>'MTN']];
    $resp = handle_REG_CONFIRM_NAME('2', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'cancelled'), 'cancelled');
});

// =============================================================================
// PR 3: RegSetPasswordState
// =============================================================================
echo "\n  RegSetPassword\n";
$r->run('renders password prompt', function () {
    $s = ['data'=>['regName'=>'X', 'regBankCode'=>'MTN'], 'msisdn'=>'233244930000'];
    $resp = renderState('REG_SET_PASSWORD', $s);
    br_assert(str_contains($resp['message'], 'Password'), 'password word');
    br_assert(str_contains($resp['message'], '8+'), 'length hint');
});
$r->run('invalid password → re-render with error', function () {
    $s = ['data'=>['regName'=>'X', 'regBankCode'=>'MTN'], 'msisdn'=>'233244930001'];
    $resp = handle_REG_SET_PASSWORD('kofi', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'too short'), 'short msg');
});
$r->run('valid password → stashes hash and routes to REG_CONFIRM_PASSWORD', function () {
    $s = ['data'=>['regName'=>'YAW DUKU', 'regBankCode'=>'MTN'], 'msisdn'=>'233244930002', 'playerId'=>null];
    $resp = handle_REG_SET_PASSWORD('Kofi2024!', $s);
    br_assert_equals('REG_CONFIRM_PASSWORD', $resp['nextState']);
    br_assert(!empty($s['data']['regPwHash']), 'hash stashed in session');
    br_assert(str_starts_with($s['data']['regPwHash'], '$argon2id$'), 'hash is argon2id');
    // Player must NOT yet exist
    br_assert(playerByMsisdn('233244930002') === null, 'player not yet created');
});

// =============================================================================
// PR 3: RegConfirmPasswordState
// =============================================================================
echo "\n  RegConfirmPassword\n";
$r->run('renders confirmation prompt', function () {
    $s = ['data'=>['regName'=>'X', 'regBankCode'=>'MTN', 'regPwHash'=>hashPassword('Test1234!')], 'msisdn'=>'233244931000'];
    $resp = renderState('REG_CONFIRM_PASSWORD', $s);
    br_assert(str_contains($resp['message'], 'Confirm'), 'confirm word');
});
$r->run('matching password → creates player, sends welcome SMS, ENDs', function () {
    $GLOBALS['testSmsLog'] = [];
    $hash = hashPassword('Kofi2024!');
    $s = ['data'=>['regName'=>'YAW DUKU', 'regBankCode'=>'MTN', 'regPwHash'=>$hash], 'msisdn'=>'233244931001', 'playerId'=>null];
    $resp = handle_REG_CONFIRM_PASSWORD('Kofi2024!', $s);

    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Welcome Yaw'), 'first name capitalised');
    br_assert(str_contains($resp['message'], 'Registration complete'), 'success msg');

    // Player created
    $p = playerByMsisdn('233244931001');
    br_assert($p !== null, 'player exists');
    br_assert_equals('YAW DUKU', $p['registeredName']);

    // SMS was sent with the right content
    br_assert_equals(1, count($GLOBALS['testSmsLog']), 'one SMS sent');
    $sms = $GLOBALS['testSmsLog'][0];
    br_assert_equals('233244931001', $sms['to']);
    br_assert(str_contains($sms['content'], 'Hello Yaw'), 'SMS greets by name');
    br_assert(str_contains($sms['content'], 'Welcome to BlackRed'), 'SMS welcome line');
    br_assert(str_contains($sms['content'], 'registered successfully'), 'SMS confirms registration');

    // Sensitive data cleared
    br_assert(!isset($s['data']['regName']), 'regName cleared');
    br_assert(!isset($s['data']['regBankCode']), 'regBankCode cleared');
    br_assert(!isset($s['data']['regPwHash']), 'regPwHash cleared');
});
$r->run('mismatched password → ENDs with mismatch message, no player created', function () {
    $GLOBALS['testSmsLog'] = [];
    $hash = hashPassword('Kofi2024!');
    $s = ['data'=>['regName'=>'X', 'regBankCode'=>'MTN', 'regPwHash'=>$hash], 'msisdn'=>'233244931002', 'playerId'=>null];
    $resp = handle_REG_CONFIRM_PASSWORD('WrongPass1!', $s);

    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'did not match'), 'mismatch msg');

    // Player NOT created
    br_assert(playerByMsisdn('233244931002') === null, 'no player');
    // No SMS sent
    br_assert_equals(0, count($GLOBALS['testSmsLog']), 'no SMS sent');
    // Hash cleared so retry starts clean
    br_assert(!isset($s['data']['regPwHash']), 'hash cleared');
});
$r->run('missing hash → END session error', function () {
    $s = ['data'=>['regName'=>'X', 'regBankCode'=>'MTN'], 'msisdn'=>'233244931003'];
    $resp = handle_REG_CONFIRM_PASSWORD('Kofi2024!', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Session error'), 'session error');
});
$r->run('duplicate msisdn → ENDs with "already registered"', function () {
    $hash = hashPassword('Kofi2024!');
    // First registration
    $s1 = ['data'=>['regName'=>'FIRST', 'regBankCode'=>'MTN', 'regPwHash'=>$hash], 'msisdn'=>'233244931004', 'playerId'=>null];
    handle_REG_CONFIRM_PASSWORD('Kofi2024!', $s1);

    // Second with same MSISDN
    $s2 = ['data'=>['regName'=>'SECOND', 'regBankCode'=>'MTN', 'regPwHash'=>$hash], 'msisdn'=>'233244931004', 'playerId'=>null];
    $resp = handle_REG_CONFIRM_PASSWORD('Kofi2024!', $s2);

    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'already registered'), 'already registered');
});
$r->run('SMS failure does NOT block registration', function () {
    $GLOBALS['testSmsShouldFail'] = true;
    $GLOBALS['testSmsLog'] = [];

    $hash = hashPassword('Kofi2024!');
    $s = ['data'=>['regName'=>'AMA NSI', 'regBankCode'=>'MTN', 'regPwHash'=>$hash], 'msisdn'=>'233244931005', 'playerId'=>null];
    $resp = handle_REG_CONFIRM_PASSWORD('Kofi2024!', $s);

    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Welcome Ama'), 'still greets user');

    // Player WAS created despite SMS failure
    $p = playerByMsisdn('233244931005');
    br_assert($p !== null, 'player created despite SMS failure');

    $GLOBALS['testSmsShouldFail'] = false;  // reset for other tests
});

// =============================================================================
// PR 3: End-to-end registration flow
// =============================================================================
echo "\n  E2E registration\n";
$r->run('full flow: unregistered → menu → auto-detect MTN → confirm → password → confirm pw → registered', function () {
    $GLOBALS['testAnmStub'] = ['name' => 'KWAME DARKO', 'shouldThrow' => false, 'errorMsg' => ''];
    $GLOBALS['testSmsLog'] = [];

    // Turn 1: dial *920*9 (handled by index.php; we simulate)
    $session = sessionCreate('e2e-reg-1', '233244940000', 'MTN', 'ENTRY');
    $r1 = renderState('ENTRY', $session);
    br_assert_equals('UNREGISTERED_MENU', $r1['nextState']);
    sessionTransition($session, 'UNREGISTERED_MENU');
    $r2 = renderState('UNREGISTERED_MENU', $session);
    br_assert(str_contains($r2['message'], '1. Register'), '1. Register shown');

    // Turn 2: input '1' → auto-detected MTN → ANM lookup → REG_CONFIRM_NAME
    $r3 = handle_UNREGISTERED_MENU('1', $session);
    br_assert_equals('REG_CONFIRM_NAME', $r3['nextState'], 'auto-routes past provider picker');
    br_assert_equals('MTN', $session['data']['regBankCode']);
    br_assert_equals('KWAME DARKO', $session['data']['regName']);
    sessionTransition($session, 'REG_CONFIRM_NAME');
    $r4 = renderState('REG_CONFIRM_NAME', $session);
    br_assert(str_contains($r4['message'], 'KWAME DARKO'), 'name shown');

    // Turn 3: input '1' (Yes) → REG_SET_PASSWORD
    $r5 = handle_REG_CONFIRM_NAME('1', $session);
    br_assert_equals('REG_SET_PASSWORD', $r5['nextState']);
    sessionTransition($session, 'REG_SET_PASSWORD');
    renderState('REG_SET_PASSWORD', $session);

    // Turn 4: enter password → REG_CONFIRM_PASSWORD
    $r6 = handle_REG_SET_PASSWORD('MyPass99!', $session);
    br_assert_equals('REG_CONFIRM_PASSWORD', $r6['nextState']);
    br_assert(!empty($session['data']['regPwHash']), 'hash stashed');
    sessionTransition($session, 'REG_CONFIRM_PASSWORD');
    renderState('REG_CONFIRM_PASSWORD', $session);

    // Turn 5: confirm same password → player created, SMS sent, END
    $r7 = handle_REG_CONFIRM_PASSWORD('MyPass99!', $session);
    br_assert_equals(false, $r7['continue']);
    br_assert(str_contains($r7['message'], 'Welcome Kwame'), 'greets');

    // Verify in DB
    $p = playerByMsisdn('233244940000');
    br_assert($p !== null, 'player created');
    br_assert_equals('KWAME DARKO', $p['registeredName']);
    br_assert_equals('MTN', $p['paymentProvider']);

    // Verify wallets
    $bal = playerBalances($p['id']);
    br_assert_equals(0, $bal['play']);
    br_assert_equals(0, $bal['payout']);

    // Verify SMS sent
    br_assert_equals(1, count($GLOBALS['testSmsLog']), 'one SMS sent');
    br_assert(str_contains($GLOBALS['testSmsLog'][0]['content'], 'Hello Kwame'), 'SMS greets');

    // Verify the user can now log in
    $session2 = sessionCreate('e2e-reg-2', '233244940000', 'MTN', 'ENTRY');
    $r8 = renderState('ENTRY', $session2);
    br_assert_equals('MAIN_MENU', $r8['nextState'], 'newly registered user routes to main menu');
});

$r->run('mismatched password breaks the flow cleanly (no player, no SMS)', function () {
    $GLOBALS['testAnmStub'] = ['name' => 'AMA MISMATCH', 'shouldThrow' => false, 'errorMsg' => ''];
    $GLOBALS['testSmsLog'] = [];

    $session = sessionCreate('e2e-mm-1', '233244940500', 'MTN', 'ENTRY');
    renderState('ENTRY', $session);
    sessionTransition($session, 'UNREGISTERED_MENU');
    handle_UNREGISTERED_MENU('1', $session);
    sessionTransition($session, 'REG_CONFIRM_NAME');
    handle_REG_CONFIRM_NAME('1', $session);
    sessionTransition($session, 'REG_SET_PASSWORD');
    handle_REG_SET_PASSWORD('FirstPass1!', $session);
    sessionTransition($session, 'REG_CONFIRM_PASSWORD');

    $resp = handle_REG_CONFIRM_PASSWORD('DifferentPass2@', $session);
    br_assert_equals(false, $resp['continue'], 'ENDs');
    br_assert(str_contains($resp['message'], 'did not match'), 'mismatch msg');

    br_assert(playerByMsisdn('233244940500') === null, 'no player created');
    br_assert_equals(0, count($GLOBALS['testSmsLog']), 'no SMS sent');
});

$r->run('fallback flow: unknown network → REG_PICK_PROVIDER → confirm → password', function () {
    $GLOBALS['testAnmStub'] = ['name' => 'GLO USER', 'shouldThrow' => false, 'errorMsg' => ''];
    // Network "GLO" isn't mapped → should fall back to provider picker
    $session = sessionCreate('e2e-reg-fb-1', '233244940001', 'GLO', 'ENTRY');
    renderState('ENTRY', $session);
    sessionTransition($session, 'UNREGISTERED_MENU');

    $r1 = handle_UNREGISTERED_MENU('1', $session);
    br_assert_equals('REG_PICK_PROVIDER', $r1['nextState'], 'fallback to picker');
    sessionTransition($session, 'REG_PICK_PROVIDER');

    $r2 = handle_REG_PICK_PROVIDER('1', $session);  // pick MTN
    br_assert_equals('REG_CONFIRM_NAME', $r2['nextState']);
    br_assert_equals('MTN', $session['data']['regBankCode']);
});

// Drop the old "input 1 → register stub" test since it's been replaced above

// =============================================================================
// PR 5: Deposits
// =============================================================================

echo "\n  DepEnterAmount\n";
$r->run('renders min/max', function () {
    $s = ['data'=>[], 'msisdn'=>'233244000001', 'playerId'=>1];
    $resp = renderState('DEP_ENTER_AMOUNT', $s);
    br_assert(str_contains($resp['message'], 'Min: GHS 2.00'), 'min');
    br_assert(str_contains($resp['message'], 'Max: GHS 5000.00'), 'max');
});
$r->run('accepts plain integer "20" → 2000 pesewas', function () {
    $s = ['data'=>[], 'msisdn'=>'233244000001', 'playerId'=>1];
    $resp = handle_DEP_ENTER_AMOUNT('20', $s);
    br_assert_equals('DEP_CONFIRM', $resp['nextState']);
    br_assert_equals(2000, $s['data']['depAmountPesewas']);
});
$r->run('accepts decimal "20.50" → 2050 pesewas', function () {
    $s = ['data'=>[], 'msisdn'=>'233244000001', 'playerId'=>1];
    $resp = handle_DEP_ENTER_AMOUNT('20.50', $s);
    br_assert_equals(2050, $s['data']['depAmountPesewas']);
});
$r->run('accepts shorthand decimal "20.5" → 2050 pesewas', function () {
    $s = ['data'=>[], 'msisdn'=>'233244000001', 'playerId'=>1];
    $resp = handle_DEP_ENTER_AMOUNT('20.5', $s);
    br_assert_equals(2050, $s['data']['depAmountPesewas']);
});
$r->run('rejects non-numeric "abc"', function () {
    $s = ['data'=>[], 'msisdn'=>'233244000001', 'playerId'=>1];
    $resp = handle_DEP_ENTER_AMOUNT('abc', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Invalid'), 'invalid');
});
$r->run('rejects too low "1" (below GHS 2)', function () {
    $s = ['data'=>[], 'msisdn'=>'233244000001', 'playerId'=>1];
    $resp = handle_DEP_ENTER_AMOUNT('1', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Too low'), 'too low');
});
$r->run('rejects too high "10000" (above GHS 5000)', function () {
    $s = ['data'=>[], 'msisdn'=>'233244000001', 'playerId'=>1];
    $resp = handle_DEP_ENTER_AMOUNT('10000', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Too high'), 'too high');
});
$r->run('accepts boundary GHS 2.00 exactly', function () {
    $s = ['data'=>[], 'msisdn'=>'233244000001', 'playerId'=>1];
    $resp = handle_DEP_ENTER_AMOUNT('2', $s);
    br_assert_equals('DEP_CONFIRM', $resp['nextState']);
    br_assert_equals(200, $s['data']['depAmountPesewas']);
});
$r->run('accepts boundary GHS 5000.00 exactly', function () {
    $s = ['data'=>[], 'msisdn'=>'233244000001', 'playerId'=>1];
    $resp = handle_DEP_ENTER_AMOUNT('5000', $s);
    br_assert_equals(500000, $s['data']['depAmountPesewas']);
});

echo "\n  DepConfirm render\n";
$r->run('renders amount + provider + balance', function () {
    $pid = seedPlayer('233244001001', 'TEST USER');
    setBalance($pid, 'PLAY', 3500);
    $s = ['msisdn'=>'233244001001', 'playerId'=>$pid, 'data'=>['depAmountPesewas'=>2000]];
    $resp = renderState('DEP_CONFIRM', $s);
    br_assert(str_contains($resp['message'], 'GHS 20.00'), 'amount');
    br_assert(str_contains($resp['message'], 'MTN'), 'provider');
    br_assert(str_contains($resp['message'], 'GHS 35.00'), 'balance');
    br_assert(str_contains($resp['message'], '1. Confirm'), 'confirm');
});
$r->run('renders dash balance if read fails', function () {
    $s = ['msisdn'=>'233244001002', 'playerId'=>99999, 'data'=>['depAmountPesewas'=>2000]];
    $resp = renderState('DEP_CONFIRM', $s);
    // No player 99999 so playerBalances throws — we should still render
    br_assert(str_contains($resp['message'], 'GHS 20.00'), 'amount still shown');
});

echo "\n  DepConfirm handle\n";
$r->run('cancel (2) → END with cancelled message', function () {
    $pid = seedPlayer('233244002001', 'CANCEL USER');
    $s = ['msisdn'=>'233244002001', 'playerId'=>$pid, 'data'=>['depAmountPesewas'=>2000]];
    $resp = handle_DEP_CONFIRM('2', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'cancelled'), 'cancelled');
    br_assert(!isset($s['data']['depAmountPesewas']), 'amount cleared');
});
$r->run('invalid input → re-render', function () {
    $pid = seedPlayer('233244002002', 'INVALID USER');
    $s = ['msisdn'=>'233244002002', 'playerId'=>$pid, 'data'=>['depAmountPesewas'=>2000]];
    $resp = handle_DEP_CONFIRM('9', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Invalid'), 'invalid');
});
$r->run('confirm (1) → preflight succeeds, ENDs with task queued', function () {
    $pid = seedPlayer('233244002003', 'CONFIRM USER');
    $s = ['msisdn'=>'233244002003', 'playerId'=>$pid, 'data'=>['depAmountPesewas'=>2000]];

    $resp = handle_DEP_CONFIRM('1', $s);

    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'MoMo prompt'), 'message');
    br_assert_equals('deposit_anm_call', $resp['postResponseTask']);
    br_assert(isset($resp['taskArgs']['depositId']), 'depositId in task args');

    // Row was inserted with status='initiated'
    $row = db()->query("SELECT * FROM depositRequest WHERE playerId = $pid")->fetch();
    br_assert($row !== false, 'row exists');
    br_assert_equals('initiated', $row['status']);
    br_assert_equals('ussd', $row['channel']);
    br_assert_equals(2000, (int)$row['amountPesewas']);
    br_assert_equals(16, strlen($row['refNumber']), 'exttrid is 16 hex');
});

echo "\n  depositPreflight\n";
$r->run('rejects amount below min', function () {
    $pid = seedPlayer('233244003001', 'LOW USER');
    try {
        depositPreflight($pid, 100, '127.0.0.1');
        throw new Exception('!');
    } catch (InvalidArgumentException $e) {
        br_assert(str_contains($e->getMessage(), 'Minimum'), 'min msg');
    }
});
$r->run('rejects amount above max', function () {
    $pid = seedPlayer('233244003002', 'HIGH USER');
    try {
        depositPreflight($pid, 600000, '127.0.0.1');
        throw new Exception('!');
    } catch (InvalidArgumentException $e) {
        br_assert(str_contains($e->getMessage(), 'Maximum'), 'max msg');
    }
});
$r->run('rejects frozen player', function () {
    $pid = seedPlayer('233244003003', 'FROZEN USER', 'frozen');
    try {
        depositPreflight($pid, 2000, '127.0.0.1');
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'not eligible'), 'not eligible');
    }
});
$r->run('rejects unknown player id', function () {
    try {
        depositPreflight(99999, 2000, '127.0.0.1');
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'not eligible'), 'not eligible');
    }
});
$r->run('rejects if another deposit is pending', function () {
    $pid = seedPlayer('233244003004', 'BUSY USER');
    // First deposit OK
    $first = depositPreflight($pid, 2000, '127.0.0.1');
    br_assert($first['depositId'] > 0, 'first ok');
    // Second should fail
    try {
        depositPreflight($pid, 5000, '127.0.0.1');
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'waiting'), 'pending msg');
    }
});
$r->run('inserts row with all expected fields', function () {
    $pid = seedPlayer('233244003005', 'FIELDS USER');
    $pre = depositPreflight($pid, 5000, '203.0.113.7');
    $row = db()->query("SELECT * FROM depositRequest WHERE id = {$pre['depositId']}")->fetch();
    br_assert_equals('initiated', $row['status']);
    br_assert_equals('ussd', $row['channel']);
    br_assert_equals('233244003005', $row['msisdn']);
    br_assert_equals('MTN', $row['paymentProvider']);
    br_assert_equals(5000, (int)$row['amountPesewas']);
    br_assert_equals('203.0.113.7', $row['clientIp']);
    br_assert_equals(16, strlen($row['refNumber']));
});

echo "\n  depositFireAnm\n";
$r->run('accepted ANM response → marks row pending', function () {
    $pid = seedPlayer('233244004001', 'FIRE OK USER');
    $pre = depositPreflight($pid, 2000, '127.0.0.1');
    $GLOBALS['testAnmCtmStub'] = [
        'accepted'=>true, 'respCode'=>'015', 'respDesc'=>'received',
        'shouldThrow'=>false, 'errorMsg'=>''
    ];
    depositFireAnm($pre['depositId']);
    $row = db()->query("SELECT status, momoRequestPayload, momoResponsePayload FROM depositRequest WHERE id = {$pre['depositId']}")->fetch();
    br_assert_equals('pending', $row['status']);
    br_assert($row['momoRequestPayload'] !== null, 'request stashed');
    br_assert($row['momoResponsePayload'] !== null, 'response stashed');
});
$r->run('rejected ANM response → marks row failed + sends SMS', function () {
    $GLOBALS['testSmsLog'] = [];
    $pid = seedPlayer('233244004002', 'FIRE REJECT USER');
    $pre = depositPreflight($pid, 2000, '127.0.0.1');
    $GLOBALS['testAnmCtmStub'] = [
        'accepted'=>false, 'respCode'=>'099', 'respDesc'=>'insufficient funds',
        'shouldThrow'=>false, 'errorMsg'=>''
    ];
    depositFireAnm($pre['depositId']);
    $row = db()->query("SELECT status, failureCode, failureReason FROM depositRequest WHERE id = {$pre['depositId']}")->fetch();
    br_assert_equals('failed', $row['status']);
    br_assert_equals('099', $row['failureCode']);
    br_assert(str_contains($row['failureReason'], 'insufficient'), 'reason');
    // Apology SMS sent
    br_assert_equals(1, count($GLOBALS['testSmsLog']), 'SMS sent');
    br_assert(str_contains($GLOBALS['testSmsLog'][0]['content'], 'could not be processed'), 'apology msg');
});
$r->run('network error → marks row failed + sends SMS', function () {
    $GLOBALS['testSmsLog'] = [];
    $pid = seedPlayer('233244004003', 'FIRE NET USER');
    $pre = depositPreflight($pid, 2000, '127.0.0.1');
    $GLOBALS['testAnmCtmStub'] = [
        'accepted'=>false, 'respCode'=>'', 'respDesc'=>'',
        'shouldThrow'=>true, 'errorMsg'=>'Could not reach MoMo provider.'
    ];
    depositFireAnm($pre['depositId']);
    $row = db()->query("SELECT status, failureCode FROM depositRequest WHERE id = {$pre['depositId']}")->fetch();
    br_assert_equals('failed', $row['status']);
    br_assert_equals('network_error', $row['failureCode']);
    br_assert_equals(1, count($GLOBALS['testSmsLog']), 'SMS sent');

    // Reset stub
    $GLOBALS['testAnmCtmStub'] = [
        'accepted'=>true, 'respCode'=>'015', 'respDesc'=>'received',
        'shouldThrow'=>false, 'errorMsg'=>''
    ];
});
$r->run('idempotent: double-firing same depositId is safe', function () {
    $pid = seedPlayer('233244004004', 'FIRE DOUBLE USER');
    $pre = depositPreflight($pid, 2000, '127.0.0.1');
    depositFireAnm($pre['depositId']);
    depositFireAnm($pre['depositId']);  // second call: row is 'pending', should no-op
    $row = db()->query("SELECT status FROM depositRequest WHERE id = {$pre['depositId']}")->fetch();
    br_assert_equals('pending', $row['status']);
});

echo "\n  depositSettleSuccess\n";
$r->run('credits PLAY wallet, creates walletTxn + 3 ledger entries', function () {
    $pid = seedPlayer('233244005001', 'SETTLE USER');
    $pre = depositPreflight($pid, 10000, '127.0.0.1');
    depositFireAnm($pre['depositId']);  // → pending

    $handled = depositSettleSuccess($pre['exttrid'], '000/200', '149.81.198.237', ['ok'=>true]);
    br_assert(true === $handled, 'handled');

    // Wallet credited
    $bal = playerBalances($pid);
    br_assert_equals(10000, $bal['play'], 'wallet credited GHS 100');

    // depositRequest updated
    $row = db()->query("SELECT status, walletTxnId, feePesewas, callbackIp FROM depositRequest WHERE id = {$pre['depositId']}")->fetch();
    br_assert_equals('succeeded', $row['status']);
    br_assert($row['walletTxnId'] !== null, 'walletTxnId set');
    br_assert_equals(100, (int)$row['feePesewas'], '1% fee = 100 pesewas');
    br_assert_equals('149.81.198.237', $row['callbackIp']);

    // walletTransaction row
    $wtx = db()->query("SELECT * FROM walletTransaction WHERE refNumber = '{$pre['exttrid']}'")->fetch();
    br_assert($wtx !== false, 'walletTransaction exists');
    br_assert_equals('DEPOSIT', $wtx['txnType']);
    br_assert_equals(10000, (int)$wtx['amountPesewas']);
    br_assert_equals('completed', $wtx['status']);
    br_assert_equals('ussd', $wtx['channel']);

    // 3 ledger entries (debit float, debit fee, credit play)
    $entries = db()->query("SELECT side, amountPesewas FROM ledgerEntry WHERE walletTxnId = {$wtx['id']} ORDER BY id")->fetchAll();
    br_assert_equals(3, count($entries), '3 entries');
    br_assert_equals('debit',  $entries[0]['side']);  // MOMO_FLOAT
    br_assert_equals(9900,     (int)$entries[0]['amountPesewas']);  // 10000 - 100 fee
    br_assert_equals('debit',  $entries[1]['side']);  // MOMO_FEE_EXPENSE
    br_assert_equals(100,      (int)$entries[1]['amountPesewas']);
    br_assert_equals('credit', $entries[2]['side']);  // PLAYER_PLAY
    br_assert_equals(10000,    (int)$entries[2]['amountPesewas']);

    // Sum check: debits = credits
    $sumD = 0; $sumC = 0;
    foreach ($entries as $e) {
        if ($e['side'] === 'debit') $sumD += (int)$e['amountPesewas'];
        else $sumC += (int)$e['amountPesewas'];
    }
    br_assert_equals($sumD, $sumC, 'debits == credits');
});

$r->run('idempotent on retry — does not double-credit', function () {
    $pid = seedPlayer('233244005002', 'IDEM USER');
    $pre = depositPreflight($pid, 5000, '127.0.0.1');
    depositFireAnm($pre['depositId']);

    depositSettleSuccess($pre['exttrid'], '000/200', '1.2.3.4', null);
    depositSettleSuccess($pre['exttrid'], '000/200', '1.2.3.4', null);  // retry
    depositSettleSuccess($pre['exttrid'], '000/200', '1.2.3.4', null);  // retry

    $bal = playerBalances($pid);
    br_assert_equals(5000, $bal['play'], 'credited once only');

    $wtxCount = (int)db()->query("SELECT COUNT(*) FROM walletTransaction WHERE refNumber = '{$pre['exttrid']}'")->fetchColumn();
    br_assert_equals(1, $wtxCount, 'one walletTransaction only');

    $entryCount = (int)db()->query("SELECT COUNT(*) FROM ledgerEntry")->fetchColumn();
    // Each settled deposit makes 3 entries. We have other tests' deposits before this one.
    // So just check the count for THIS deposit's wtx is 3.
    $wtxId = (int)db()->query("SELECT id FROM walletTransaction WHERE refNumber = '{$pre['exttrid']}'")->fetchColumn();
    $thisCount = (int)db()->query("SELECT COUNT(*) FROM ledgerEntry WHERE walletTxnId = $wtxId")->fetchColumn();
    br_assert_equals(3, $thisCount, 'one set of ledger entries');
});

$r->run('unknown refNumber → returns false, no work', function () {
    $handled = depositSettleSuccess('deadbeefdeadbeef', '000/200', '1.2.3.4', null);
    br_assert_equals(false, $handled);
});

echo "\n  depositSettleFailure\n";
$r->run('marks row failed, no wallet change', function () {
    $GLOBALS['testSmsLog'] = [];
    $pid = seedPlayer('233244006001', 'FAIL USER');
    setBalance($pid, 'PLAY', 1000);
    $pre = depositPreflight($pid, 2000, '127.0.0.1');
    depositFireAnm($pre['depositId']);

    $handled = depositSettleFailure($pre['exttrid'], '001/01', '1.2.3.4', null);
    br_assert(true === $handled, 'handled');

    $row = db()->query("SELECT status, failureCode FROM depositRequest WHERE id = {$pre['depositId']}")->fetch();
    br_assert_equals('failed', $row['status']);
    br_assert_equals('001/01', $row['failureCode']);

    // No wallet change
    $bal = playerBalances($pid);
    br_assert_equals(1000, $bal['play'], 'balance unchanged');
});

echo "\n  Main menu routing\n";
$r->run('input 2 from main menu → DEP_ENTER_AMOUNT', function () {
    $pid = seedPlayer('233244007001', 'MENU USER');
    $s = ['msisdn'=>'233244007001', 'playerId'=>$pid, 'data'=>[], 'firstName'=>'Menu'];
    $resp = handle_MAIN_MENU('2', $s);
    br_assert_equals('DEP_ENTER_AMOUNT', $resp['nextState']);
});

echo "\n  E2E deposit\n";
$r->run('full flow: menu → amount → confirm → preflight + task queued', function () {
    $GLOBALS['testSmsLog'] = [];
    $pid = seedPlayer('233244008001', 'E2E USER');
    setBalance($pid, 'PLAY', 0);

    $s = ['msisdn'=>'233244008001', 'playerId'=>$pid, 'data'=>[], 'firstName'=>'E2E', 'state'=>'MAIN_MENU'];

    // Turn: main menu input 2
    $r1 = handle_MAIN_MENU('2', $s);
    br_assert_equals('DEP_ENTER_AMOUNT', $r1['nextState']);
    $s['state'] = 'DEP_ENTER_AMOUNT';

    // Render amount screen
    $r2 = renderState('DEP_ENTER_AMOUNT', $s);
    br_assert(str_contains($r2['message'], 'Enter Deposit'), 'amount prompt');

    // Enter amount
    $r3 = handle_DEP_ENTER_AMOUNT('50', $s);
    br_assert_equals('DEP_CONFIRM', $r3['nextState']);
    br_assert_equals(5000, $s['data']['depAmountPesewas']);
    $s['state'] = 'DEP_CONFIRM';

    // Render confirm screen
    $r4 = renderState('DEP_CONFIRM', $s);
    br_assert(str_contains($r4['message'], 'GHS 50.00'), 'amount on confirm');
    br_assert(str_contains($r4['message'], '1. Confirm'), 'confirm option');

    // Confirm — END with task
    $r5 = handle_DEP_CONFIRM('1', $s);
    br_assert_equals(false, $r5['continue']);
    br_assert_equals('deposit_anm_call', $r5['postResponseTask']);
    $depositId = $r5['taskArgs']['depositId'];

    // Simulate the post-response task running
    depositFireAnm($depositId);

    // Simulate ANM callback with success
    $row = db()->query("SELECT refNumber FROM depositRequest WHERE id = $depositId")->fetch();
    depositSettleSuccess($row['refNumber'], '000/200', '149.81.198.237', null);

    // Wallet credited
    $bal = playerBalances($pid);
    br_assert_equals(5000, $bal['play'], 'wallet has GHS 50');

    // Status is succeeded
    $final = db()->query("SELECT status FROM depositRequest WHERE id = $depositId")->fetch();
    br_assert_equals('succeeded', $final['status']);
});

// =============================================================================
// PR 6 (Path 1): Payout → Play internal transfer
// =============================================================================

// Helper to set a player's password so we can test the WdPlayPassword state.
function setPlayerPassword(int $playerId, string $plaintext): void
{
    $hash = hashPassword($plaintext);
    db()->prepare("UPDATE player SET passwordHash = :h WHERE id = :id")
        ->execute([':h' => $hash, ':id' => $playerId]);
}

/**
 * Seed (or reset) today's dailyRevenueSummary to a known state.
 * Clears any existing row first to avoid UNIQUE collisions across tests
 * sharing the same businessDate within one run.
 */
function seedSummary(int $floor, int $losses, int $wins, int $rounds): void
{
    $today = gameBusinessDate();
    db()->prepare("DELETE FROM dailyRevenueSummary WHERE businessDate = :d")->execute([':d' => $today]);
    db()->prepare(
        "INSERT INTO dailyRevenueSummary (businessDate, floorPesewas, lossesPesewas, winsPesewas, roundsCount)
         VALUES (:d, :f, :l, :w, :r)"
    )->execute([':d'=>$today, ':f'=>$floor, ':l'=>$losses, ':w'=>$wins, ':r'=>$rounds]);
}

echo "\n  WdPickDestination\n";
$r->run('renders both options', function () {
    $s = ['data'=>[], 'msisdn'=>'233244500001', 'playerId'=>1];
    $resp = renderState('WD_PICK_DESTINATION', $s);
    br_assert(str_contains($resp['message'], '1. Play Balance'), 'play');
    br_assert(str_contains($resp['message'], '2. MoMo'), 'momo');
});
$r->run('input 1 → WD_PLAY_ENTER_AMOUNT', function () {
    $s = ['data'=>[], 'msisdn'=>'233244500002', 'playerId'=>1];
    $resp = handle_WD_PICK_DESTINATION('1', $s);
    br_assert_equals('WD_PLAY_ENTER_AMOUNT', $resp['nextState']);
});
$r->run('invalid input → re-render', function () {
    $s = ['data'=>[], 'msisdn'=>'233244500004', 'playerId'=>1];
    $resp = handle_WD_PICK_DESTINATION('9', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Invalid'), 'invalid msg');
});

echo "\n  WdPlayEnterAmount\n";
$r->run('renders payout balance, min, max', function () {
    $pid = seedPlayer('233244501001', 'AMT USER');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['data'=>[], 'msisdn'=>'233244501001', 'playerId'=>$pid];
    $resp = renderState('WD_PLAY_ENTER_AMOUNT', $s);
    br_assert(str_contains($resp['message'], 'Payout Bal: GHS 50.00'), 'shows payout');
    br_assert(str_contains($resp['message'], 'Min: GHS 1.00'), 'shows min');
    br_assert(str_contains($resp['message'], 'Max: GHS 5000.00'), 'shows max');
});
$r->run('accepts integer "20" → 2000 pesewas', function () {
    $pid = seedPlayer('233244501002', 'INT USER');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['data'=>[], 'msisdn'=>'233244501002', 'playerId'=>$pid];
    $resp = handle_WD_PLAY_ENTER_AMOUNT('20', $s);
    br_assert_equals('WD_PLAY_CONFIRM', $resp['nextState']);
    br_assert_equals(2000, $s['data']['wdAmountPesewas']);
});
$r->run('accepts decimal "1.50" → 150 pesewas (boundary)', function () {
    $pid = seedPlayer('233244501003', 'DEC USER');
    setBalance($pid, 'PAYOUT', 1000);
    $s = ['data'=>[], 'msisdn'=>'233244501003', 'playerId'=>$pid];
    $resp = handle_WD_PLAY_ENTER_AMOUNT('1.50', $s);
    br_assert_equals(150, $s['data']['wdAmountPesewas']);
});
$r->run('rejects non-numeric', function () {
    $pid = seedPlayer('233244501004', 'NAN USER');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['data'=>[], 'msisdn'=>'233244501004', 'playerId'=>$pid];
    $resp = handle_WD_PLAY_ENTER_AMOUNT('twenty', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Invalid'), 'invalid');
});
$r->run('rejects below min', function () {
    $pid = seedPlayer('233244501005', 'LOW USER');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['data'=>[], 'msisdn'=>'233244501005', 'playerId'=>$pid];
    $resp = handle_WD_PLAY_ENTER_AMOUNT('0.50', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Too low'), 'too low');
});
$r->run('rejects above max', function () {
    $pid = seedPlayer('233244501006', 'HIGH USER');
    setBalance($pid, 'PAYOUT', 600000);
    $s = ['data'=>[], 'msisdn'=>'233244501006', 'playerId'=>$pid];
    $resp = handle_WD_PLAY_ENTER_AMOUNT('6000', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Too high'), 'too high');
});
$r->run('rejects if amount exceeds Payout balance', function () {
    $pid = seedPlayer('233244501007', 'INSUF USER');
    setBalance($pid, 'PAYOUT', 1500);  // GHS 15
    $s = ['data'=>[], 'msisdn'=>'233244501007', 'playerId'=>$pid];
    $resp = handle_WD_PLAY_ENTER_AMOUNT('20', $s);  // GHS 20
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Not enough'), 'soft balance check');
});

echo "\n  WdPlayConfirm render\n";
$r->run('shows amount + Play Balance + options', function () {
    $pid = seedPlayer('233244502001', 'CONF USER');
    setBalance($pid, 'PAYOUT', 5000);
    setBalance($pid, 'PLAY', 1200);
    $s = [
        'msisdn'   => '233244502001',
        'playerId' => $pid,
        'data'     => ['wdAmountPesewas' => 2000],
    ];
    $resp = renderState('WD_PLAY_CONFIRM', $s);
    br_assert(str_contains($resp['message'], 'Move GHS 20.00'), 'amount');
    br_assert(str_contains($resp['message'], 'Play Balance'), 'destination wording');
    br_assert(str_contains($resp['message'], '1. Confirm'), 'confirm option');
    br_assert(str_contains($resp['message'], '2. Cancel'), 'cancel option');
});

echo "\n  WdPlayConfirm handle\n";
$r->run('confirm (1) → WD_PLAY_PASSWORD, resets pw attempts', function () {
    $pid = seedPlayer('233244502002', 'CONF YES');
    setBalance($pid, 'PAYOUT', 5000);
    $s = [
        'msisdn'   => '233244502002', 'playerId' => $pid,
        'data'     => ['wdAmountPesewas' => 2000, 'wdPwAttempts' => 1],
    ];
    $resp = handle_WD_PLAY_CONFIRM('1', $s);
    br_assert_equals('WD_PLAY_PASSWORD', $resp['nextState']);
    br_assert_equals(0, $s['data']['wdPwAttempts'], 'attempts reset');
});
$r->run('cancel (2) → END, amount cleared', function () {
    $s = ['data' => ['wdAmountPesewas' => 2000]];
    $resp = handle_WD_PLAY_CONFIRM('2', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'cancelled'), 'cancelled');
    br_assert(!isset($s['data']['wdAmountPesewas']), 'amount cleared');
});

echo "\n  WdPlayPassword\n";
$r->run('renders password prompt (and primes state file load)', function () {
    $pid = seedPlayer('233244503000', 'PRIME');
    $s = ['msisdn' => '233244503000', 'playerId' => $pid, 'data' => ['wdAmountPesewas' => 2000]];
    $resp = renderState('WD_PLAY_PASSWORD', $s);
    br_assert(str_contains($resp['message'], 'password'), 'password prompt');
});
$r->run('correct password → transfers + ENDs with new balances', function () {
    $pid = seedPlayer('233244503001', 'PW USER');
    setPlayerPassword($pid, 'Test1234!');
    setBalance($pid, 'PAYOUT', 5000);
    setBalance($pid, 'PLAY', 1200);

    $s = [
        'msisdn'   => '233244503001', 'playerId' => $pid,
        'data'     => ['wdAmountPesewas' => 2000, 'wdPwAttempts' => 0],
    ];
    $resp = handle_WD_PLAY_PASSWORD('Test1234!', $s);

    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'complete'), 'success msg');
    br_assert(str_contains($resp['message'], 'GHS 30.00'), 'new payout shown');
    br_assert(str_contains($resp['message'], 'GHS 32.00'), 'new play shown');

    // Wallets updated
    $bal = playerBalances($pid);
    br_assert_equals(3000, $bal['payout'], 'payout reduced');
    br_assert_equals(3200, $bal['play'], 'play increased');

    // Sensitive flow data cleared
    br_assert(!isset($s['data']['wdAmountPesewas']), 'amount cleared');
    br_assert(!isset($s['data']['wdPwAttempts']), 'attempts cleared');
});
$r->run('wrong password first time → retry once', function () {
    $pid = seedPlayer('233244503002', 'PW WRONG1');
    setPlayerPassword($pid, 'Right1234!');
    setBalance($pid, 'PAYOUT', 5000);
    $s = [
        'msisdn'   => '233244503002', 'playerId' => $pid,
        'data'     => ['wdAmountPesewas' => 2000, 'wdPwAttempts' => 0],
    ];
    $resp = handle_WD_PLAY_PASSWORD('Wrong1234!', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Wrong password'), 'wrong pw');
    br_assert(str_contains($resp['message'], 'once more'), 'try once more');
    br_assert_equals(1, $s['data']['wdPwAttempts']);
    // No transfer happened
    $bal = playerBalances($pid);
    br_assert_equals(5000, $bal['payout'], 'payout unchanged');
});
$r->run('wrong password twice → ENDs and clears flow', function () {
    $pid = seedPlayer('233244503003', 'PW WRONG2');
    setPlayerPassword($pid, 'Right1234!');
    setBalance($pid, 'PAYOUT', 5000);
    $s = [
        'msisdn'   => '233244503003', 'playerId' => $pid,
        'data'     => ['wdAmountPesewas' => 2000, 'wdPwAttempts' => 1],  // already had 1 wrong
    ];
    $resp = handle_WD_PLAY_PASSWORD('AnotherWrong1!', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Too many'), 'too many');
    br_assert(!isset($s['data']['wdAmountPesewas']), 'amount cleared');
    br_assert(!isset($s['data']['wdPwAttempts']), 'attempts cleared');
    // No transfer
    $bal = playerBalances($pid);
    br_assert_equals(5000, $bal['payout'], 'payout unchanged');
});
$r->run('player without password → END account error', function () {
    $pid = seedPlayer('233244503004', 'NO PW');  // seedPlayer doesn't set password
    setBalance($pid, 'PAYOUT', 5000);
    $s = [
        'msisdn' => '233244503004', 'playerId' => $pid,
        'data'   => ['wdAmountPesewas' => 2000, 'wdPwAttempts' => 0],
    ];
    $resp = handle_WD_PLAY_PASSWORD('AnyThing1!', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Account error'), 'account error');
});

echo "\n  withdrawalToPlay\n";
$r->run('atomic: 1 walletTxn + 2 ledger entries + 2 wallet updates', function () {
    $pid = seedPlayer('233244504001', 'XFER USER');
    setBalance($pid, 'PAYOUT', 10000);
    setBalance($pid, 'PLAY', 0);

    $result = withdrawalToPlay($pid, 3000, '127.0.0.1');

    br_assert($result['withdrawalId'] > 0, 'withdrawalId returned');
    br_assert_equals(16, strlen($result['exttrid']), 'exttrid is 16 hex');
    br_assert_equals(3000, $result['newPlayBalance']);
    br_assert_equals(7000, $result['newPayoutBalance']);

    // withdrawalRequest row
    $w = db()->query("SELECT * FROM withdrawalRequest WHERE id = {$result['withdrawalId']}")->fetch();
    br_assert_equals('succeeded', $w['status']);
    br_assert_equals('PLAY', $w['destination']);
    br_assert_equals('PAYOUT', $w['sourceWallet']);
    br_assert_equals('ussd', $w['channel']);
    br_assert_equals(3000, (int)$w['amountPesewas']);
    br_assert($w['walletTxnId'] !== null, 'walletTxnId linked');
    br_assert($w['approvedAt'] !== null, 'approvedAt set');
    br_assert($w['completedAt'] !== null, 'completedAt set');

    // walletTransaction row
    $wtx = db()->query("SELECT * FROM walletTransaction WHERE refNumber = '{$result['exttrid']}'")->fetch();
    br_assert_equals('INTERNAL_XFER', $wtx['txnType']);
    br_assert_equals(3000, (int)$wtx['amountPesewas']);
    br_assert_equals('completed', $wtx['status']);
    br_assert_equals('ussd', $wtx['channel']);

    // Two ledger entries — debit payout, credit play
    $entries = db()->query("SELECT side, amountPesewas FROM ledgerEntry WHERE walletTxnId = {$wtx['id']} ORDER BY id")->fetchAll();
    br_assert_equals(2, count($entries), 'two entries');
    br_assert_equals('debit', $entries[0]['side']);
    br_assert_equals(3000, (int)$entries[0]['amountPesewas']);
    br_assert_equals('credit', $entries[1]['side']);
    br_assert_equals(3000, (int)$entries[1]['amountPesewas']);
});
$r->run('rejects below minimum', function () {
    $pid = seedPlayer('233244504002', 'MIN USER');
    setBalance($pid, 'PAYOUT', 5000);
    try {
        withdrawalToPlay($pid, 50, '127.0.0.1');  // GHS 0.50 — below min
        throw new Exception('!');
    } catch (InvalidArgumentException $e) {
        br_assert(str_contains($e->getMessage(), 'Minimum'), 'min msg');
    }
});
$r->run('rejects above maximum', function () {
    $pid = seedPlayer('233244504003', 'MAX USER');
    setBalance($pid, 'PAYOUT', 600000);  // GHS 6000
    try {
        withdrawalToPlay($pid, 600000, '127.0.0.1');
        throw new Exception('!');
    } catch (InvalidArgumentException $e) {
        br_assert(str_contains($e->getMessage(), 'Maximum'), 'max msg');
    }
});
$r->run('rejects frozen player', function () {
    $pid = seedPlayer('233244504004', 'FROZEN', 'frozen');
    setBalance($pid, 'PAYOUT', 5000);
    try {
        withdrawalToPlay($pid, 2000, '127.0.0.1');
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'not eligible'), 'not eligible');
    }
});
$r->run('rejects insufficient Payout funds (race-safe)', function () {
    $pid = seedPlayer('233244504005', 'INSUF');
    setBalance($pid, 'PAYOUT', 1000);  // GHS 10
    try {
        withdrawalToPlay($pid, 2000, '127.0.0.1');  // GHS 20
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'Not enough'), 'not enough');
    }
    // Wallets unchanged
    $bal = playerBalances($pid);
    br_assert_equals(1000, $bal['payout'], 'payout unchanged');
});
$r->run('whole Payout balance can be transferred', function () {
    $pid = seedPlayer('233244504006', 'ALL');
    setBalance($pid, 'PAYOUT', 5000);
    setBalance($pid, 'PLAY', 0);
    $result = withdrawalToPlay($pid, 5000, '127.0.0.1');
    br_assert_equals(0, $result['newPayoutBalance']);
    br_assert_equals(5000, $result['newPlayBalance']);
});

echo "\n  E2E withdrawal Path 1\n";
$r->run('full flow: menu → pick → amount → confirm → password → moved', function () {
    $pid = seedPlayer('233244505001', 'E2E WD');
    setPlayerPassword($pid, 'Test1234!');
    setBalance($pid, 'PAYOUT', 10000);
    setBalance($pid, 'PLAY', 500);

    $s = [
        'msisdn'   => '233244505001', 'playerId' => $pid,
        'data'     => [], 'firstName' => 'E2E', 'state' => 'MAIN_MENU',
    ];

    // Turn 1: main menu input 3
    $r1 = handle_MAIN_MENU('3', $s);
    br_assert_equals('WD_PICK_DESTINATION', $r1['nextState']);
    $s['state'] = 'WD_PICK_DESTINATION';

    // Turn 2: pick Play balance
    renderState('WD_PICK_DESTINATION', $s);
    $r2 = handle_WD_PICK_DESTINATION('1', $s);
    br_assert_equals('WD_PLAY_ENTER_AMOUNT', $r2['nextState']);
    $s['state'] = 'WD_PLAY_ENTER_AMOUNT';

    // Turn 3: enter amount
    renderState('WD_PLAY_ENTER_AMOUNT', $s);
    $r3 = handle_WD_PLAY_ENTER_AMOUNT('30', $s);
    br_assert_equals('WD_PLAY_CONFIRM', $r3['nextState']);
    br_assert_equals(3000, $s['data']['wdAmountPesewas']);
    $s['state'] = 'WD_PLAY_CONFIRM';

    // Turn 4: confirm
    $r4preview = renderState('WD_PLAY_CONFIRM', $s);
    br_assert(str_contains($r4preview['message'], 'GHS 30.00'), 'confirm shows amount');
    br_assert(str_contains($r4preview['message'], 'Play Balance'), 'confirm shows destination');
    $r4 = handle_WD_PLAY_CONFIRM('1', $s);
    br_assert_equals('WD_PLAY_PASSWORD', $r4['nextState']);
    $s['state'] = 'WD_PLAY_PASSWORD';

    // Turn 5: password
    renderState('WD_PLAY_PASSWORD', $s);
    $r5 = handle_WD_PLAY_PASSWORD('Test1234!', $s);
    br_assert_equals(false, $r5['continue']);
    br_assert(str_contains($r5['message'], 'complete'), 'success');

    // Final state
    $bal = playerBalances($pid);
    br_assert_equals(7000, $bal['payout'], 'payout final');
    br_assert_equals(3500, $bal['play'], 'play final');
});

// =============================================================================
// PR 6 (Path 2): Payout → MoMo external withdrawal
// =============================================================================

echo "\n  WdPickDestination — MoMo now wired\n";
$r->run('input 2 → WD_MOMO_ENTER_AMOUNT (no longer coming-soon)', function () {
    $s = ['data'=>[], 'msisdn'=>'233244600001', 'playerId'=>1];
    $resp = handle_WD_PICK_DESTINATION('2', $s);
    br_assert_equals('WD_MOMO_ENTER_AMOUNT', $resp['nextState']);
});

echo "\n  WdMomoEnterAmount\n";
$r->run('renders payout balance + min/max (and primes file load)', function () {
    $pid = seedPlayer('233244601001', 'MOMO AMT');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['data'=>[], 'msisdn'=>'233244601001', 'playerId'=>$pid];
    $resp = renderState('WD_MOMO_ENTER_AMOUNT', $s);
    br_assert(str_contains($resp['message'], 'Withdraw to MoMo'), 'header');
    br_assert(str_contains($resp['message'], 'GHS 50.00'), 'payout shown');
    br_assert(str_contains($resp['message'], 'Min: GHS 1.00'), 'min');
    br_assert(str_contains($resp['message'], 'Max: GHS 5000.00'), 'max');
});
$r->run('accepts decimal "25.50"', function () {
    $pid = seedPlayer('233244601002', 'MOMO DEC');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['data'=>[], 'msisdn'=>'233244601002', 'playerId'=>$pid];
    $resp = handle_WD_MOMO_ENTER_AMOUNT('25.50', $s);
    br_assert_equals('WD_MOMO_CONFIRM', $resp['nextState']);
    br_assert_equals(2550, $s['data']['wdAmountPesewas']);
});
$r->run('rejects above max', function () {
    $pid = seedPlayer('233244601003', 'MOMO HIGH');
    setBalance($pid, 'PAYOUT', 600000);
    $s = ['data'=>[], 'msisdn'=>'233244601003', 'playerId'=>$pid];
    $resp = handle_WD_MOMO_ENTER_AMOUNT('6000', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Too high'), 'too high');
});
$r->run('rejects if amount exceeds Payout balance', function () {
    $pid = seedPlayer('233244601004', 'MOMO INSUF');
    setBalance($pid, 'PAYOUT', 1500);
    $s = ['data'=>[], 'msisdn'=>'233244601004', 'playerId'=>$pid];
    $resp = handle_WD_MOMO_ENTER_AMOUNT('30', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Not enough'), 'soft balance check');
});

echo "\n  WdMomoConfirm\n";
$r->run('renders amount + destination number + provider', function () {
    $pid = seedPlayer('233244602001', 'CONF USER');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['msisdn'=>'233244602001', 'playerId'=>$pid, 'data'=>['wdAmountPesewas'=>2000]];
    $resp = renderState('WD_MOMO_CONFIRM', $s);
    br_assert(str_contains($resp['message'], 'Send GHS 20.00'), 'amount');
    br_assert(str_contains($resp['message'], '0244602001'), 'destination msisdn shown');
    br_assert(str_contains($resp['message'], 'MTN'), 'provider');
    br_assert(str_contains($resp['message'], '1. Confirm'), 'confirm');
});
$r->run('confirm (1) → WD_MOMO_PASSWORD', function () {
    $pid = seedPlayer('233244602002', 'CONF YES');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['msisdn'=>'233244602002', 'playerId'=>$pid,
          'data'=>['wdAmountPesewas'=>2000, 'wdPwAttempts'=>1]];
    $resp = handle_WD_MOMO_CONFIRM('1', $s);
    br_assert_equals('WD_MOMO_PASSWORD', $resp['nextState']);
    br_assert_equals(0, $s['data']['wdPwAttempts'], 'attempts reset');
});
$r->run('cancel (2) → END, amount cleared', function () {
    $s = ['data' => ['wdAmountPesewas' => 2000]];
    $resp = handle_WD_MOMO_CONFIRM('2', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'cancelled'), 'cancelled');
    br_assert(!isset($s['data']['wdAmountPesewas']), 'amount cleared');
});

echo "\n  WdMomoPassword\n";
$r->run('renders prompt (and primes file load)', function () {
    $pid = seedPlayer('233244603000', 'PRIME');
    $s = ['msisdn'=>'233244603000', 'playerId'=>$pid, 'data'=>['wdAmountPesewas'=>2000]];
    $resp = renderState('WD_MOMO_PASSWORD', $s);
    br_assert(str_contains($resp['message'], 'password'), 'password prompt');
});
$r->run('correct pw + preflight → END with task queued', function () {
    $GLOBALS['testAnmMtcStub'] = [
        'accepted'=>true,'respCode'=>'015','respDesc'=>'received',
        'shouldThrow'=>false,'errorMsg'=>''
    ];
    $pid = seedPlayer('233244603001', 'GOOD PW');
    setPlayerPassword($pid, 'Test1234!');
    setBalance($pid, 'PAYOUT', 10000);

    $s = ['msisdn'=>'233244603001', 'playerId'=>$pid,
          'data'=>['wdAmountPesewas'=>2000, 'wdPwAttempts'=>0]];

    $resp = handle_WD_MOMO_PASSWORD('Test1234!', $s);

    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'on the way'), 'on the way');
    br_assert_equals('withdrawal_anm_call', $resp['postResponseTask']);
    br_assert(isset($resp['taskArgs']['withdrawalId']), 'withdrawalId set');

    // Row inserted with status='initiated', destination='MOMO'
    $row = db()->query("SELECT * FROM withdrawalRequest WHERE playerId = $pid")->fetch();
    br_assert_equals('initiated', $row['status']);
    br_assert_equals('MOMO', $row['destination']);
    br_assert_equals('PAYOUT', $row['sourceWallet']);
    br_assert_equals('ussd', $row['channel']);

    // Payout NOT yet debited (happens on callback)
    $bal = playerBalances($pid);
    br_assert_equals(10000, $bal['payout'], 'payout unchanged until callback');
});
$r->run('wrong password → retry once', function () {
    $pid = seedPlayer('233244603002', 'BAD PW');
    setPlayerPassword($pid, 'Right1234!');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['msisdn'=>'233244603002', 'playerId'=>$pid,
          'data'=>['wdAmountPesewas'=>2000, 'wdPwAttempts'=>0]];
    $resp = handle_WD_MOMO_PASSWORD('Wrong1234!', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Wrong password'), 'wrong');
    br_assert_equals(1, $s['data']['wdPwAttempts']);
});
$r->run('wrong password twice → END, clears flow', function () {
    $pid = seedPlayer('233244603003', 'BAD PW 2');
    setPlayerPassword($pid, 'Right1234!');
    setBalance($pid, 'PAYOUT', 5000);
    $s = ['msisdn'=>'233244603003', 'playerId'=>$pid,
          'data'=>['wdAmountPesewas'=>2000, 'wdPwAttempts'=>1]];
    $resp = handle_WD_MOMO_PASSWORD('AnotherWrong1!', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Too many'), 'too many');
});

echo "\n  withdrawalToMomoPreflight\n";
$r->run('inserts row with status=initiated, destination=MOMO', function () {
    $pid = seedPlayer('233244604001', 'PRE OK');
    setBalance($pid, 'PAYOUT', 10000);
    setBalance($pid, 'PLAY', 500);
    $pre = withdrawalToMomoPreflight($pid, 3000, '127.0.0.1');
    br_assert($pre['withdrawalId'] > 0, 'id');
    br_assert_equals(16, strlen($pre['exttrid']));

    $row = db()->query("SELECT * FROM withdrawalRequest WHERE id = {$pre['withdrawalId']}")->fetch();
    br_assert_equals('initiated', $row['status']);
    br_assert_equals('MOMO', $row['destination']);
    br_assert_equals('PAYOUT', $row['sourceWallet']);
    br_assert_equals(500, (int)$row['playBalanceAtRequestPesewas'], 'snapshot of play bal');
});
$r->run('rejects below min', function () {
    $pid = seedPlayer('233244604002', 'PRE LOW');
    setBalance($pid, 'PAYOUT', 5000);
    try {
        withdrawalToMomoPreflight($pid, 50, '127.0.0.1');
        throw new Exception('!');
    } catch (InvalidArgumentException $e) {
        br_assert(str_contains($e->getMessage(), 'Minimum'), 'min');
    }
});
$r->run('rejects insufficient Payout', function () {
    $pid = seedPlayer('233244604003', 'PRE INSUF');
    setBalance($pid, 'PAYOUT', 1500);
    try {
        withdrawalToMomoPreflight($pid, 3000, '127.0.0.1');
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'Not enough'), 'not enough');
    }
});
$r->run('one-in-flight: blocks second MoMo while first is pending', function () {
    $pid = seedPlayer('233244604004', 'INFLIGHT');
    setBalance($pid, 'PAYOUT', 10000);
    $first = withdrawalToMomoPreflight($pid, 2000, '127.0.0.1');
    br_assert($first['withdrawalId'] > 0, 'first ok');
    try {
        withdrawalToMomoPreflight($pid, 3000, '127.0.0.1');
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'in progress'), 'one-in-flight');
    }
});
$r->run('cooldown: blocks MoMo for 1hr after password reset', function () {
    $pid = seedPlayer('233244604005', 'COOLDOWN');
    setBalance($pid, 'PAYOUT', 5000);
    // Simulate a recent password reset
    db()->prepare(
        "INSERT INTO passwordResetRequest (playerId, status, usedAt) VALUES (:p, 'used', :t)"
    )->execute([':p' => $pid, ':t' => gmdate('Y-m-d H:i:s', time() - 600)]);  // 10min ago
    try {
        withdrawalToMomoPreflight($pid, 2000, '127.0.0.1');
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'password reset'), 'cooldown msg');
    }
});
$r->run('cooldown expires after 1hr — allows MoMo again', function () {
    $pid = seedPlayer('233244604006', 'EXPIRED');
    setBalance($pid, 'PAYOUT', 5000);
    // Password reset 2 hours ago — past the cooldown
    db()->prepare(
        "INSERT INTO passwordResetRequest (playerId, status, usedAt) VALUES (:p, 'used', :t)"
    )->execute([':p' => $pid, ':t' => gmdate('Y-m-d H:i:s', time() - 7200)]);
    $pre = withdrawalToMomoPreflight($pid, 2000, '127.0.0.1');
    br_assert($pre['withdrawalId'] > 0, 'allowed after cooldown');
});
$r->run('cooldown ignores unused reset records', function () {
    $pid = seedPlayer('233244604007', 'UNUSED');
    setBalance($pid, 'PAYOUT', 5000);
    db()->prepare(
        "INSERT INTO passwordResetRequest (playerId, status, usedAt) VALUES (:p, 'pending', :t)"
    )->execute([':p' => $pid, ':t' => gmdate('Y-m-d H:i:s', time() - 600)]);
    $pre = withdrawalToMomoPreflight($pid, 2000, '127.0.0.1');
    br_assert($pre['withdrawalId'] > 0, 'pending reset does not block');
});

echo "\n  withdrawalToMomoFireAnm\n";
$r->run('accepted → row pending', function () {
    $GLOBALS['testAnmMtcStub'] = ['accepted'=>true,'respCode'=>'015','respDesc'=>'',
        'shouldThrow'=>false,'errorMsg'=>''];
    $pid = seedPlayer('233244605001', 'FIRE OK');
    setBalance($pid, 'PAYOUT', 5000);
    $pre = withdrawalToMomoPreflight($pid, 2000, '127.0.0.1');
    withdrawalToMomoFireAnm($pre['withdrawalId']);
    $row = db()->query("SELECT status, momoRequestPayload, approvedAt FROM withdrawalRequest WHERE id = {$pre['withdrawalId']}")->fetch();
    br_assert_equals('pending', $row['status']);
    br_assert($row['momoRequestPayload'] !== null, 'request stashed');
    br_assert($row['approvedAt'] !== null, 'approvedAt set');
});
$r->run('rejected → row failed + apology SMS', function () {
    $GLOBALS['testSmsLog'] = [];
    $GLOBALS['testAnmMtcStub'] = ['accepted'=>false,'respCode'=>'099',
        'respDesc'=>'insufficient float','shouldThrow'=>false,'errorMsg'=>''];
    $pid = seedPlayer('233244605002', 'FIRE REJECT');
    setBalance($pid, 'PAYOUT', 5000);
    $pre = withdrawalToMomoPreflight($pid, 2000, '127.0.0.1');
    withdrawalToMomoFireAnm($pre['withdrawalId']);
    $row = db()->query("SELECT status, failureCode FROM withdrawalRequest WHERE id = {$pre['withdrawalId']}")->fetch();
    br_assert_equals('failed', $row['status']);
    br_assert_equals('099', $row['failureCode']);
    br_assert_equals(1, count($GLOBALS['testSmsLog']), 'apology SMS sent');
    // Payout unchanged
    $bal = playerBalances($pid);
    br_assert_equals(5000, $bal['payout']);
});
$r->run('network error → row failed + SMS', function () {
    $GLOBALS['testSmsLog'] = [];
    $GLOBALS['testAnmMtcStub'] = ['accepted'=>false,'respCode'=>'',
        'respDesc'=>'','shouldThrow'=>true,'errorMsg'=>'Could not reach MoMo.'];
    $pid = seedPlayer('233244605003', 'FIRE NET');
    setBalance($pid, 'PAYOUT', 5000);
    $pre = withdrawalToMomoPreflight($pid, 2000, '127.0.0.1');
    withdrawalToMomoFireAnm($pre['withdrawalId']);
    $row = db()->query("SELECT status, failureCode FROM withdrawalRequest WHERE id = {$pre['withdrawalId']}")->fetch();
    br_assert_equals('failed', $row['status']);
    br_assert_equals('network_error', $row['failureCode']);

    // Reset stub
    $GLOBALS['testAnmMtcStub'] = ['accepted'=>true,'respCode'=>'015','respDesc'=>'',
        'shouldThrow'=>false,'errorMsg'=>''];
});

echo "\n  withdrawalSettleSuccess\n";
$r->run('atomic: debits Payout, creates walletTxn + 3 ledger entries', function () {
    $pid = seedPlayer('233244606001', 'SETTLE OK');
    setBalance($pid, 'PAYOUT', 10000);
    setBalance($pid, 'PLAY', 0);

    $pre = withdrawalToMomoPreflight($pid, 5000, '127.0.0.1');
    withdrawalToMomoFireAnm($pre['withdrawalId']);  // → pending

    $handled = withdrawalSettleSuccess($pre['exttrid'], '000/200', '149.81.198.237', ['ok'=>true]);
    br_assert(true === $handled, 'handled');

    // Payout debited
    $bal = playerBalances($pid);
    br_assert_equals(5000, $bal['payout'], 'payout debited');
    br_assert_equals(0, $bal['play'], 'play unchanged');

    // Row updated
    $row = db()->query("SELECT status, walletTxnId, feePesewas, completedAt FROM withdrawalRequest WHERE id = {$pre['withdrawalId']}")->fetch();
    br_assert_equals('succeeded', $row['status']);
    br_assert($row['walletTxnId'] !== null, 'linked');
    br_assert_equals(25, (int)$row['feePesewas'], '0.5% of 5000 = 25 pesewas');
    br_assert($row['completedAt'] !== null, 'completedAt');

    // walletTransaction
    $wtx = db()->query("SELECT * FROM walletTransaction WHERE refNumber = '{$pre['exttrid']}'")->fetch();
    br_assert_equals('WITHDRAWAL_PAYOUT', $wtx['txnType']);
    br_assert_equals(5000, (int)$wtx['amountPesewas']);
    br_assert_equals('ussd', $wtx['channel']);

    // Three ledger entries
    $entries = db()->query("SELECT side, amountPesewas FROM ledgerEntry WHERE walletTxnId = {$wtx['id']} ORDER BY id")->fetchAll();
    br_assert_equals(3, count($entries), '3 entries');
    br_assert_equals('debit',  $entries[0]['side']);                              // PAYOUT
    br_assert_equals(5000,     (int)$entries[0]['amountPesewas']);
    br_assert_equals('credit', $entries[1]['side']);                              // FLOAT (amount+fee)
    br_assert_equals(5025,     (int)$entries[1]['amountPesewas']);
    br_assert_equals('debit',  $entries[2]['side']);                              // FEE EXPENSE
    br_assert_equals(25,       (int)$entries[2]['amountPesewas']);

    // Debits = credits
    $sumD = $entries[0]['amountPesewas'] + $entries[2]['amountPesewas'];
    $sumC = $entries[1]['amountPesewas'];
    br_assert_equals($sumD, $sumC, 'debits == credits');
});
$r->run('idempotent: retry does not double-debit', function () {
    $pid = seedPlayer('233244606002', 'IDEM WD');
    setBalance($pid, 'PAYOUT', 10000);
    $pre = withdrawalToMomoPreflight($pid, 3000, '127.0.0.1');
    withdrawalToMomoFireAnm($pre['withdrawalId']);

    withdrawalSettleSuccess($pre['exttrid'], '000/200', '1.2.3.4', null);
    withdrawalSettleSuccess($pre['exttrid'], '000/200', '1.2.3.4', null);
    withdrawalSettleSuccess($pre['exttrid'], '000/200', '1.2.3.4', null);

    $bal = playerBalances($pid);
    br_assert_equals(7000, $bal['payout'], 'debited once only');
});
$r->run('unknown refNumber returns false', function () {
    $handled = withdrawalSettleSuccess('cafebabecafebabe', '000/200', '1.2.3.4', null);
    br_assert_equals(false, $handled);
});

echo "\n  withdrawalSettleFailure\n";
$r->run('marks row failed, no wallet change', function () {
    $GLOBALS['testSmsLog'] = [];
    $pid = seedPlayer('233244607001', 'FAIL WD');
    setBalance($pid, 'PAYOUT', 5000);
    $pre = withdrawalToMomoPreflight($pid, 2000, '127.0.0.1');
    withdrawalToMomoFireAnm($pre['withdrawalId']);

    $handled = withdrawalSettleFailure($pre['exttrid'], '001/01', '1.2.3.4', null);
    br_assert(true === $handled, 'handled');

    $row = db()->query("SELECT status, failureCode FROM withdrawalRequest WHERE id = {$pre['withdrawalId']}")->fetch();
    br_assert_equals('failed', $row['status']);

    $bal = playerBalances($pid);
    br_assert_equals(5000, $bal['payout'], 'payout unchanged on fail');
});

echo "\n  E2E MoMo withdrawal\n";
$r->run('full flow: menu → MoMo → amount → confirm → password → ANM → settled', function () {
    $GLOBALS['testAnmMtcStub'] = ['accepted'=>true,'respCode'=>'015','respDesc'=>'',
        'shouldThrow'=>false,'errorMsg'=>''];
    $GLOBALS['testSmsLog'] = [];

    $pid = seedPlayer('233244608001', 'E2E MOMO');
    setPlayerPassword($pid, 'Test1234!');
    setBalance($pid, 'PAYOUT', 10000);

    $s = ['msisdn'=>'233244608001', 'playerId'=>$pid, 'data'=>[], 'state'=>'MAIN_MENU'];

    // Turn 1: input 3
    $r1 = handle_MAIN_MENU('3', $s);
    br_assert_equals('WD_PICK_DESTINATION', $r1['nextState']);
    $s['state'] = 'WD_PICK_DESTINATION';

    // Turn 2: pick MoMo
    renderState('WD_PICK_DESTINATION', $s);
    $r2 = handle_WD_PICK_DESTINATION('2', $s);
    br_assert_equals('WD_MOMO_ENTER_AMOUNT', $r2['nextState']);
    $s['state'] = 'WD_MOMO_ENTER_AMOUNT';

    // Turn 3: amount
    renderState('WD_MOMO_ENTER_AMOUNT', $s);
    $r3 = handle_WD_MOMO_ENTER_AMOUNT('30', $s);
    br_assert_equals('WD_MOMO_CONFIRM', $r3['nextState']);
    $s['state'] = 'WD_MOMO_CONFIRM';

    // Turn 4: confirm
    renderState('WD_MOMO_CONFIRM', $s);
    $r4 = handle_WD_MOMO_CONFIRM('1', $s);
    br_assert_equals('WD_MOMO_PASSWORD', $r4['nextState']);
    $s['state'] = 'WD_MOMO_PASSWORD';

    // Turn 5: password — END with task
    renderState('WD_MOMO_PASSWORD', $s);
    $r5 = handle_WD_MOMO_PASSWORD('Test1234!', $s);
    br_assert_equals(false, $r5['continue']);
    br_assert_equals('withdrawal_anm_call', $r5['postResponseTask']);
    $withdrawalId = $r5['taskArgs']['withdrawalId'];

    // Simulate post-response task: fire ANM
    withdrawalToMomoFireAnm($withdrawalId);

    // Simulate ANM callback
    $row = db()->query("SELECT refNumber FROM withdrawalRequest WHERE id = $withdrawalId")->fetch();
    withdrawalSettleSuccess($row['refNumber'], '000/200', '149.81.198.237', null);

    // Final state
    $bal = playerBalances($pid);
    br_assert_equals(7000, $bal['payout'], 'GHS 30 debited');

    $final = db()->query("SELECT status, feePesewas FROM withdrawalRequest WHERE id = $withdrawalId")->fetch();
    br_assert_equals('succeeded', $final['status']);
    br_assert_equals(15, (int)$final['feePesewas'], '0.5% of 3000 = 15');
});

// =============================================================================
// PR 4: Play (game engine)
// =============================================================================

echo "\n  Deck generation\n";
$r->run('generates 12 cards: 6 red + 6 black, no dupes', function () {
    for ($trial = 0; $trial < 20; $trial++) {
        $deck = gameGenerateLockedDeck();
        br_assert_equals(12, count($deck), 'twelve cards');
        $red = 0; $black = 0; $seen = [];
        foreach ($deck as $c) {
            br_assert(!isset($seen[$c]), "no dupe: $c");
            $seen[$c] = true;
            if (gameCardColor($c) === 'red') $red++; else $black++;
        }
        br_assert_equals(6, $red, 'six red');
        br_assert_equals(6, $black, 'six black');
    }
});
$r->run('cardColor: H,D red; C,S black', function () {
    br_assert_equals('red', gameCardColor('AH'));
    br_assert_equals('red', gameCardColor('KD'));
    br_assert_equals('black', gameCardColor('7C'));
    br_assert_equals('black', gameCardColor('2S'));
});

echo "\n  Multiplier table\n";
$r->run('matches [2,10,20,50,100]', function () {
    br_assert_equals(2,   gameMultiplier(1));
    br_assert_equals(10,  gameMultiplier(2));
    br_assert_equals(20,  gameMultiplier(3));
    br_assert_equals(50,  gameMultiplier(4));
    br_assert_equals(100, gameMultiplier(5));
});

echo "\n  validateInput\n";
$r->run('rejects bad game type', function () {
    br_assert(gameValidateInput(6, ['red'], 200) !== null, 'type 6 bad');
    br_assert(gameValidateInput(0, [], 200) !== null, 'type 0 bad');
});
$r->run('rejects picks length mismatch', function () {
    br_assert(gameValidateInput(3, ['red','black'], 200) !== null, 'len 2 != 3');
});
$r->run('rejects bad pick value', function () {
    br_assert(gameValidateInput(2, ['red','green'], 200) !== null, 'green bad');
});
$r->run('rejects stake below min', function () {
    br_assert(gameValidateInput(1, ['red'], 100) !== null, 'below 200');
});
$r->run('accepts valid input', function () {
    br_assert_equals(null, gameValidateInput(3, ['red','black','red'], 500));
});

echo "\n  pickDrawnCards — forced_loss always loses\n";
$r->run('forced_loss never matches picks (100 trials, all game types)', function () {
    for ($gt = 1; $gt <= 5; $gt++) {
        for ($trial = 0; $trial < 100; $trial++) {
            $deck = gameGenerateLockedDeck();
            // Random picks
            $picks = [];
            for ($i = 0; $i < $gt; $i++) $picks[] = random_int(0,1) ? 'red' : 'black';
            $drawn = gamePickDrawnCards($deck, $picks, 'forced_loss');
            br_assert_equals($gt, count($drawn), "drew $gt");
            // At least one position must mismatch
            $allMatch = true;
            for ($i = 0; $i < $gt; $i++) {
                if (gameCardColor($drawn[$i]) !== $picks[$i]) { $allMatch = false; break; }
            }
            br_assert(!$allMatch, "forced_loss gt=$gt trial=$trial must not match");
        }
    }
});
$r->run('fair_random draws N distinct deck cards', function () {
    $deck = gameGenerateLockedDeck();
    $drawn = gamePickDrawnCards($deck, ['red','black','red'], 'fair_random');
    br_assert_equals(3, count($drawn));
    foreach ($drawn as $c) br_assert(in_array($c, $deck, true), 'card from deck');
});

echo "\n  gamePlay — decision rule\n";
$r->run('forced_loss when net revenue negative', function () {
    $pid = seedPlayer('233244780001', 'NEGREV');
    setBalance($pid, 'PLAY', 100000);
    // Seed today's summary with wins > losses → net negative
    seedSummary(50000, 1000, 50000, 5);
    // Play many rounds; with net negative, all must be losses
    for ($i = 0; $i < 10; $i++) {
        $res = gamePlay($pid, 1, ['red'], 200, '127.0.0.1');
        br_assert_equals('loss', $res['outcome'], "round $i forced loss");
    }
});
$r->run('fair_random possible when net revenue healthy', function () {
    $pid = seedPlayer('233244700002', 'HEALTHY');
    setBalance($pid, 'PLAY', 1000000);
    seedSummary(50000, 5000000, 0, 50);
    // Play enough 1-card games that at least one should win by chance
    $wins = 0; $losses = 0;
    for ($i = 0; $i < 80; $i++) {
        $res = gamePlay($pid, 1, ['red'], 200, '127.0.0.1');
        if ($res['outcome'] === 'win') $wins++; else $losses++;
    }
    // With fair 50/50 over 80 rounds, overwhelmingly likely to see both
    br_assert($wins > 0, 'some wins under fair_random');
    br_assert($losses > 0, 'some losses under fair_random');
});

echo "\n  gamePlay — ledger correctness\n";
$r->run('loss: stake debits play→house, no payout', function () {
    $pid = seedPlayer('233244701001', 'LOSS LED');
    setBalance($pid, 'PLAY', 10000);
    seedSummary(50000, 0, 100, 1);

    $res = gamePlay($pid, 3, ['red','black','red'], 2000, '127.0.0.1');
    br_assert_equals('loss', $res['outcome']);
    br_assert_equals(0, $res['payoutPesewas']);

    // Play debited
    $bal = playerBalances($pid);
    br_assert_equals(8000, $bal['play'], 'play debited 2000');
    br_assert_equals(0, $bal['payout'], 'payout unchanged');

    // STAKE walletTransaction + 2 ledger entries
    $wtx = db()->query("SELECT * FROM walletTransaction WHERE refNumber = '{$res['refNumber']}'")->fetch();
    br_assert_equals('STAKE', $wtx['txnType']);
    br_assert_equals('ussd', $wtx['channel']);
    $entries = db()->query("SELECT side, amountPesewas FROM ledgerEntry WHERE walletTxnId = {$wtx['id']} ORDER BY id")->fetchAll();
    br_assert_equals(2, count($entries));
    br_assert_equals('debit', $entries[0]['side']);   // PLAY
    br_assert_equals('credit', $entries[1]['side']);  // HOUSE
    br_assert_equals(2000, (int)$entries[0]['amountPesewas']);
    br_assert_equals(2000, (int)$entries[1]['amountPesewas']);
});
$r->run('win: stake + win payout to PAYOUT wallet', function () {
    $pid = seedPlayer('233244701002', 'WIN LED');
    setBalance($pid, 'PLAY', 10000);
    seedSummary(50000, 10000000, 0, 99);

    // 1-card game, 50% win chance — loop until we get a win (cap iterations)
    $winRes = null;
    for ($i = 0; $i < 200 && $winRes === null; $i++) {
        setBalance($pid, 'PLAY', 10000);  // top up each try
        $res = gamePlay($pid, 1, ['red'], 200, '127.0.0.1');
        if ($res['outcome'] === 'win') $winRes = $res;
    }
    br_assert($winRes !== null, 'got at least one win in 200 tries');

    // Win payout = stake * 2 (1-card multiplier)
    br_assert_equals(400, $winRes['payoutPesewas'], 'payout = 200 * 2');

    // WIN walletTransaction
    $wtx = db()->query("SELECT * FROM walletTransaction WHERE txnType = 'WIN_PAYOUT' AND playerId = $pid ORDER BY id DESC LIMIT 1")->fetch();
    br_assert($wtx !== false, 'WIN_PAYOUT txn exists');
    $entries = db()->query("SELECT side, amountPesewas FROM ledgerEntry WHERE walletTxnId = {$wtx['id']} ORDER BY id")->fetchAll();
    br_assert_equals(2, count($entries));
    br_assert_equals('debit', $entries[0]['side']);   // HOUSE
    br_assert_equals('credit', $entries[1]['side']);  // PAYOUT
    br_assert_equals(400, (int)$entries[0]['amountPesewas']);
});

echo "\n  gamePlay — limits\n";
$r->run('rejects insufficient Play balance', function () {
    $pid = seedPlayer('233244702001', 'INSUF');
    setBalance($pid, 'PLAY', 100);  // GHS 1, below min stake anyway
    try {
        gamePlay($pid, 1, ['red'], 200, '127.0.0.1');
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'Insufficient'), 'insufficient');
    }
});
$r->run('rejects stake above max', function () {
    $pid = seedPlayer('233244702002', 'MAXSTK');
    setBalance($pid, 'PLAY', 500000);
    try {
        gamePlay($pid, 1, ['red'], 300000, '127.0.0.1');  // GHS 3000 > 2000 max
        throw new Exception('!');
    } catch (InvalidArgumentException $e) {
        br_assert(str_contains($e->getMessage(), 'Maximum'), 'max stake');
    }
});
$r->run('enforces daily stake limit', function () {
    $pid = seedPlayer('233244702003', 'DAILYLIM');
    setBalance($pid, 'PLAY', 5000000);
    seedSummary(50000, 0, 100, 1);
    // Daily limit is 2,000,000 pesewas (GHS 20k). Stake just under, then over.
    gamePlay($pid, 1, ['red'], 200000, '127.0.0.1');  // 2000 GHS — one round
    // Simulate prior stakes by inserting rounds totalling near the cap
    for ($i = 0; $i < 8; $i++) {
        gamePlay($pid, 1, ['red'], 200000, '127.0.0.1');  // 9 * 2000 = 18000 so far
    }
    // Next 2000 brings us to 20000 — still OK (<=). One more must fail.
    gamePlay($pid, 1, ['red'], 200000, '127.0.0.1');  // 20000 exactly
    try {
        gamePlay($pid, 1, ['red'], 200, '127.0.0.1');  // over the cap
        throw new Exception('!');
    } catch (RuntimeException $e) {
        br_assert(str_contains($e->getMessage(), 'limit'), 'daily limit');
    }
});

echo "\n  gamePlay — daily summary updates\n";
$r->run('loss increments lossesPesewas + roundsCount', function () {
    $pid = seedPlayer('233244703001', 'SUMLOSS');
    setBalance($pid, 'PLAY', 10000);
    seedSummary(50000, 0, 100, 0);
    gamePlay($pid, 3, ['red','red','red'], 2000, '127.0.0.1');  // forced loss (net neg)
    $today = gameBusinessDate();
    $sum = db()->query("SELECT lossesPesewas, roundsCount FROM dailyRevenueSummary WHERE businessDate = '$today'")->fetch();
    br_assert_equals(2000, (int)$sum['lossesPesewas'], 'losses += stake');
    br_assert_equals(1, (int)$sum['roundsCount'], 'rounds += 1');
});
$r->run('auto-creates summary row when none exists', function () {
    $pid = seedPlayer('233244703002', 'AUTOSUM');
    setBalance($pid, 'PLAY', 10000);
    // No summary row seeded — gamePlay should create it
    $res = gamePlay($pid, 1, ['red'], 200, '127.0.0.1');
    $today = gameBusinessDate();
    $sum = db()->query("SELECT floorPesewas FROM dailyRevenueSummary WHERE businessDate = '$today'")->fetch();
    br_assert($sum !== false, 'summary auto-created');
    br_assert_equals(50000, (int)$sum['floorPesewas'], 'floor snapshot');
});

echo "\n  gamePlay — audit row\n";
$r->run('writes gameRound with full engine fields', function () {
    $pid = seedPlayer('233244704001', 'AUDIT');
    setBalance($pid, 'PLAY', 10000);
    seedSummary(50000, 0, 100, 1);
    $res = gamePlay($pid, 3, ['red','black','red'], 2000, '203.0.113.9');
    $row = db()->query("SELECT * FROM gameRound WHERE refNumber = '{$res['refNumber']}'")->fetch();
    br_assert($row !== false, 'row exists');
    br_assert_equals(3, (int)$row['gameType']);
    br_assert_equals(20, (int)$row['multiplier']);
    br_assert_equals('red,black,red', $row['colorPicks'], 'picks imploded');
    br_assert_equals(2000, (int)$row['stakePesewas']);
    br_assert_equals(40000, (int)$row['potentialPayoutPesewas'], '2000 * 20');
    br_assert(in_array($row['enginePath'], ['fair_random','forced_loss'], true), 'enginePath set');
    br_assert($row['lockedDeck'] !== null, 'deck stored');
    br_assert($row['drawnCards'] !== null, 'drawn stored');
    br_assert_equals('203.0.113.9', $row['clientIp']);
    br_assert($row['stakeWalletTxnId'] !== null, 'stake txn linked');
});

echo "\n  Color pick parsing (PlayPickColors)\n";
$r->run('renders example + prompt (primes file load)', function () {
    $s = ['data'=>['playGameType'=>3], 'msisdn'=>'233244705000', 'playerId'=>1];
    $resp = renderState('PLAY_PICK_COLORS', $s);
    br_assert(str_contains($resp['message'], 'Enter your Selection'), 'header');
    br_assert(str_contains($resp['message'], 'For 3 Cards'), 'count');
    br_assert(str_contains($resp['message'], 'B = Black'), 'B legend');
    br_assert(str_contains($resp['message'], 'R = Red'), 'R legend');
});
$r->run('parses BRB → [black,red,black]', function () {
    $s = ['data'=>['playGameType'=>3], 'msisdn'=>'233244705001', 'playerId'=>1];
    $resp = handle_PLAY_PICK_COLORS('BRB', $s);
    br_assert_equals('PLAY_ENTER_STAKE', $resp['nextState']);
    br_assert_equals(['black','red','black'], $s['data']['playColorPicks']);
});
$r->run('case-insensitive: brb works', function () {
    $s = ['data'=>['playGameType'=>3], 'msisdn'=>'233244705002', 'playerId'=>1];
    $resp = handle_PLAY_PICK_COLORS('brb', $s);
    br_assert_equals(['black','red','black'], $s['data']['playColorPicks']);
});
$r->run('whitespace stripped: "B R B" works', function () {
    $s = ['data'=>['playGameType'=>3], 'msisdn'=>'233244705003', 'playerId'=>1];
    $resp = handle_PLAY_PICK_COLORS('B R B', $s);
    br_assert_equals(['black','red','black'], $s['data']['playColorPicks']);
});
$r->run('length mismatch rejected', function () {
    $s = ['data'=>['playGameType'=>3], 'msisdn'=>'233244705004', 'playerId'=>1];
    $resp = handle_PLAY_PICK_COLORS('BR', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'exactly'), 'length error');
});
$r->run('invalid char rejected', function () {
    $s = ['data'=>['playGameType'=>3], 'msisdn'=>'233244705005', 'playerId'=>1];
    $resp = handle_PLAY_PICK_COLORS('BXB', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Only B or R'), 'char error');
});

echo "\n  PlayPickType\n";
$r->run('renders 5 options (primes file load)', function () {
    $s = ['data'=>[], 'msisdn'=>'233244706000', 'playerId'=>1];
    $resp = renderState('PLAY_PICK_TYPE', $s);
    br_assert(str_contains($resp['message'], '1 Card(x2)'), 'opt1');
    br_assert(str_contains($resp['message'], '5 Cards(x100)'), 'opt5');
});
$r->run('input 3 sets gameType=3', function () {
    $s = ['data'=>[], 'msisdn'=>'233244706001', 'playerId'=>1];
    $resp = handle_PLAY_PICK_TYPE('3', $s);
    br_assert_equals('PLAY_PICK_COLORS', $resp['nextState']);
    br_assert_equals(3, $s['data']['playGameType']);
});
$r->run('invalid type rejected', function () {
    $s = ['data'=>[], 'msisdn'=>'233244706002', 'playerId'=>1];
    $resp = handle_PLAY_PICK_TYPE('9', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Invalid'), 'invalid');
});

echo "\n  PlayEnterStake\n";
$r->run('renders play balance + min/max (primes file load)', function () {
    $pid = seedPlayer('233244707001', 'STAKEUI');
    setBalance($pid, 'PLAY', 5000);
    $s = ['data'=>['playGameType'=>1,'playColorPicks'=>['red']], 'msisdn'=>'233244707001', 'playerId'=>$pid];
    $resp = renderState('PLAY_ENTER_STAKE', $s);
    br_assert(str_contains($resp['message'], 'Play Bal: GHS 50.00'), 'balance');
    br_assert(str_contains($resp['message'], 'Min: GHS 2.00'), 'min');
});
$r->run('accepts stake, routes to confirm', function () {
    $pid = seedPlayer('233244707002', 'STAKEOK');
    setBalance($pid, 'PLAY', 5000);
    $s = ['data'=>['playGameType'=>1,'playColorPicks'=>['red']], 'msisdn'=>'233244707002', 'playerId'=>$pid];
    $resp = handle_PLAY_ENTER_STAKE('20', $s);
    br_assert_equals('PLAY_CONFIRM', $resp['nextState']);
    br_assert_equals(2000, $s['data']['playStakePesewas']);
});
$r->run('rejects stake over Play balance', function () {
    $pid = seedPlayer('233244707003', 'STAKEINSUF');
    setBalance($pid, 'PLAY', 1000);
    $s = ['data'=>['playGameType'=>1,'playColorPicks'=>['red']], 'msisdn'=>'233244707003', 'playerId'=>$pid];
    $resp = handle_PLAY_ENTER_STAKE('20', $s);
    br_assert_equals(true, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Not enough'), 'soft check');
});

echo "\n  PlayConfirm + PlayAgain\n";
$r->run('confirm renders game summary (primes file load)', function () {
    $pid = seedPlayer('233244708001', 'CONFUI');
    setBalance($pid, 'PLAY', 5000);
    $s = ['data'=>['playGameType'=>3,'playColorPicks'=>['red','black','red'],'playStakePesewas'=>500],
          'msisdn'=>'233244708001', 'playerId'=>$pid];
    $resp = renderState('PLAY_CONFIRM', $s);
    br_assert(str_contains($resp['message'], '3 Cards (x20)'), 'game');
    br_assert(str_contains($resp['message'], 'R B R'), 'picks letters');
    br_assert(str_contains($resp['message'], 'Stake: GHS 5.00'), 'stake');
    br_assert(str_contains($resp['message'], 'Win: GHS 100.00'), 'potential');
});
$r->run('cancel ENDs', function () {
    $s = ['data'=>['playGameType'=>1,'playColorPicks'=>['red'],'playStakePesewas'=>200],
          'msisdn'=>'233244708002', 'playerId'=>1];
    $resp = handle_PLAY_CONFIRM('2', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Cancelled'), 'cancelled');
});
$r->run('confirm runs round → routes to PLAY_AGAIN with stashed result', function () {
    $pid = seedPlayer('233244708003', 'PLAYRUN');
    setBalance($pid, 'PLAY', 5000);
    seedSummary(50000, 0, 100, 1);  // net neg → loss
    $s = ['data'=>['playGameType'=>1,'playColorPicks'=>['red'],'playStakePesewas'=>2000],
          'msisdn'=>'233244708003', 'playerId'=>$pid];
    $resp = handle_PLAY_CONFIRM('1', $s);
    br_assert_equals('PLAY_AGAIN', $resp['nextState']);
    br_assert(isset($s['data']['playResultMsg']), 'result stashed');
    br_assert(!isset($s['data']['playGameType']), 'round data cleared');
    // Balance was debited
    $bal = playerBalances($pid);
    br_assert_equals(3000, $bal['play'], 'stake debited');
});
$r->run('PLAY_AGAIN renders stashed result (primes file load)', function () {
    $s = ['data'=>['playResultMsg'=>'You WON! 1. Play Again 2. Exit'], 'msisdn'=>'233244781000', 'playerId'=>1];
    $resp = renderState('PLAY_AGAIN', $s);
    br_assert(str_contains($resp['message'], 'Play Again'), 'renders result');
});
$r->run('PLAY_AGAIN input 1 → back to PLAY_PICK_TYPE', function () {
    $s = ['data'=>['playResultMsg'=>'You lost. 1. Play Again 2. Exit'], 'msisdn'=>'233244708004', 'playerId'=>1];
    $resp = handle_PLAY_AGAIN('1', $s);
    br_assert_equals('PLAY_PICK_TYPE', $resp['nextState']);
    br_assert(!isset($s['data']['playResultMsg']), 'result cleared');
});
$r->run('PLAY_AGAIN input 2 → END', function () {
    $s = ['data'=>['playResultMsg'=>'msg'], 'msisdn'=>'233244708005', 'playerId'=>1];
    $resp = handle_PLAY_AGAIN('2', $s);
    br_assert_equals(false, $resp['continue']);
    br_assert(str_contains($resp['message'], 'Thanks for playing'), 'thanks');
});

echo "\n  Main menu routing — play\n";
$r->run('input 1 → PLAY_PICK_TYPE', function () {
    $s = []; $resp = handle_MAIN_MENU('1', $s);
    br_assert_equals('PLAY_PICK_TYPE', $resp['nextState']);
});

echo "\n  E2E play round\n";
$r->run('full flow: menu → type → colors → stake → confirm → result', function () {
    $pid = seedPlayer('233244709001', 'E2E PLAY');
    setBalance($pid, 'PLAY', 10000);
    seedSummary(50000, 0, 100, 1);

    $s = ['msisdn'=>'233244709001', 'playerId'=>$pid, 'data'=>[], 'state'=>'MAIN_MENU'];

    $r1 = handle_MAIN_MENU('1', $s);
    br_assert_equals('PLAY_PICK_TYPE', $r1['nextState']);
    $s['state'] = 'PLAY_PICK_TYPE';

    renderState('PLAY_PICK_TYPE', $s);
    $r2 = handle_PLAY_PICK_TYPE('2', $s);  // 2-card game
    br_assert_equals('PLAY_PICK_COLORS', $r2['nextState']);
    br_assert_equals(2, $s['data']['playGameType']);
    $s['state'] = 'PLAY_PICK_COLORS';

    renderState('PLAY_PICK_COLORS', $s);
    $r3 = handle_PLAY_PICK_COLORS('RB', $s);
    br_assert_equals('PLAY_ENTER_STAKE', $r3['nextState']);
    br_assert_equals(['red','black'], $s['data']['playColorPicks']);
    $s['state'] = 'PLAY_ENTER_STAKE';

    renderState('PLAY_ENTER_STAKE', $s);
    $r4 = handle_PLAY_ENTER_STAKE('10', $s);
    br_assert_equals('PLAY_CONFIRM', $r4['nextState']);
    $s['state'] = 'PLAY_CONFIRM';

    renderState('PLAY_CONFIRM', $s);
    $r5 = handle_PLAY_CONFIRM('1', $s);
    br_assert_equals('PLAY_AGAIN', $r5['nextState']);

    // Round executed: stake 1000 debited (net neg → loss)
    $bal = playerBalances($pid);
    br_assert_equals(9000, $bal['play'], 'stake debited');

    // Result screen present
    $resultRender = renderState('PLAY_AGAIN', $s);
    br_assert(str_contains($resultRender['message'], 'Play Again'), 'play again offered');
});

exit($r->summary());
