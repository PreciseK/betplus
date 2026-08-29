<?php

declare(strict_types=1);

/**
 * USSD screen-length gate — REQ-QA-013.
 *
 * Every USSD screen, in every locale, is 160 characters or fewer including the prompt. This is
 * a hard protocol limit, not a style preference, and it applies most awkwardly to Nigerian
 * Pidgin, which routinely runs longer than the English source for the same meaning.
 *
 * SCREEN CONVENTION (authoritative — Epic 8 builds against this):
 *
 *   Every screen lives in its own file under apps/ussd/app/Screens/, and returns an array
 *   keyed by locale code, each value the exact string sent to the gateway for that screen:
 *
 *     <?php
 *     return [
 *         'en'  => "Welcome to Betplus!\n1. Register\n2. How to play",
 *         'pcm' => "Welcome to Betplus!\n1. Register\n2. How e take work",
 *     ];
 *
 *   Locale codes: 'en' (English), 'pcm' (Nigerian Pidgin, ISO 639-3). Both keys are required —
 *   a screen shipped in English only is caught below, since REQ-NOT... / the localisation
 *   requirement is that Pidgin ships alongside English from launch, not follows it.
 *
 *   Length is measured as mb_strlen() in UTF-8 — displayed characters, not bytes. If the
 *   contracted USSD aggregator turns out to enforce a byte-based (GSM 03.38) limit instead,
 *   that changes this gate's measurement, not its threshold; flag it against OQ tracking if
 *   discovered, do not silently switch the arithmetic.
 *
 * Usage: php tools/gates/ussd-screen-length.php
 * Exit:  0 = clean (or nothing to check yet) · 1 = violation found
 */

require __DIR__ . '/_walk.php';

const ROOT = __DIR__ . '/../..';
const SCREENS_DIR = ROOT . '/apps/ussd/app/Screens';
const MAX_CHARS = 160;
const REQUIRED_LOCALES = ['en', 'pcm'];

$screensPath = realpath(SCREENS_DIR);

if ($screensPath === false) {
    echo "ussd-screen-length: no Screens/ directory yet — nothing to check (Epic 8 not started).\n";
    exit(0);
}

$violations = [];
$screenCount = 0;

foreach (gateWalk($screensPath) as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(realpath(ROOT)))), '/');

    // Execute in an isolated scope. Screen files are pure data (a returned array), never
    // executable logic, so evaluating them is safe and far more reliable than parsing PHP
    // source for string literals by hand.
    $screen = (static fn (string $__path) => require $__path)($file->getPathname());

    if (!is_array($screen)) {
        $violations[] = sprintf('%s  does not return an array — see the convention in this gate\'s docblock', $rel);
        continue;
    }

    $screenCount++;

    foreach (REQUIRED_LOCALES as $locale) {
        if (!array_key_exists($locale, $screen)) {
            $violations[] = sprintf('%s  missing locale "%s" — both en and pcm ship together', $rel, $locale);
            continue;
        }

        $text = $screen[$locale];
        if (!is_string($text)) {
            $violations[] = sprintf('%s  locale "%s" is not a string', $rel, $locale);
            continue;
        }

        $len = mb_strlen($text, 'UTF-8');
        if ($len > MAX_CHARS) {
            $violations[] = sprintf(
                '%s  locale "%s" is %d characters (limit %d): "%s%s"',
                $rel,
                $locale,
                $len,
                MAX_CHARS,
                mb_substr($text, 0, 40, 'UTF-8'),
                $len > 40 ? '…' : ''
            );
        }
    }
}

if ($violations === []) {
    printf("ussd-screen-length: OK — %d screen(s) checked, all within %d characters in every locale.\n", $screenCount, MAX_CHARS);
    exit(0);
}

fwrite(STDERR, 'ussd-screen-length: ' . count($violations) . " violation(s)\n\n");
foreach ($violations as $v) {
    fwrite(STDERR, "  {$v}\n");
}
fwrite(
    STDERR,
    "\n  A USSD screen that overflows does not wrap — it is truncated or rejected by the\n" .
    "  gateway. Shorten the copy; do not petition for a longer limit.\n"
);
exit(1);
