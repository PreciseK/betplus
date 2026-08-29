<?php

declare(strict_types=1);

/**
 * Prohibited randomness gate — REQ-RNG-008, REQ-RNG-009, REQ-QA-005.
 *
 * Fails the build when a predictable random source appears anywhere in a game or money path,
 * or when either game engine generates randomness at all.
 *
 * Two separate rules are enforced here:
 *
 *   1. PHP's `rand()`, `mt_rand()`, `shuffle()`, `array_rand()` and `str_shuffle()` are banned
 *      platform-wide. `mt_rand` is a Mersenne Twister: observe 624 outputs and every subsequent
 *      value is predictable. In a game whose outcome is money, that is the whole system.
 *
 *   2. Engines must not generate randomness *at all*, cryptographic or otherwise. A seed comes
 *      from the platform Fairness Service (REQ-GEC-003). An engine that calls `random_bytes()`
 *      is no longer a pure function of its inputs and cannot be replayed (REQ-GEC-001).
 *
 * Usage: php tools/gates/prohibited-randomness.php
 * Exit:  0 = clean · 1 = violation found
 */

require __DIR__ . '/_walk.php';

const ROOT = __DIR__ . '/../..';
// Resolved once. Calling realpath() inside the per-file loop below cost a filesystem stat per
// call and was the actual source of a multi-minute runtime on Windows.
$GLOBALS['__ROOT_REAL'] = realpath(ROOT);

/** Banned everywhere. Predictable generators. */
const BANNED_PHP = ['rand', 'mt_rand', 'shuffle', 'array_rand', 'str_shuffle', 'mt_srand', 'srand', 'lcg_value'];

/** Allowed in the platform (the Fairness Service needs them) but never inside an engine. */
const ENGINE_BANNED_PHP = ['random_int', 'random_bytes', 'openssl_random_pseudo_bytes', 'uniqid'];

const ENGINE_BANNED_PY = ['import random', 'from random import', 'random.', 'secrets.', 'os.urandom'];

/** Paths that are engines — the stricter rule applies. */
const ENGINE_PATHS = ['apps/engine-blackred', 'apps/engine-heritage'];

/** Never scanned. */
$violations = [];

function isEngine(string $path): bool
{
    $p = str_replace('\\', '/', $path);
    foreach (ENGINE_PATHS as $e) {
        if (str_contains($p, $e)) {
            return true;
        }
    }
    return false;
}

/** Strip comments and string literals so a mention in prose is not a violation. */
function strippedPhp(string $code): array
{
    $out = [];
    $tokens = @token_get_all($code);
    foreach ($tokens as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $out[] = [$t[1], $t[2]];
        }
    }
    return $out;
}

foreach (gateWalk(ROOT) as $file) {
    $path = $file->getPathname();
    if (!$file->isFile()) {
        continue;
    }

    $ext = strtolower($file->getExtension());
    $rel = ltrim(str_replace('\\', '/', substr($path, strlen($GLOBALS['__ROOT_REAL']))), '/');

    // The system being replaced is read-only reference material, not code we ship.
    if (str_starts_with($rel, 'BlackRed/')) {
        continue;
    }

    if ($ext === 'php') {
        $code = file_get_contents($path);
        $banned = BANNED_PHP;
        if (isEngine($path)) {
            $banned = array_merge($banned, ENGINE_BANNED_PHP);
        }

        foreach (strippedPhp($code) as [$text, $line]) {
            foreach ($banned as $fn) {
                if (strcasecmp($text, $fn) === 0) {
                    $why = in_array($fn, ENGINE_BANNED_PHP, true)
                        ? 'engines receive a seed from the Fairness Service and generate no randomness (REQ-GEC-003)'
                        : 'predictable generator, prohibited in any game or money path (REQ-RNG-008)';
                    $violations[] = sprintf('%s:%d  %s()  — %s', $rel, $line, $fn, $why);
                }
            }
        }
    }

    if ($ext === 'py' && isEngine($path)) {
        foreach (file($path) as $i => $line) {
            $bare = preg_replace('/#.*$/', '', $line);
            foreach (ENGINE_BANNED_PY as $needle) {
                if (str_contains($bare, $needle)) {
                    $violations[] = sprintf(
                        '%s:%d  %s — the Heritage engine generates no randomness (REQ-RNG-009)',
                        $rel,
                        $i + 1,
                        trim($needle)
                    );
                }
            }
        }
    }
}

if ($violations === []) {
    echo "prohibited-randomness: OK — no predictable or engine-side random source found.\n";
    exit(0);
}

fwrite(STDERR, "prohibited-randomness: " . count($violations) . " violation(s)\n\n");
foreach ($violations as $v) {
    fwrite(STDERR, "  {$v}\n");
}
fwrite(
    STDERR,
    "\n  Use the platform Fairness Service. If you need randomness outside the game and money\n" .
    "  paths, that is a conversation to have explicitly, not a suppression to add here.\n"
);
exit(1);
