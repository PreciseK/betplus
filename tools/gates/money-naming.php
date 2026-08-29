<?php

declare(strict_types=1);

/**
 * Money naming and typing gate — project-context rules 1 and 3, REQ-WAL-003.
 *
 * Three rules, each of which has caused a real defect somewhere:
 *
 *   1. Every money identifier ends in Kobo or _kobo. A bare `$amount` tells the next reader
 *      nothing about its unit, and the unit is the whole problem: OPay payout *requests* carry
 *      kobo while payout *callbacks* carry Naira. A field named for its unit makes that
 *      mismatch visible at the point of use instead of at reconciliation.
 *
 *   2. No `*Pesewas` identifier survives. The system being replaced denominated in pesewas
 *      throughout; carrying one across is how a market migration silently half-happens.
 *
 *   3. No float arithmetic on money. `floatval`, `(float)` and `round()` applied to a money
 *      identifier are all rejected — integers only, everywhere.
 *
 * Usage: php tools/gates/money-naming.php
 * Exit:  0 = clean · 1 = violation found
 */

require __DIR__ . '/_walk.php';

const ROOT = __DIR__ . '/../..';
// Resolved once — see prohibited-randomness.php for why this matters on Windows.
$GLOBALS['__ROOT_REAL'] = realpath(ROOT);

/**
 * Identifiers that clearly hold money but carry no unit. Deliberately conservative: this list
 * only contains words that are unambiguous in this domain.
 */
const MONEY_WORDS = [
    'stake', 'prize', 'payout', 'balance', 'deposit', 'withdrawal',
    'winnings', 'amount', 'fee', 'levy', 'mdr', 'float',
];

/** Suffixes that make a money identifier acceptable. */
const OK_SUFFIX = ['kobo', 'Kobo', '_kobo'];

/**
 * Words that look like money but are not — counts, rates, flags, identifiers, statuses.
 * A rate is a ratio, not an amount; a count is a number of things.
 */
const NOT_MONEY = [
    'count', 'rate', 'pct', 'percent', 'ratio', 'status', 'id', 'ref', 'type',
    'method', 'enabled', 'at', 'by', 'version', 'name', 'code', 'multiplier',
    'threshold', 'tier', 'limit', 'key', 'label', 'text', 'message', 'currency',
];

$violations = [];

function rel(string $p): string
{
    return ltrim(str_replace('\\', '/', substr($p, strlen($GLOBALS['__ROOT_REAL']))), '/');
}

function skip(string $path): bool
{
    $p = str_replace('\\', '/', $path);
    // The system being replaced is reference material, not shipped code.
    if (str_starts_with(rel($path), 'BlackRed/')) {
        return true;
    }
    // This gate describes the rules, so it necessarily contains the words it bans.
    return str_contains($p, 'tools/gates/');
}

function looksLikeMoney(string $ident): bool
{
    $l = strtolower($ident);
    foreach (NOT_MONEY as $n) {
        if (str_ends_with($l, $n)) {
            return false;
        }
    }
    foreach (MONEY_WORDS as $w) {
        if (str_contains($l, $w)) {
            return true;
        }
    }
    return false;
}

function hasUnit(string $ident): bool
{
    $l = strtolower($ident);
    return str_contains($l, 'kobo');
}

foreach (gateWalk(ROOT) as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || skip($path)) {
        continue;
    }

    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['php', 'py', 'ts', 'tsx'], true)) {
        continue;
    }

    $r = rel($path);
    $lines = file($path);

    foreach ($lines as $i => $line) {
        $n = $i + 1;
        $bare = preg_replace('~//.*$|#.*$~', '', $line) ?? $line;

        // Rule 2 — no pesewas anywhere.
        if (stripos($bare, 'pesewas') !== false) {
            $violations[] = sprintf('%s:%d  "pesewas" — this platform denominates in kobo (REQ-WAL-003)', $r, $n);
        }

        // Rule 1 — money identifiers carry their unit.
        if (preg_match_all('/\$?([a-zA-Z_][a-zA-Z0-9_]{2,})\s*(?:=[^=]|:)/', $bare, $m)) {
            foreach ($m[1] as $ident) {
                if (looksLikeMoney($ident) && !hasUnit($ident)) {
                    $violations[] = sprintf(
                        '%s:%d  "%s" holds money but carries no unit — name it %sKobo',
                        $r,
                        $n,
                        $ident,
                        $ident
                    );
                }
            }
        }

        // Rule 3 — no float arithmetic on money.
        if (preg_match('/\b(floatval|\(float\)|\(double\))\s*\(?\$?\w*[Kk]obo/', $bare)
            || preg_match('/\bround\s*\(\s*\$?\w*[Kk]obo/', $bare)) {
            $violations[] = sprintf('%s:%d  float arithmetic on a money value — integers only (REQ-WAL-003)', $r, $n);
        }
    }
}

$violations = array_values(array_unique($violations));

if ($violations === []) {
    echo "money-naming: OK — every money identifier carries its unit, no pesewas, no float maths.\n";
    exit(0);
}

fwrite(STDERR, 'money-naming: ' . count($violations) . " violation(s)\n\n");
foreach ($violations as $v) {
    fwrite(STDERR, "  {$v}\n");
}
fwrite(
    STDERR,
    "\n  If a value is money it is an integer number of kobo and its name says so. If one of these\n" .
    "  is a false positive, rename it so it is not ambiguous to a reader either.\n"
);
exit(1);
