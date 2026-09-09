# Caged USSD Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Caged (Option B "Escape Count") as a third instant-resolve ticket game on `apps/platform`, exposed over `apps/ussd`, and remove USSD-initiated deposits platform-wide.

**Architecture:** Caged mirrors BlackRed's existing shape exactly — a pure `CagedEngine` under `Domain/Games/Engine/Caged`, a `CreateCagedTicket` domain service running the same two-phase eligibility/commit pipeline as `CreateTicket`, a `CagedController` on three new `/v1` routes, and a seeder. It reuses the existing generic `PrizeTable`/`prizeTableTier` tables and the existing generic `PrizeTableResolver` — no new migration or resolver class. On `apps/ussd`, `MenuEngine` gains three new screens (`caged_pick`/`caged_stake`/`caged_confirm`) and loses the standalone "Fund account" flow plus the BlackRed/Heritage in-flow OTP top-up fallback, since deposits now go directly through the OPay app.

**Tech Stack:** PHP 8.x, Laravel (apps/platform), plain PHP + PHPUnit (apps/ussd), PHPUnit (both).

**Spec:** `docs/superpowers/specs/2026-09-09-caged-ussd-engine-design.md`

## Global Constraints

- Option B ("Escape Count") only — no live-round/target-multiplier flow (Option A) is built.
- USSD-initiated deposits are removed everywhere in `apps/ussd`: no screen or `PlatformClientInterface` method may call the platform's deposit-quote/create-deposit/deposit-OTP endpoints. `apps/platform`'s `/v1/wallet/deposits*` routes are untouched.
- Caged `GameRegistry`: `minStakeKobo = 10_000` (NGN 100), `maxStakeKobo = 2_000_000` (NGN 20,000) — same as BlackRed/Heritage.
- Caged tier table (cumulative "P(escaped ≥ targetBirds)" / multiplier, exact values from the design spec §3-4): target 1 → 7180/10000 / 1.25x (125 hundredths); target 2 → 4620/10000 / 1.90x (190); target 3 → 2310/10000 / 3.80x (380); target 4 → 1140/10000 / 7.50x (750); target 5 → 480/10000 / 18.00x (1800).
- RTP ceiling: 9500 basis points (95%), gross and net-of-withholding, enforced by `CagedPrizeTablePublicationGate` — same ceiling every other game's gate uses.
- `App\Domain\Games\Engine\Caged\*` must import no `Illuminate\`/Eloquent/`DB::`/`PDO` and call no `rand()`/`mt_rand()`/`shuffle()`/`array_rand()`/`str_shuffle()` — already enforced automatically by the existing `tests/Unit/EnginePurityTest.php`, which recursively scans the whole `Engine` directory; no test change needed, just don't violate it.
- USSD main menu after this work: `1. BlackRed  2. Heritage  3. Caged  4. Responsible play  0. Exit` — RG stays on digit `4` (unchanged from today), Caged takes the digit `3` that "Fund account" used to occupy.
- Every new/changed USSD screen string must stay ≤160 characters including the `CON `/`END ` envelope prefix (`CharacterLimit::fits()`).

---

## Task 1: `CagedEngine` — pure outcome resolution

**Files:**
- Create: `apps/platform/app/Domain/Games/Engine/Caged/CagedTier.php`
- Create: `apps/platform/app/Domain/Games/Engine/Caged/CagedEngineResult.php`
- Create: `apps/platform/app/Domain/Games/Engine/Caged/Digest.php`
- Create: `apps/platform/app/Domain/Games/Engine/Caged/CagedEngine.php`
- Test: `apps/platform/tests/Unit/CagedEngineTest.php`

**Interfaces:**
- Consumes: nothing (pure, no dependencies on earlier tasks).
- Produces: `CagedEngine::resolve(string $seedHex, int $targetBirds, int $stakeKobo, array $tiers): CagedEngineResult` and `::replay(...)` (same signature) — consumed by Task 4's `CreateCagedTicket`. `CagedTier(int $targetBirds, int $probabilityNumerator, int $probabilityDenominator, int $multiplierHundredths)` — consumed by Task 4. `CagedEngineResult` has public readonly `escapedBirds`, `targetBirds`, `won`, `grossPrizeKobo`, `digest`, `engineVersion` — consumed by Task 4.

- [ ] **Step 1: Write the failing test file**

Create `apps/platform/tests/Unit/CagedEngineTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Engine\Caged\CagedEngine;
use App\Domain\Games\Engine\Caged\CagedTier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CagedEngineTest extends TestCase
{
    /** @return list<CagedTier> */
    private function tiers(): array
    {
        return [
            new CagedTier(1, 7180, 10_000, 125),
            new CagedTier(2, 4620, 10_000, 190),
            new CagedTier(3, 2310, 10_000, 380),
            new CagedTier(4, 1140, 10_000, 750),
            new CagedTier(5, 480, 10_000, 1800),
        ];
    }

    public function test_resolve_and_replay_are_byte_identical_for_the_same_inputs(): void
    {
        $engine = new CagedEngine();
        $seed = bin2hex(random_bytes(32));

        $resolved = $engine->resolve($seed, 2, 100_000, $this->tiers());
        $replayed = $engine->replay($seed, 2, 100_000, $this->tiers());

        $this->assertSame($resolved->escapedBirds, $replayed->escapedBirds);
        $this->assertSame($resolved->won, $replayed->won);
        $this->assertSame($resolved->grossPrizeKobo, $replayed->grossPrizeKobo);
        $this->assertSame($resolved->digest, $replayed->digest);
    }

    public function test_different_seeds_produce_different_outcomes_with_overwhelming_probability(): void
    {
        $engine = new CagedEngine();
        $results = [];
        for ($i = 0; $i < 20; $i++) {
            $results[] = $engine->resolve(bin2hex(random_bytes(32)), 3, 100_000, $this->tiers())->escapedBirds;
        }

        $this->assertGreaterThan(1, count(array_unique($results)), 'Twenty random seeds produced an identical escaped-birds outcome every time.');
    }

    public function test_won_is_exactly_escaped_birds_meeting_or_exceeding_the_target(): void
    {
        $engine = new CagedEngine();
        for ($i = 0; $i < 100; $i++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), 3, 100_000, $this->tiers());
            $this->assertSame($result->escapedBirds >= 3, $result->won);
        }
    }

    public function test_gross_prize_is_stake_times_tier_multiplier_on_a_win(): void
    {
        $engine = new CagedEngine();
        do {
            $seed = bin2hex(random_bytes(32));
            $result = $engine->resolve($seed, 1, 100_000, $this->tiers());
        } while (!$result->won);

        $this->assertSame(125_000, $result->grossPrizeKobo); // 100_000 * 1.25
    }

    public function test_a_loss_pays_nothing(): void
    {
        $engine = new CagedEngine();
        do {
            $seed = bin2hex(random_bytes(32));
            $result = $engine->resolve($seed, 5, 100_000, $this->tiers());
        } while ($result->won);

        $this->assertSame(0, $result->grossPrizeKobo);
    }

    public function test_escaped_birds_is_always_between_zero_and_five(): void
    {
        $engine = new CagedEngine();
        for ($i = 0; $i < 200; $i++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), 1, 100_000, $this->tiers());
            $this->assertGreaterThanOrEqual(0, $result->escapedBirds);
            $this->assertLessThanOrEqual(5, $result->escapedBirds);
        }
    }

    public function test_the_escaped_birds_distribution_roughly_matches_the_configured_tier_probabilities(): void
    {
        $engine = new CagedEngine();
        $counts = array_fill(0, 6, 0);
        $samples = 4000;
        for ($i = 0; $i < $samples; $i++) {
            $result = $engine->resolve(bin2hex(random_bytes(32)), 1, 100_000, $this->tiers());
            $counts[$result->escapedBirds]++;
        }

        // Exact-count widths derived from the cumulative tier probabilities (design
        // spec §3): 28.20/25.60/23.10/11.70/6.60/4.80%.
        $expectedFractions = [0 => 0.2820, 1 => 0.2560, 2 => 0.2310, 3 => 0.1170, 4 => 0.0660, 5 => 0.0480];
        foreach ($expectedFractions as $escapedBirds => $expectedFraction) {
            $observedFraction = $counts[$escapedBirds] / $samples;
            $this->assertEqualsWithDelta(
                $expectedFraction,
                $observedFraction,
                0.04,
                "escapedBirds=$escapedBirds observed frequency $observedFraction too far from expected $expectedFraction",
            );
        }
    }

    public function test_rejects_a_target_below_one(): void
    {
        $engine = new CagedEngine();
        $this->expectException(InvalidArgumentException::class);
        $engine->resolve(bin2hex(random_bytes(32)), 0, 100_000, $this->tiers());
    }

    public function test_rejects_a_target_above_five(): void
    {
        $engine = new CagedEngine();
        $this->expectException(InvalidArgumentException::class);
        $engine->resolve(bin2hex(random_bytes(32)), 6, 100_000, $this->tiers());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run (from `apps/platform`): `vendor/bin/phpunit tests/Unit/CagedEngineTest.php`
Expected: FAIL — `Class "App\Domain\Games\Engine\Caged\CagedEngine" not found`.

- [ ] **Step 3: Create `CagedTier`**

Create `apps/platform/app/Domain/Games/Engine/Caged/CagedTier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Caged;

/**
 * Plain input to the engine — deliberately not an Eloquent model (REQ-GEC-002),
 * mirroring BlackRed\EngineTier. probabilityNumerator/Denominator is the
 * CUMULATIVE win probability P(escapedBirds >= targetBirds), not an exact-count
 * width — see the design spec §3 for why, and CagedEngine::escapedBirdsFor()
 * for how the engine derives its internal buckets from these five values.
 */
final readonly class CagedTier
{
    public function __construct(
        public int $targetBirds,
        public int $probabilityNumerator,
        public int $probabilityDenominator,
        public int $multiplierHundredths,
    ) {
    }
}
```

- [ ] **Step 4: Create `CagedEngineResult`**

Create `apps/platform/app/Domain/Games/Engine/Caged/CagedEngineResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Caged;

/** resolve/replay response shape, as a plain value object — mirrors BlackRed\EngineResult. */
final readonly class CagedEngineResult
{
    public function __construct(
        public int $escapedBirds,
        public int $targetBirds,
        public bool $won,
        public int $grossPrizeKobo,
        public string $digest,
        public string $engineVersion,
    ) {
    }
}
```

- [ ] **Step 5: Create `Digest`**

Create `apps/platform/app/Domain/Games/Engine/Caged/Digest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Caged;

/** sha256 of the canonical outcome, returned alongside every resolve/replay. */
final class Digest
{
    public static function of(string $seedHex, int $targetBirds, int $escapedBirds): string
    {
        return hash('sha256', $seedHex . '|' . $targetBirds . '|' . $escapedBirds);
    }
}
```

- [ ] **Step 6: Create `CagedEngine`**

Create `apps/platform/app/Domain/Games/Engine/Caged/CagedEngine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Engine\Caged;

use InvalidArgumentException;
use RuntimeException;

/**
 * A pure function of (seed, targetBirds, stake, tiers): no database, no external
 * call, no filesystem write, no internally generated randomness — same purity
 * contract as BlackRedEngine (REQ-GEC-001/002/003), swept by the same
 * tests/Unit/EnginePurityTest.php. One fresh seed is issued per TICKET (see
 * CreateCagedTicket), matching BlackRedEngine's per-ticket-seed shape rather
 * than BirdEscapeEngine's per-round shape.
 */
final class CagedEngine
{
    public const VERSION = 'caged-1.0.0';

    /** @param list<CagedTier> $tiers exactly 5 tiers, targetBirds 1..5, no gaps */
    public function resolve(string $seedHex, int $targetBirds, int $stakeKobo, array $tiers): CagedEngineResult
    {
        if ($targetBirds < 1 || $targetBirds > 5) {
            throw new InvalidArgumentException('targetBirds must be between 1 and 5.');
        }

        $tier = $this->tierFor($tiers, $targetBirds);

        $seed = hex2bin($seedHex);
        if ($seed === false) {
            throw new InvalidArgumentException('seedHex must be valid hex.');
        }

        $digest = hash_hmac('sha256', 'CAGED', $seed, true);
        $unpacked = unpack('N', substr($digest, 0, 4));
        $h = $unpacked[1]; // uint32, 0..4294967295

        // Normalized uniform draw [0.0, 1.0) — same normalization style as
        // BirdEscapeEngine::resolve().
        $r = ($h % 100_000) / 100_000.0;

        $escapedBirds = $this->escapedBirdsFor($r, $tiers);
        $won = $escapedBirds >= $targetBirds;
        $grossPrizeKobo = $won ? intdiv($stakeKobo * $tier->multiplierHundredths, 100) : 0;

        return new CagedEngineResult(
            escapedBirds: $escapedBirds,
            targetBirds: $targetBirds,
            won: $won,
            grossPrizeKobo: $grossPrizeKobo,
            digest: Digest::of($seedHex, $targetBirds, $escapedBirds),
            engineVersion: self::VERSION,
        );
    }

    /** Same inputs, byte-identical output — resolve() is already pure. */
    public function replay(string $seedHex, int $targetBirds, int $stakeKobo, array $tiers): CagedEngineResult
    {
        return $this->resolve($seedHex, $targetBirds, $stakeKobo, $tiers);
    }

    /** @param list<CagedTier> $tiers */
    private function tierFor(array $tiers, int $targetBirds): CagedTier
    {
        foreach ($tiers as $tier) {
            if ($tier->targetBirds === $targetBirds) {
                return $tier;
            }
        }

        throw new RuntimeException("No prize table tier configured for target $targetBirds birds.");
    }

    /**
     * Buckets the uniform draw into an escaped-bird count (0-5) using the
     * boundaries implied by the tiers' cumulative "at least N birds"
     * probabilities — see the design spec §3 for the derivation. cumulative[0]
     * (100%) and cumulative[6] (0%) are the fixed edges of the distribution;
     * cumulative[1..5] come from the tiers, so there is nothing configured
     * separately that could drift out of sync with them.
     *
     * @param list<CagedTier> $tiers
     */
    private function escapedBirdsFor(float $r, array $tiers): int
    {
        $cumulative = [0 => 1.0, 6 => 0.0];
        foreach ($tiers as $tier) {
            $cumulative[$tier->targetBirds] = $tier->probabilityNumerator / $tier->probabilityDenominator;
        }
        ksort($cumulative);

        for ($escapedBirds = 0; $escapedBirds <= 5; $escapedBirds++) {
            $lower = 1 - $cumulative[$escapedBirds];
            $upper = 1 - $cumulative[$escapedBirds + 1];
            if ($r >= $lower && $r < $upper) {
                return $escapedBirds;
            }
        }

        // Floating-point edge as $r approaches 1.0 — falls in the top bucket.
        return 5;
    }

    /** @return array{gameCode:string,engineVersion:string} */
    public function describe(): array
    {
        return [
            'gameCode' => 'CAGED',
            'engineVersion' => self::VERSION,
        ];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/CagedEngineTest.php`
Expected: PASS (9 tests).

- [ ] **Step 8: Run the engine-purity test to confirm the new files don't violate it**

Run: `vendor/bin/phpunit tests/Unit/EnginePurityTest.php`
Expected: PASS — `CagedEngine`/`CagedTier`/`CagedEngineResult`/`Digest` use no `Illuminate\`/Eloquent/`DB::`/`PDO` and no prohibited randomness function.

- [ ] **Step 9: Commit**

```bash
git add apps/platform/app/Domain/Games/Engine/Caged apps/platform/tests/Unit/CagedEngineTest.php
git commit -m "feat: add pure CagedEngine for Escape Count outcome resolution"
```

---

## Task 2: `CagedPrizeTablePublicationGate`

**Files:**
- Create: `apps/platform/app/Domain/Games/PrizeTable/CagedPrizeTablePublicationGate.php`
- Test: `apps/platform/tests/Feature/CagedPrizeTablePublicationGateTest.php`

**Interfaces:**
- Consumes: `App\Models\PrizeTable` (existing model, `tiers()` relation → `PrizeTableTier` rows with `positions`, `probabilityNumerator`, `probabilityDenominator`, `multiplierHundredths`).
- Produces: `CagedPrizeTablePublicationGate::validate(PrizeTable $table, int $withholdingRateBasisPoints): array` (list of error strings, empty = passes) — consumed by Task 3's seeder.

- [ ] **Step 1: Write the failing test file**

Create `apps/platform/tests/Feature/CagedPrizeTablePublicationGateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\PrizeTable\CagedPrizeTablePublicationGate;
use App\Models\PrizeTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CagedPrizeTablePublicationGateTest extends TestCase
{
    use RefreshDatabase;

    private function table(array $tiers, ?string $actuarialCertRef = 'CERT-1'): PrizeTable
    {
        $table = PrizeTable::create([
            'gameCode' => 'CAGED', 'stateCode' => null, 'version' => 'test',
            'status' => 'draft', 'effectiveAt' => now(), 'actuarialCertRef' => $actuarialCertRef,
        ]);
        foreach ($tiers as $tier) {
            $table->tiers()->create($tier);
        }

        return $table->fresh(['tiers']);
    }

    private function launchTiers(): array
    {
        return [
            ['positions' => 1, 'multiplierHundredths' => 125, 'probabilityNumerator' => 7180, 'probabilityDenominator' => 10_000],
            ['positions' => 2, 'multiplierHundredths' => 190, 'probabilityNumerator' => 4620, 'probabilityDenominator' => 10_000],
            ['positions' => 3, 'multiplierHundredths' => 380, 'probabilityNumerator' => 2310, 'probabilityDenominator' => 10_000],
            ['positions' => 4, 'multiplierHundredths' => 750, 'probabilityNumerator' => 1140, 'probabilityDenominator' => 10_000],
            ['positions' => 5, 'multiplierHundredths' => 1800, 'probabilityNumerator' => 480, 'probabilityDenominator' => 10_000],
        ];
    }

    public function test_the_launch_table_passes_with_no_errors(): void
    {
        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($this->launchTiers()), 500);

        $this->assertSame([], $errors);
    }

    public function test_it_rejects_a_missing_tier(): void
    {
        $tiers = array_slice($this->launchTiers(), 0, 4); // drop target 5
        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($tiers), 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Missing tier for target 5 birds', implode(' ', $errors));
    }

    public function test_it_rejects_a_tier_probability_that_does_not_match_the_documented_value(): void
    {
        $tiers = $this->launchTiers();
        $tiers[0]['probabilityNumerator'] = 7000; // should be 7180

        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($tiers), 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not match the documented', implode(' ', $errors));
    }

    public function test_it_accepts_the_launch_table_rtp_under_the_ceiling(): void
    {
        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($this->launchTiers()), 500);

        $this->assertSame([], $errors);
    }

    public function test_it_rejects_a_table_whose_gross_rtp_exceeds_the_ceiling(): void
    {
        $tiers = $this->launchTiers();
        $tiers[4]['multiplierHundredths'] = 40_000; // an absurdly rich 5-bird multiplier

        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($tiers), 500);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ceiling', implode(' ', $errors));
    }

    public function test_it_rejects_a_table_with_no_actuarial_certification_reference(): void
    {
        $errors = app(CagedPrizeTablePublicationGate::class)->validate($this->table($this->launchTiers(), null), 500);

        $this->assertContains('No actuarial certification reference recorded (REQ-GEC-025).', $errors);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/CagedPrizeTablePublicationGateTest.php`
Expected: FAIL — `Class "App\Domain\Games\PrizeTable\CagedPrizeTablePublicationGate" not found`.

- [ ] **Step 3: Write the gate**

Create `apps/platform/app/Domain/Games/PrizeTable/CagedPrizeTablePublicationGate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\PrizeTable;

use App\Models\PrizeTable;

/**
 * Caged's counterpart to PrizeTablePublicationGate/HeritagePrizeTablePublicationGate.
 * Reuses the generic prizeTableTier table (positions column holds targetBirds,
 * 1-5) — same table BlackRed uses, scoped by this table's own prizeTableId.
 *
 * Unlike BlackRed's fair-coin check, Caged's tier probabilities are empirically
 * chosen game-design frequencies (docs/caged-ussd-complete-flows.md's "Escape
 * Count" table), not something derivable from a combinatorial formula — so this
 * gate checks each tier's configured probability against the documented
 * constant directly, plus the same RTP-ceiling and actuarial-cert checks every
 * other gate in this codebase enforces.
 *
 * NOT built here, deliberately, for the same reasons as the other gates: the
 * real maker-checker publication workflow, the Monte Carlo validation run, and
 * a real actuarial certification.
 */
final class CagedPrizeTablePublicationGate
{
    private const RTP_CEILING_BASIS_POINTS = 9500; // 95%, same ceiling as every other gate

    /** targetBirds => [numerator, denominator] — the documented cumulative "at least N birds" frequencies. */
    private const EXPECTED_PROBABILITIES = [
        1 => [7180, 10_000],
        2 => [4620, 10_000],
        3 => [2310, 10_000],
        4 => [1140, 10_000],
        5 => [480, 10_000],
    ];

    /** @return list<string> validation errors; empty means the table may publish */
    public function validate(PrizeTable $table, int $withholdingRateBasisPoints): array
    {
        $errors = [];
        $tiers = $table->tiers;

        if ($tiers->isEmpty()) {
            return ['Prize table has no tiers.'];
        }

        $seenTargets = [];
        foreach ($tiers as $tier) {
            $seenTargets[] = $tier->positions;

            $expected = self::EXPECTED_PROBABILITIES[$tier->positions] ?? null;
            if ($expected === null) {
                $errors[] = "Tier {$tier->positions}: not a valid Caged target (must be 1-5).";
                continue;
            }

            [$expectedNumerator, $expectedDenominator] = $expected;
            $actualFraction = $tier->probabilityNumerator / $tier->probabilityDenominator;
            $expectedFraction = $expectedNumerator / $expectedDenominator;
            if (abs($actualFraction - $expectedFraction) > 0.00005) {
                $errors[] = "Tier {$tier->positions}: probability {$tier->probabilityNumerator}/{$tier->probabilityDenominator} does not match the documented {$expectedNumerator}/{$expectedDenominator}.";
            }
        }

        foreach (array_keys(self::EXPECTED_PROBABILITIES) as $target) {
            if (!in_array($target, $seenTargets, true)) {
                $errors[] = "Missing tier for target $target birds.";
            }
        }

        foreach ($tiers as $tier) {
            $probability = $tier->probabilityNumerator / $tier->probabilityDenominator;
            $grossRtpBasisPoints = (int) round($probability * $tier->multiplierHundredths * 100);
            $netRtpBasisPoints = (int) round($grossRtpBasisPoints * (10_000 - $withholdingRateBasisPoints) / 10_000);

            if ($grossRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: gross RTP {$grossRtpBasisPoints}bp exceeds the 9500bp ceiling.";
            }
            if ($netRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: net-of-WHT RTP {$netRtpBasisPoints}bp exceeds the 9500bp ceiling.";
            }
        }

        if ($table->actuarialCertRef === null) {
            $errors[] = 'No actuarial certification reference recorded (REQ-GEC-025).';
        }

        return $errors;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/CagedPrizeTablePublicationGateTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/PrizeTable/CagedPrizeTablePublicationGate.php apps/platform/tests/Feature/CagedPrizeTablePublicationGateTest.php
git commit -m "feat: add CagedPrizeTablePublicationGate"
```

---

## Task 3: `CagedGameSeeder`

**Files:**
- Create: `apps/platform/database/seeders/CagedGameSeeder.php`
- Modify: `apps/platform/database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: `CagedPrizeTablePublicationGate::validate()` (Task 2), existing `GameRegistry`, `PrizeTable`, `ExclusionRegistry`, `StateLicence` models.
- Produces: a seeded, published `gameCode = 'CAGED'` `GameRegistry` row and `PrizeTable` (with 5 tiers) — consumed by Task 4's feature tests via `$this->seed(CagedGameSeeder::class)`.

- [ ] **Step 1: Write the seeder**

Create `apps/platform/database/seeders/CagedGameSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Games\PrizeTable\CagedPrizeTablePublicationGate;
use App\Models\ExclusionRegistry;
use App\Models\GameRegistry;
use App\Models\PrizeTable;
use App\Models\StateLicence;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The minimum configuration Caged needs to be playable in dev and staging,
 * mirroring BlackRedGameSeeder's shape exactly. Tiers are the doc's "Escape
 * Count" table (docs/caged-ussd-complete-flows.md §3) — positions column
 * reused to mean targetBirds (1-5), same generic prizeTableTier shape
 * BlackRed uses (see CagedPrizeTablePublicationGate's doc comment).
 */
class CagedGameSeeder extends Seeder
{
    public function run(): void
    {
        GameRegistry::updateOrCreate(
            ['gameCode' => 'CAGED'],
            [
                'engineVersion' => 'caged-1.0.0',
                'status' => 'ACTIVE',
                'minStakeKobo' => 10_000,
                'maxStakeKobo' => 2_000_000,
                'enabledChannels' => ['web', 'app', 'ussd'],
                'enabledStates' => ['LAG'],
            ],
        );

        ExclusionRegistry::updateOrCreate(
            ['stateCode' => 'LAG'],
            ['registryName' => 'SafePlay Lagos', 'configuredAt' => now()],
        );

        StateLicence::updateOrCreate(
            ['stateCode' => 'LAG'],
            [
                'licenceNumber' => 'PENDING-LICENCE-NUMBER',
                'issuedAt' => now()->subYear(),
                'expiresAt' => now()->addYear(),
                'rulesetVersion' => (string) config('jurisdiction.ruleset_version', '2026.1'),
            ],
        );

        $table = PrizeTable::updateOrCreate(
            ['gameCode' => 'CAGED', 'stateCode' => null, 'version' => 'CG-NG-2026.1'],
            [
                'status' => 'draft',
                'effectiveAt' => now()->subDay(),
                'actuarialCertRef' => 'PENDING-ACTUARIAL-CERT — placeholder, not a real certification',
            ],
        );

        $table->tiers()->delete();
        $tiers = [
            ['positions' => 1, 'multiplierHundredths' => 125, 'probabilityNumerator' => 7180, 'probabilityDenominator' => 10_000],
            ['positions' => 2, 'multiplierHundredths' => 190, 'probabilityNumerator' => 4620, 'probabilityDenominator' => 10_000],
            ['positions' => 3, 'multiplierHundredths' => 380, 'probabilityNumerator' => 2310, 'probabilityDenominator' => 10_000],
            ['positions' => 4, 'multiplierHundredths' => 750, 'probabilityNumerator' => 1140, 'probabilityDenominator' => 10_000],
            ['positions' => 5, 'multiplierHundredths' => 1800, 'probabilityNumerator' => 480, 'probabilityDenominator' => 10_000],
        ];
        foreach ($tiers as $tier) {
            $table->tiers()->create($tier);
        }
        $table->refresh();

        $errors = app(CagedPrizeTablePublicationGate::class)->validate($table, (int) config('tax.withholding.resident_rate_basis_points'));
        // The only expected failure is the actuarial cert being a placeholder (see
        // class doc). Anything else is a genuine math error in the seeded tiers.
        $unexpected = array_values(array_filter($errors, fn (string $e) => !str_contains($e, 'actuarial')));
        if ($unexpected !== []) {
            throw new RuntimeException('Caged prize table failed the publication gate: ' . implode('; ', $unexpected));
        }

        $table->update(['status' => 'published', 'publishedAt' => now()]);
    }
}
```

- [ ] **Step 2: Register it in `DatabaseSeeder`**

Read `apps/platform/database/seeders/DatabaseSeeder.php`, then change:

```php
        $this->call(BlackRedGameSeeder::class);
        $this->call(HeritageGameSeeder::class);
        $this->call(BirdEscapeGameSeeder::class);
```

to:

```php
        $this->call(BlackRedGameSeeder::class);
        $this->call(HeritageGameSeeder::class);
        $this->call(BirdEscapeGameSeeder::class);
        $this->call(CagedGameSeeder::class);
```

- [ ] **Step 3: Run the seeder against a test database to verify it doesn't throw**

Run (from `apps/platform`): `php artisan db:seed --class="Database\Seeders\CagedGameSeeder" --env=testing`

If there's no dedicated testing DB configured for artisan directly, instead verify via a quick throwaway test — create a temporary test file, run it, then delete it:

```bash
cat > tests/Feature/_TmpCagedSeederSmokeTest.php <<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Feature;
use Database\Seeders\CagedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class _TmpCagedSeederSmokeTest extends TestCase
{
    use RefreshDatabase;
    public function test_it_seeds_without_throwing(): void
    {
        $this->seed(CagedGameSeeder::class);
        $this->assertDatabaseHas('gameRegistry', ['gameCode' => 'CAGED', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('prizeTable', ['gameCode' => 'CAGED', 'status' => 'published']);
    }
}
PHP
vendor/bin/phpunit tests/Feature/_TmpCagedSeederSmokeTest.php
rm tests/Feature/_TmpCagedSeederSmokeTest.php
```

Expected: PASS, then the temp file is removed (it is not committed — Task 4's `CagedTicketTest` covers this seeder for real).

- [ ] **Step 4: Commit**

```bash
git add apps/platform/database/seeders/CagedGameSeeder.php apps/platform/database/seeders/DatabaseSeeder.php
git commit -m "feat: add CagedGameSeeder and register it in DatabaseSeeder"
```

---

## Task 4: `CreateCagedTicket` + `CagedController` + routes — full vertical slice

**Files:**
- Create: `apps/platform/app/Domain/Ticket/CreateCagedTicket.php`
- Create: `apps/platform/app/Http/Requests/Api/V1/PurchaseCagedTicketRequest.php`
- Create: `apps/platform/app/Http/Controllers/Api/V1/CagedController.php`
- Modify: `apps/platform/routes/v1.php`
- Test: `apps/platform/tests/Feature/CagedTicketTest.php`

**Interfaces:**
- Consumes: `CagedEngine`/`CagedTier` (Task 1), `PrizeTableResolver::resolveFor(string $gameCode, string $stateCode, ?CarbonInterface $at = null): ?PrizeTable` (existing, generic), `TicketEligibilityException(string $errorCode, string $message)` (existing), `WalletService`, `TaxEngine`, `LimitsService`, `ProtectionService`, `RegistryCheckService`, `VelocityService`, `AnalyticsEventRecorder`, `AttributionService`, `SeedIssuer`, `RevealTicket` (all existing, same signatures `CreateTicket.php` already uses).
- Produces: `CreateCagedTicket::create(Player $player, int $targetBirds, int $stakeKobo, string $idempotencyKey): Ticket`. HTTP routes `GET /v1/games/caged`, `POST /v1/caged/tickets`, `GET /v1/caged/tickets/{reference}/reveal` — consumed by Task 6's USSD `PlatformClient`.

- [ ] **Step 1: Write the failing feature test file**

Create `apps/platform/tests/Feature/CagedTicketTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\WalletService;
use App\Models\Player;
use App\Models\SignupSession;
use App\Models\Ticket;
use Database\Seeders\CagedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CagedTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CagedGameSeeder::class);
        Queue::fake();
    }

    /** @return array{0: Player, 1: string} */
    private function signedInPlayer(string $msisdn = '+2348033234567', int $kycTier = 1, int $fundedKobo = 1_000_000): array
    {
        $player = Player::create([
            'msisdn' => $msisdn, 'registeredName' => 'Nkem Obi', 'registrationChannel' => 'web', 'kycTier' => $kycTier,
            'ninHash' => $kycTier >= 1 ? hash('sha256', $msisdn) : null,
        ]);
        SignupSession::create([
            'msisdn' => $msisdn,
            'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5),
            'otpVerifiedAt' => now(),
            'expiresAt' => now()->addMinutes(5),
        ]);
        $tokens = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => $msisdn])->json();

        if ($fundedKobo > 0) {
            app(WalletService::class)->creditPlayBalanceFromOpay($player, $fundedKobo, 'collection', $player->id);
        }

        return [$player, $tokens['access_token']];
    }

    public function test_the_game_descriptor_reflects_the_seeded_prize_table_and_live_balance(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 500_000);

        $response = $this->withToken($token)->getJson('/v1/games/caged');

        $response->assertOk();
        $response->assertJson([
            'game_name' => 'Caged',
            'play_balance_kobo' => 500_000,
            'currency' => 'NGN',
            'prize_table_version' => 'CG-NG-2026.1',
        ]);
        $this->assertCount(5, $response->json('tiers'));
    }

    public function test_a_purchase_response_discloses_no_outcome_information(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 2,
            'stake_kobo' => 100_000,
            'idempotency_key' => 'caged-test-purchase-1',
        ]);

        $response->assertOk();
        $body = $response->json();

        foreach (['escaped_birds', 'won', 'gross_prize_kobo', 'tax_withheld_kobo', 'net_credit_kobo', 'winnings_balance_after_kobo'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $body, "Purchase response leaked '$forbidden' before reveal.");
        }
        $this->assertSame('purchased', $body['status']);
        $this->assertSame(2, $body['target_birds']);
        $this->assertSame(100_000, $body['stake_kobo']);
        $this->assertSame(900_000, $body['play_balance_after_kobo']);
    }

    public function test_reveal_discloses_the_full_settled_outcome(): void
    {
        [, $token] = $this->signedInPlayer();

        $purchase = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1,
            'stake_kobo' => 100_000,
            'idempotency_key' => 'caged-test-reveal-1',
        ])->json();

        $reveal = $this->withToken($token)->getJson("/v1/caged/tickets/{$purchase['reference']}/reveal");

        $reveal->assertOk();
        $reveal->assertJsonStructure([
            'reference', 'escaped_birds', 'won', 'gross_prize_kobo', 'tax_withheld_kobo',
            'net_credit_kobo', 'winnings_balance_after_kobo', 'tax_rate_basis_points',
            'tax_basis_label', 'ruleset_version', 'prize_table_version', 'engine_version', 'state_name',
        ]);
        $this->assertSame($purchase['reference'], $reveal->json('reference'));
    }

    public function test_a_win_credits_winnings_balance_net_of_withholding(): void
    {
        [, $token] = $this->signedInPlayer();

        // target=1 wins ~71.8% of the time — 20 tries is overwhelmingly enough.
        $won = null;
        for ($i = 0; $i < 20 && $won === null; $i++) {
            $purchase = $this->withToken($token)->postJson('/v1/caged/tickets', [
                'target_birds' => 1,
                'stake_kobo' => 10_000,
                'idempotency_key' => "caged-win-search-$i",
            ])->json();
            $reveal = $this->withToken($token)->getJson("/v1/caged/tickets/{$purchase['reference']}/reveal")->json();
            if ($reveal['won']) {
                $won = $reveal;
            }
        }

        $this->assertNotNull($won, 'No win in 20 target=1 tickets — engine bias is suspect.');
        $this->assertSame(12_500, $won['gross_prize_kobo']); // 10_000 * 1.25
        $this->assertSame(625, $won['tax_withheld_kobo']); // 5% of gross
        $this->assertSame(11_875, $won['net_credit_kobo']);
        $this->assertSame($won['winnings_balance_after_kobo'], $won['net_credit_kobo']);
    }

    public function test_a_repeated_idempotency_key_returns_the_same_ticket_without_double_charging(): void
    {
        [$player, $token] = $this->signedInPlayer();

        $first = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 2, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-replay-key',
        ])->json();
        $second = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 2, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-replay-key',
        ])->json();

        $this->assertSame($first['reference'], $second['reference']);
        $this->assertSame(1, Ticket::where('playerId', $player->id)->where('gameCode', 'CAGED')->count());
    }

    public function test_a_stake_below_the_kyc_gate_is_refused_with_no_money_moved(): void
    {
        [$player, $token] = $this->signedInPlayer(kycTier: 0);

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-kyc-gate',
        ]);

        $response->assertStatus(422);
        $this->assertSame('GAME_UNAVAILABLE', $response->json('code'));
        $this->assertSame(0, Ticket::where('playerId', $player->id)->count());
    }

    public function test_a_stake_exceeding_play_balance_is_refused_with_no_money_moved(): void
    {
        [$player, $token] = $this->signedInPlayer(fundedKobo: 5_000);

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-balance-gate',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Ticket::where('playerId', $player->id)->count());
        $this->assertSame(5_000, app(WalletService::class)->walletFor($player)->playBalanceKobo);
    }

    public function test_a_stake_outside_the_game_registry_range_is_refused(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 5_000_000);

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 1, 'stake_kobo' => 5, 'idempotency_key' => 'caged-stake-range',
        ]);

        $response->assertStatus(422);
        $this->assertSame('GAME_UNAVAILABLE', $response->json('code'));
    }

    public function test_a_target_outside_one_to_five_is_rejected_by_validation(): void
    {
        [, $token] = $this->signedInPlayer();

        $response = $this->withToken($token)->postJson('/v1/caged/tickets', [
            'target_birds' => 6, 'stake_kobo' => 100_000, 'idempotency_key' => 'caged-target-range',
        ]);

        $response->assertStatus(422);
    }

    public function test_every_ticket_leaves_the_ledger_in_balance(): void
    {
        [, $token] = $this->signedInPlayer(fundedKobo: 2_000_000);

        for ($i = 0; $i < 10; $i++) {
            $this->withToken($token)->postJson('/v1/caged/tickets', [
                'target_birds' => 2, 'stake_kobo' => 50_000, 'idempotency_key' => "caged-balance-check-$i",
            ]);
        }

        $totals = DB::table('ledgerEntry')->selectRaw(
            "SUM(CASE WHEN direction = 'debit' THEN amountKobo ELSE 0 END) as debits, " .
            "SUM(CASE WHEN direction = 'credit' THEN amountKobo ELSE 0 END) as credits",
        )->first();

        $this->assertSame($totals->debits, $totals->credits);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/CagedTicketTest.php`
Expected: FAIL — route `/v1/games/caged` not found (404) on the first test.

- [ ] **Step 3: Write `CreateCagedTicket`**

Create `apps/platform/app/Domain/Ticket/CreateCagedTicket.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Fairness\SeedIssuer;
use App\Domain\Games\Engine\Caged\CagedEngine;
use App\Domain\Games\Engine\Caged\CagedTier;
use App\Domain\Games\PrizeTable\PrizeTableResolver;
use App\Domain\Jurisdiction\AttributionService;
use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Domain\ResponsibleGaming\VelocityService;
use App\Domain\Tax\TaxEngine;
use App\Domain\Wallet\WalletService;
use App\Jobs\DispatchPrizePayoutJob;
use App\Models\GameRegistry;
use App\Models\Player;
use App\Models\Ticket;
use App\Models\TicketOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Caged's counterpart to Domain/Ticket/CreateTicket — same two-phase pipeline
 * (eligibility/resolution with no locks held, then one short commit
 * transaction), same game-agnostic wallet/tax/RG/registry/velocity/analytics
 * machinery. The only real difference from BlackRed's CreateTicket is the
 * engine call: a single int targetBirds (1-5) instead of a B/R prediction
 * list, and win = escapedBirds >= targetBirds rather than an exact-match
 * comparison. Uses the generic PrizeTableResolver directly — Caged's
 * PrizeTable relation (tiers()) is the same generic one BlackRed uses, so no
 * dedicated resolver class is needed (unlike Heritage's).
 */
final class CreateCagedTicket
{
    private const REQUIRED_KYC_TIER = 1;
    private const GAME_CODE = 'CAGED';

    public function __construct(
        private readonly AttributionService $attribution,
        private readonly SeedIssuer $seedIssuer,
        private readonly PrizeTableResolver $prizeTableResolver,
        private readonly CagedEngine $engine,
        private readonly WalletService $wallet,
        private readonly TaxEngine $tax,
        private readonly LimitsService $limits,
        private readonly ProtectionService $protection,
        private readonly RegistryCheckService $registry,
        private readonly VelocityService $velocity,
        private readonly AnalyticsEventRecorder $analytics,
    ) {
    }

    public function create(Player $player, int $targetBirds, int $stakeKobo, string $idempotencyKey): Ticket
    {
        $existing = Ticket::where('idempotencyKey', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        // ── ELIGIBILITY AND RESOLUTION — no database locks held ──
        $game = GameRegistry::where('gameCode', self::GAME_CODE)->first();
        if ($game === null || $game->status !== 'ACTIVE') {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Caged is not currently available.');
        }

        if ($targetBirds < 1 || $targetBirds > 5) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Target must be between 1 and 5 birds.');
        }

        $this->assertKycTier($player);
        $this->protection->assertPlayAndDepositAllowed($player);
        $this->registry->assertClear($player);

        $attribution = $this->attribution->attribute($player, $game);

        if ($stakeKobo < $game->minStakeKobo || $stakeKobo > $game->maxStakeKobo) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', "Stake must be between {$game->minStakeKobo} and {$game->maxStakeKobo} kobo.");
        }
        $this->limits->assertStakeWithinLimits($player, $stakeKobo);

        $prizeTable = $this->prizeTableResolver->resolveFor(self::GAME_CODE, $attribution['stateCode']);
        if ($prizeTable === null || $prizeTable->tiers->firstWhere('positions', $targetBirds) === null) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Caged has no published prize table for this state.');
        }

        $seed = $this->seedIssuer->issue();
        $engineTiers = $prizeTable->tiers
            ->map(fn ($tier) => new CagedTier(
                (int) $tier->positions,
                (int) $tier->probabilityNumerator,
                (int) $tier->probabilityDenominator,
                (int) $tier->multiplierHundredths,
            ))
            ->all();
        $engineResult = $this->engine->resolve($seed->seedHex, $targetBirds, $stakeKobo, $engineTiers);

        $withholding = $engineResult->won ? $this->tax->withhold($engineResult->grossPrizeKobo, $player) : null;

        // ── COMMITMENT — single transaction, locks held briefly ──
        $ticket = DB::transaction(function () use (
            $player, $targetBirds, $stakeKobo, $idempotencyKey, $attribution, $seed, $engineResult, $withholding, $prizeTable,
        ) {
            $player->refresh();
            $this->assertKycTier($player);
            $this->protection->assertPlayAndDepositAllowed($player);
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);

            $ticket = Ticket::create([
                'reference' => (string) Str::ulid(),
                'playerId' => $player->id,
                'gameCode' => self::GAME_CODE,
                'idempotencyKey' => $idempotencyKey,
                'stateCode' => $attribution['stateCode'],
                'attributionConfidence' => $attribution['confidence'],
                'jurisdictionRulesetVersion' => $attribution['rulesetVersion'],
                'stakeKobo' => $stakeKobo,
                'predictionJson' => [$targetBirds],
                'positions' => $targetBirds,
                'prizeTableVersion' => $prizeTable->version,
                'rngSeedRef' => $seed->id,
                'rngAlgorithm' => $seed->algorithm,
                'engineVersion' => $engineResult->engineVersion,
                'status' => 'CREATED',
            ]);

            $this->wallet->reserveStake($player, $stakeKobo, 'ticket', $ticket->id, $attribution['stateCode']);

            if ($withholding !== null) {
                $taxWithheldKobo = $withholding->taxWithheldKobo;
                $netCreditKobo = $withholding->netCreditKobo;
                $taxRateBasisPoints = $withholding->rateBasisPoints;
                $taxBasisLabel = $withholding->basisLabel;
                $taxRulesetVersion = $withholding->rulesetVersion;
            } else {
                $taxWithheldKobo = 0;
                $netCreditKobo = 0;
                $taxRateBasisPoints = 0;
                $taxBasisLabel = '';
                $taxRulesetVersion = '';
            }

            TicketOutcome::create([
                'ticketId' => $ticket->id,
                'resultJson' => ['escaped_birds' => $engineResult->escapedBirds, 'target_birds' => $targetBirds],
                'won' => $engineResult->won,
                'grossPrizeKobo' => $engineResult->grossPrizeKobo,
                'taxWithheldKobo' => $taxWithheldKobo,
                'netCreditKobo' => $netCreditKobo,
                'taxRateBasisPoints' => $taxRateBasisPoints,
                'taxBasisLabel' => $taxBasisLabel,
                'taxRulesetVersion' => $taxRulesetVersion,
                'digest' => $engineResult->digest,
            ]);

            if ($engineResult->won) {
                $this->wallet->settleWin(
                    $player, $stakeKobo, $engineResult->grossPrizeKobo,
                    $taxWithheldKobo, $netCreditKobo, 'ticket', $ticket->id, $attribution['stateCode'],
                );
            } else {
                $this->wallet->settleLoss($stakeKobo, 'ticket', $ticket->id, $attribution['stateCode']);
            }

            $ticket->update(['status' => 'SETTLED']);

            return $ticket;
        });

        if ($engineResult->won) {
            DispatchPrizePayoutJob::dispatch($ticket->id);
        }

        $this->velocity->evaluateAfterTicket($player, self::GAME_CODE, $stakeKobo);

        $this->analytics->record('ticket_purchased', $player, 'web', gameCode: self::GAME_CODE, stateCode: $attribution['stateCode'], properties: [
            'stake_kobo' => $stakeKobo,
            'won' => $engineResult->won,
        ]);

        return $ticket;
    }

    private function assertKycTier(Player $player): void
    {
        if ($player->kycTier < self::REQUIRED_KYC_TIER) {
            throw new TicketEligibilityException('GAME_UNAVAILABLE', 'Account has not reached the KYC tier required to play.');
        }
    }
}
```

- [ ] **Step 4: Write `PurchaseCagedTicketRequest`**

Create `apps/platform/app/Http/Requests/Api/V1/PurchaseCagedTicketRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseCagedTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'target_birds' => ['required', 'integer', 'min:1', 'max:5'],
            'stake_kobo' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
```

- [ ] **Step 5: Write `CagedController`**

Create `apps/platform/app/Http/Controllers/Api/V1/CagedController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Games\PrizeTable\PrizeTableResolver;
use App\Domain\Ticket\CreateCagedTicket;
use App\Domain\Ticket\RevealTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseCagedTicketRequest;
use App\Models\GameRegistry;
use App\Models\Player;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

class CagedController extends Controller
{
    private const STATE_NAMES = ['LAG' => 'Lagos'];
    private const GAME_CODE = 'CAGED';

    public function __construct(
        private readonly WalletService $wallet,
        private readonly CreateCagedTicket $createTicket,
        private readonly RevealTicket $revealTicket,
        private readonly PrizeTableResolver $prizeTableResolver,
    ) {
    }

    /** GET /v1/games/caged */
    public function show(): JsonResponse
    {
        $player = $this->player();
        $game = GameRegistry::where('gameCode', self::GAME_CODE)->firstOrFail();
        $wallet = $this->wallet->walletFor($player);
        $stateCode = (string) config('jurisdiction.stub_state_code');

        $prizeTable = $this->prizeTableResolver->resolveFor(self::GAME_CODE, $stateCode);
        if ($prizeTable === null) {
            return response()->json(['message' => 'Caged is not currently available.'], 503);
        }

        return response()->json([
            'game_name' => 'Caged',
            'description' => 'Predict how many birds escape before the cage slams shut.',
            'play_balance_kobo' => $wallet->playBalanceKobo,
            'winnings_balance_kobo' => $wallet->winningsBalanceKobo,
            'min_stake_kobo' => $game->minStakeKobo,
            'max_stake_kobo' => $game->maxStakeKobo,
            'currency' => 'NGN',
            'state_name' => self::STATE_NAMES[$stateCode] ?? $stateCode,
            'prize_table_version' => $prizeTable->version,
            'engine_version' => $game->engineVersion,
            'tiers' => $prizeTable->tiers->map(fn ($tier) => [
                'target_birds' => $tier->positions,
                'multiplier_hundredths' => $tier->multiplierHundredths,
                'probability_numerator' => $tier->probabilityNumerator,
                'probability_denominator' => $tier->probabilityDenominator,
            ])->values(),
        ]);
    }

    /** POST /v1/caged/tickets — the response discloses nothing about the outcome. */
    public function purchase(PurchaseCagedTicketRequest $request): JsonResponse
    {
        $player = $this->player();

        try {
            $ticket = $this->createTicket->create(
                $player,
                (int) $request->input('target_birds'),
                (int) $request->input('stake_kobo'),
                $request->string('idempotency_key')->toString(),
            );
        } catch (TicketEligibilityException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], 422);
        }

        $wallet = $this->wallet->walletFor($player);

        return response()->json([
            'reference' => $ticket->reference,
            'purchased_at' => $ticket->createdAt->toIso8601String(),
            'target_birds' => $ticket->positions,
            'stake_kobo' => $ticket->stakeKobo,
            'tier' => $this->tierShape($ticket),
            'play_balance_after_kobo' => $wallet->playBalanceKobo,
            'status' => 'purchased',
        ]);
    }

    /** GET /v1/caged/tickets/{reference}/reveal */
    public function reveal(string $reference): JsonResponse
    {
        $player = $this->player();
        $ticket = $this->revealTicket->reveal($player, $reference);
        if ($ticket === null) {
            return response()->json(['message' => 'Ticket not found or not yet settled.'], 404);
        }

        $outcome = $ticket->outcome;
        $wallet = $this->wallet->walletFor($player);

        return response()->json([
            'reference' => $ticket->reference,
            'purchased_at' => $ticket->createdAt->toIso8601String(),
            'target_birds' => $ticket->positions,
            'stake_kobo' => $ticket->stakeKobo,
            'tier' => $this->tierShape($ticket),
            'play_balance_after_kobo' => $wallet->playBalanceKobo,
            'status' => 'settled',
            'escaped_birds' => $outcome->resultJson['escaped_birds'],
            'won' => $outcome->won,
            'gross_prize_kobo' => $outcome->grossPrizeKobo,
            'tax_withheld_kobo' => $outcome->taxWithheldKobo,
            'net_credit_kobo' => $outcome->netCreditKobo,
            'winnings_balance_after_kobo' => $wallet->winningsBalanceKobo,
            'tax_rate_basis_points' => $outcome->taxRateBasisPoints,
            'tax_basis_label' => $outcome->taxBasisLabel,
            'ruleset_version' => $ticket->jurisdictionRulesetVersion,
            'prize_table_version' => $ticket->prizeTableVersion,
            'engine_version' => $ticket->engineVersion,
            'state_name' => self::STATE_NAMES[$ticket->stateCode] ?? $ticket->stateCode,
        ]);
    }

    /** @return array{target_birds:int,multiplier_hundredths:int,probability_numerator:int,probability_denominator:int} */
    private function tierShape(Ticket $ticket): array
    {
        $prizeTable = $this->prizeTableResolver->resolveFor($ticket->gameCode, $ticket->stateCode, $ticket->createdAt);
        $tier = $prizeTable?->tiers->firstWhere('positions', $ticket->positions);

        if ($tier !== null) {
            return [
                'target_birds' => $ticket->positions,
                'multiplier_hundredths' => $tier->multiplierHundredths,
                'probability_numerator' => $tier->probabilityNumerator,
                'probability_denominator' => $tier->probabilityDenominator,
            ];
        }

        return [
            'target_birds' => $ticket->positions,
            'multiplier_hundredths' => 0,
            'probability_numerator' => 0,
            'probability_denominator' => 1,
        ];
    }

    private function player(): Player
    {
        /** @var Player $player */
        $player = request()->attributes->get('player');

        return $player;
    }
}
```

- [ ] **Step 6: Wire the routes**

Read `apps/platform/routes/v1.php`, then add the import:

```php
use App\Http\Controllers\Api\V1\CagedController;
```

(alphabetically after `BlackRedController`, before `HeritageController`) and add the route block right after the Heritage block, before the BirdEscape comment:

```php
        // A third ticket game, same resolve-once shape as BlackRed/Heritage — its own
        // routes since the purchase shape (target_birds) differs from both.
        Route::get('/games/caged', [CagedController::class, 'show']);
        Route::post('/caged/tickets', [CagedController::class, 'purchase'])->middleware('idempotent');
        Route::get('/caged/tickets/{reference}/reveal', [CagedController::class, 'reveal']);
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/CagedTicketTest.php`
Expected: PASS (11 tests).

- [ ] **Step 8: Run the full platform test suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS — no existing test references anything this task touched.

- [ ] **Step 9: Commit**

```bash
git add apps/platform/app/Domain/Ticket/CreateCagedTicket.php apps/platform/app/Http/Requests/Api/V1/PurchaseCagedTicketRequest.php apps/platform/app/Http/Controllers/Api/V1/CagedController.php apps/platform/routes/v1.php apps/platform/tests/Feature/CagedTicketTest.php
git commit -m "feat: add CreateCagedTicket, CagedController, and /v1/caged/* routes"
```

---

## Task 5: USSD `PlatformClient` — add Caged methods, remove funding methods

**Files:**
- Modify: `apps/ussd/src/PlatformClientInterface.php`
- Modify: `apps/ussd/src/PlatformClient.php`
- Modify: `apps/ussd/tests/Fakes/FakePlatformClient.php`

**Interfaces:**
- Consumes: the platform routes from Task 4 (`GET /v1/games/caged`, `POST /v1/caged/tickets`, `GET /v1/caged/tickets/{reference}/reveal`) — not called directly by these unit-level changes (no HTTP in `apps/ussd` tests), but the method bodies target them.
- Produces: `PlatformClientInterface::cagedDescriptor(string $token): array`, `::purchaseCagedTicket(string $token, int $targetBirds, int $stakeKobo, string $idempotencyKey): array`, `::revealCagedTicket(string $token, string $reference): array` — consumed by Task 6's `MenuEngine`.

- [ ] **Step 1: Update `PlatformClientInterface`**

Read `apps/ussd/src/PlatformClientInterface.php`, then remove:

```php
    /** @return array<string, mixed> */
    public function fundingQuote(string $token, int $amountKobo): array;

    /** @return array<string, mixed> */
    public function createDeposit(string $token, string $quoteId): array;

    /** @return array<string, mixed> */
    public function submitDepositOtp(string $token, int $depositId, string $otp): array;
```

and add, right after `revealHeritageTicket`'s declaration and before `notifyTicketSms`:

```php
    /** @return array<string, mixed> */
    public function cagedDescriptor(string $token): array;

    /**
     * @return array<string, mixed>
     */
    public function purchaseCagedTicket(string $token, int $targetBirds, int $stakeKobo, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function revealCagedTicket(string $token, string $reference): array;
```

- [ ] **Step 2: Update `PlatformClient`**

Read `apps/ussd/src/PlatformClient.php`, then remove the `fundingQuote`, `createDeposit`, and `submitDepositOtp` method bodies, and add, right after `revealHeritageTicket()`'s body and before `notifyTicketSms()`:

```php
    /** @return array<string, mixed> */
    public function cagedDescriptor(string $token): array
    {
        return $this->getAuthed('/v1/games/caged', $token);
    }

    /**
     * @return array<string, mixed>
     */
    public function purchaseCagedTicket(string $token, int $targetBirds, int $stakeKobo, string $idempotencyKey): array
    {
        return $this->postAuthed('/v1/caged/tickets', $token, [
            'target_birds' => $targetBirds, 'stake_kobo' => $stakeKobo, 'idempotency_key' => $idempotencyKey,
        ]);
    }

    /** @return array<string, mixed> */
    public function revealCagedTicket(string $token, string $reference): array
    {
        return $this->getAuthed("/v1/caged/tickets/$reference/reveal", $token);
    }
```

- [ ] **Step 3: Update `FakePlatformClient`**

Read `apps/ussd/tests/Fakes/FakePlatformClient.php`, then remove:

```php
    public function fundingQuote(string $token, int $amountKobo): array
    {
        return $this->respond(__FUNCTION__, [$token, $amountKobo]);
    }

    public function createDeposit(string $token, string $quoteId): array
    {
        return $this->respond(__FUNCTION__, [$token, $quoteId]);
    }

    public function submitDepositOtp(string $token, int $depositId, string $otp): array
    {
        return $this->respond(__FUNCTION__, [$token, $depositId, $otp]);
    }
```

and add, right after `revealHeritageTicket()`'s body and before `notifyTicketSms()`:

```php
    public function cagedDescriptor(string $token): array
    {
        return $this->respond(__FUNCTION__, [$token]);
    }

    public function purchaseCagedTicket(string $token, int $targetBirds, int $stakeKobo, string $idempotencyKey): array
    {
        return $this->respond(__FUNCTION__, [$token, $targetBirds, $stakeKobo, $idempotencyKey]);
    }

    public function revealCagedTicket(string $token, string $reference): array
    {
        return $this->respond(__FUNCTION__, [$token, $reference]);
    }
```

- [ ] **Step 4: Run the full ussd test suite to confirm it still compiles/passes**

Run (from `apps/ussd`): `vendor/bin/phpunit`
Expected: FAIL at this point — `MenuEngine.php` still calls the now-removed `fundingQuote`/`createDeposit`/`submitDepositOtp` methods and won't compile/pass. This is expected; Task 6 fixes it. (If your PHP setup fails fast on the interface mismatch, that failure message is the confirmation this step needs — proceed to Task 6.)

- [ ] **Step 5: Commit**

```bash
git add apps/ussd/src/PlatformClientInterface.php apps/ussd/src/PlatformClient.php apps/ussd/tests/Fakes/FakePlatformClient.php
git commit -m "feat: add Caged methods to PlatformClient, remove USSD deposit methods"
```

---

## Task 6: `MenuEngine` — Caged screens, main-menu renumber, deposit-flow removal

**Files:**
- Modify: `apps/ussd/src/MenuEngine.php`
- Modify: `apps/ussd/tests/MenuEngineTest.php`

**Interfaces:**
- Consumes: `PlatformClientInterface::cagedDescriptor/purchaseCagedTicket/revealCagedTicket` (Task 5).
- Produces: three new session screens (`caged_pick`, `caged_stake`, `caged_confirm`) and an updated main menu — this is the final USSD-facing deliverable, tested end-to-end via `MenuEngineTest`.

- [ ] **Step 1: Write the failing/updated test file**

Read `apps/ussd/tests/MenuEngineTest.php` in full first (needed since this step both removes two tests and adds several — Edit, not Write, so the untouched tests survive unchanged). Make these changes:

Delete this entire block verbatim (that flow no longer exists):

```php
    // ── Funding (REQ-USSD-010..013) ──────────────────────────────────────────

    public function test_funding_without_tier_1_is_routed_to_verification_not_a_bare_failure(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3'); // -> fund_amount

        $this->platform->programResponse('fundingQuote', ['quote_id' => '100000']);
        $this->platform->programResponse('createDeposit', ['status' => 'bvn_required']);

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '1000');

        $this->assertFalse($screen->continues);
        $this->assertStringContainsString('Tier 1 verification', $screen->render());
    }

    public function test_a_deposit_that_does_not_resolve_promptly_closes_the_session_gracefully(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');
        $this->platform->programResponse('fundingQuote', ['quote_id' => '100000']);
        $this->platform->programResponse('createDeposit', ['status' => 'otp_required', 'collection_id' => 42]);
        $this->engine->handleTurn('sess-1', '+2348031234567', '1000');

        $this->platform->programResponse('submitDepositOtp', ['status' => 'processing']);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '123456');

        $this->assertFalse($screen->continues);
        $this->assertStringContainsString('SMS you when it lands', $screen->render());
    }

```

In its place (same location in the file, right after the Heritage tests and before the Responsible-gambling section), insert this new section, with these test methods:

```php
    // ── Caged (Option B — Escape Count) ──────────────────────────────────────

    public function test_a_full_caged_win_flow_calls_the_same_v1_endpoint_web_uses(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '3'); // -> caged_pick
        $this->assertFits($screen);

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '2'); // target 2 birds
        $this->assertFits($screen);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '200'); // stake
        $this->assertStringContainsString('Target: 2 Birds', $screen->render());
        $this->assertStringContainsString('Stake: NGN 200', $screen->render());
        $this->assertFits($screen);

        $this->platform->programResponse('purchaseCagedTicket', ['reference' => 'tkt-cg-1']);
        $this->platform->programResponse('revealCagedTicket', ['won' => true, 'escaped_birds' => 3, 'net_credit_kobo' => 38_000]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('CAGED WIN!', $result->render());
        $this->assertStringContainsString('3 birds escaped', $result->render());
        $this->assertFits($result);

        $purchaseCall = array_values(array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseCagedTicket'))[0];
        $this->assertSame(2, $purchaseCall['args'][1]); // target_birds
        $this->assertSame(20_000, $purchaseCall['args'][2]); // stake_kobo
    }

    public function test_a_caged_loss_shows_the_escaped_count_and_lost_stake(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');
        $this->engine->handleTurn('sess-1', '+2348031234567', '5'); // target 5 birds
        $this->engine->handleTurn('sess-1', '+2348031234567', '100'); // stake

        $this->platform->programResponse('purchaseCagedTicket', ['reference' => 'tkt-cg-2']);
        $this->platform->programResponse('revealCagedTicket', ['won' => false, 'escaped_birds' => 1, 'net_credit_kobo' => 0]);

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('CAGED LOSS!', $result->render());
        $this->assertStringContainsString('Cage dropped at 1 bird(s)!', $result->render());
        $this->assertStringContainsString('Lost: NGN 100', $result->render());
        $this->assertFits($result);
    }

    public function test_caged_pick_rejects_an_out_of_range_target(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');

        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '9');

        $this->assertStringStartsWith('Invalid input.', $screen->text);
    }

    public function test_zero_at_caged_pick_returns_to_the_main_menu(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '0');

        $this->assertStringContainsString('Play Caged', $screen->render());
    }

    public function test_cancelling_at_caged_confirm_returns_to_the_main_menu_without_purchasing(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '200');

        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);
        $screen = $this->engine->handleTurn('sess-1', '+2348031234567', '2');

        $this->assertStringContainsString('Play Caged', $screen->render());
        $this->assertCount(0, array_filter($this->platform->calls, fn ($c) => $c['method'] === 'purchaseCagedTicket'));
    }

    public function test_a_failed_caged_purchase_points_the_player_at_the_opay_app_with_no_otp_screen(): void
    {
        $this->signIn('sess-1', '+2348031234567');
        $this->engine->handleTurn('sess-1', '+2348031234567', '3');
        $this->engine->handleTurn('sess-1', '+2348031234567', '1');
        $this->engine->handleTurn('sess-1', '+2348031234567', '200');

        $this->platform->programResponse('purchaseCagedTicket', []); // no 'reference' => purchase failed

        $result = $this->engine->handleTurn('sess-1', '+2348031234567', '1');

        $this->assertFalse($result->continues);
        $this->assertStringContainsString('Fund your wallet via the Betplus/OPay app', $result->render());
        $this->assertCount(0, array_filter($this->platform->calls, fn ($c) => $c['method'] === 'fundingQuote'));
    }
```

Then update the main-menu assertion in `test_a_full_blackred_purchase_flow_calls_the_same_v1_endpoint_web_uses`'s sibling helper — there isn't one to change directly, but add one explicit main-menu-text test right after the "── Invalid input ──" section:

```php
    // ── Main menu (post-Caged renumber) ──────────────────────────────────────

    public function test_the_main_menu_lists_caged_as_option_three_with_no_fund_account_item(): void
    {
        $screen = $this->signInAndReturnScreen('sess-1', '+2348031234567');

        $this->assertStringContainsString('3. Play Caged', $screen->render());
        $this->assertStringContainsString('4. Responsible play', $screen->render());
        $this->assertStringNotContainsStringIgnoringCase('fund account', $screen->render());
        $this->assertFits($screen);
    }
```

That test needs a small helper — add this private method right next to the existing `signIn()` helper at the bottom of the class:

```php
    private function signInAndReturnScreen(string $sessionId, string $msisdn): \Betplus\Ussd\Screen
    {
        $this->platform->programResponse('identify', ['status' => 'signed_in', 'access_token' => 'tok-1', 'registered_name' => 'Ada']);
        $this->platform->programResponse('wallet', ['play_balance_kobo' => 0]);

        return $this->engine->handleTurn($sessionId, $msisdn, '');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run (from `apps/ussd`): `vendor/bin/phpunit`
Expected: FAIL — `MenuEngine` doesn't dispatch `caged_pick`/`caged_stake`/`caged_confirm`, main menu still shows "Fund account", and `MenuEngine` still references the now-removed `fundingQuote`/`createDeposit`/`submitDepositOtp` (fatal error).

- [ ] **Step 3: Update `MenuEngine::dispatch()`**

Read `apps/ussd/src/MenuEngine.php`, then replace the `dispatch()` method's `match` body:

```php
    private function dispatch(Session $session, string $input): Screen
    {
        return match ($session->screen) {
            'resume_offer' => $this->screenResumeOffer($session, $input),
            'welcome' => $this->screenWelcome($session, $input),
            'registration_confirm' => $this->screenRegistrationConfirm($session, $input),
            'main_menu' => $this->screenMainMenu($session, $input),
            'blackred_length' => $this->screenBlackRedLength($session, $input),
            'blackred_pick' => $this->screenBlackRedPick($session, $input),
            'blackred_stake' => $this->screenBlackRedStake($session, $input),
            'blackred_confirm' => $this->screenBlackRedConfirm($session, $input),
            'heritage_pick' => $this->screenHeritagePick($session, $input),
            'heritage_stake' => $this->screenHeritageStake($session, $input),
            'heritage_confirm' => $this->screenHeritageConfirm($session, $input),
            'caged_pick' => $this->screenCagedPick($session, $input),
            'caged_stake' => $this->screenCagedStake($session, $input),
            'caged_confirm' => $this->screenCagedConfirm($session, $input),
            'rg_menu' => $this->screenRgMenu($session, $input),
            'rg_break_menu' => $this->screenRgBreakMenu($session, $input),
            default => Screen::end('Session error. Please dial again.'),
        };
    }
```

(This drops `'blackred_pay_otp'`, `'heritage_pay_otp'`, `'fund_amount'`, `'fund_otp'` and adds the three `caged_*` arms.)

- [ ] **Step 4: Add the `CAGED_ODDS` constant and update `screenMainMenu()`**

Add this constant right after `RESUME_WINDOW_SECONDS` near the top of the class:

```php
    private const RESUME_WINDOW_SECONDS = 10 * 60;

    /** targetBirds => display multiplier, from docs/caged-ussd-complete-flows.md's Escape Count table. */
    private const CAGED_ODDS = [1 => 1.25, 2 => 1.90, 3 => 3.80, 4 => 7.50, 5 => 18.00];
```

Replace `screenMainMenu()`:

```php
    private function screenMainMenu(Session $session, string $input): Screen
    {
        if ($input === '1') {
            $session->screen = 'blackred_length';

            return Screen::continue("BlackRed\nHow many picks? (1-5)");
        }
        if ($input === '2') {
            $session->screen = 'heritage_pick';
            $session->data['heritagePicks'] = [];

            return Screen::continue("Heritage\nPick 5 of 9 positions (1-9), one at a time.\nEnter position 1 of 5:");
        }
        if ($input === '3') {
            $session->screen = 'caged_pick';

            return $this->screenCagedPick($session, '');
        }
        if ($input === '4') {
            $session->screen = 'rg_menu';

            return $this->screenRgMenu($session, '');
        }
        if ($input === '0' || $input === '') {
            if ($input === '0') {
                return Screen::end('Thank you for playing Betplus responsibly.');
            }
            $wallet = $this->platform->wallet((string) $session->accessToken);
            $bal = number_format((int) ($wallet['play_balance_kobo'] ?? 0) / 100, 0);

            return Screen::continue("Betplus\nBal: NGN $bal\n1. Play BlackRed\n2. Play Heritage\n3. Play Caged\n4. Responsible play\n0. Exit");
        }

        return $this->errorPrefixed($session, 'main_menu', '1. BlackRed 2. Heritage 3. Caged 4. RG tools 0. Exit');
    }
```

- [ ] **Step 5: Remove the OTP fallback from `screenBlackRedConfirm()` and delete `screenBlackRedPayOtp()`**

Replace the body of `screenBlackRedConfirm()`'s purchase-failure branch — find:

```php
        if (!isset($purchase['reference'])) {
            $quote = $this->platform->fundingQuote((string) $session->accessToken, (int) $session->data['brStakeKobo']);
            if (isset($quote['quote_id'])) {
                $deposit = $this->platform->createDeposit((string) $session->accessToken, (string) $quote['quote_id']);
                if (($deposit['status'] ?? '') === 'otp_required') {
                    $session->data['depositId'] = (int) $deposit['collection_id'];
                    $session->screen = 'blackred_pay_otp';
                    $naira = number_format(((int) $session->data['brStakeKobo']) / 100, 0);

                    return Screen::continue("Stake NGN $naira via OPay.\nEnter the OTP OPay sent you to play:");
                }
            }

            return Screen::end('Could not place that ticket. Please try again.');
        }
```

replace with:

```php
        if (!isset($purchase['reference'])) {
            return Screen::end('Could not place that ticket. Fund your wallet via the Betplus/OPay app and try again.');
        }
```

Then delete the entire `screenBlackRedPayOtp()` method that follows `screenBlackRedConfirm()`.

- [ ] **Step 6: Remove the OTP fallback from `screenHeritageConfirm()` and delete `screenHeritagePayOtp()`**

Same treatment — find in `screenHeritageConfirm()`:

```php
        if (!isset($purchase['reference'])) {
            $quote = $this->platform->fundingQuote((string) $session->accessToken, (int) $session->data['hgStakeKobo']);
            if (isset($quote['quote_id'])) {
                $deposit = $this->platform->createDeposit((string) $session->accessToken, (string) $quote['quote_id']);
                if (($deposit['status'] ?? '') === 'otp_required') {
                    $session->data['depositId'] = (int) $deposit['collection_id'];
                    $session->screen = 'heritage_pay_otp';
                    $naira = number_format(((int) $session->data['hgStakeKobo']) / 100, 0);

                    return Screen::continue("Stake NGN $naira via OPay.\nEnter the OTP OPay sent you to play:");
                }
            }

            return Screen::end('Could not place that ticket. Please try again.');
        }
```

replace with:

```php
        if (!isset($purchase['reference'])) {
            return Screen::end('Could not place that ticket. Fund your wallet via the Betplus/OPay app and try again.');
        }
```

Then delete the entire `screenHeritagePayOtp()` method that follows.

- [ ] **Step 7: Delete `screenFundAmount()` and `screenFundOtp()`, and the `// ── Funding ──` section comment**

Remove both methods (and their preceding `// ── Funding ──────────────────────────────────────────────────────────────` section-header comment) entirely from `MenuEngine.php`.

- [ ] **Step 8: Add the three Caged screens**

Add this new section right after `screenHeritagePayOtp()`'s deletion point (i.e., where the Heritage section ends), before the (now-deleted) Funding section would have been — so directly before the `// ── Responsible gambling ──` section:

```php
    // ── Caged (Option B — Escape Count) ─────────────────────────────────────

    private function screenCagedPick(Session $session, string $input): Screen
    {
        if ($input === '0') {
            $session->screen = 'main_menu';

            return $this->screenMainMenu($session, '');
        }
        if ($input === '') {
            return Screen::continue("Caged: Birds Escaping\n1. 1 Bird  (1.25x)\n2. 2 Birds (1.90x)\n3. 3 Birds (3.80x)\n4. 4 Birds (7.50x)\n5. 5 Birds (18.0x)\n0. Back");
        }
        if (!in_array($input, ['1', '2', '3', '4', '5'], true)) {
            return $this->errorPrefixed($session, 'caged_pick', 'Pick 1-5 birds, or 0 to go back.');
        }

        $session->data['cagedTarget'] = (int) $input;
        $session->screen = 'caged_stake';

        return Screen::continue('Enter stake in Naira (e.g. 200):');
    }

    private function screenCagedStake(Session $session, string $input): Screen
    {
        $stakeKobo = $this->nairaToKobo($input);
        if ($stakeKobo === null) {
            return $this->errorPrefixed($session, 'caged_stake', 'Enter stake in Naira (e.g. 200):');
        }

        $session->data['cgStakeKobo'] = $stakeKobo;
        $session->screen = 'caged_confirm';

        return Screen::continue($this->cagedConfirmText($session));
    }

    private function screenCagedConfirm(Session $session, string $input): Screen
    {
        if ($input === '2') {
            $session->screen = 'main_menu';

            return $this->screenMainMenu($session, '');
        }
        if ($input !== '1') {
            return $this->errorPrefixed($session, 'caged_confirm', $this->cagedConfirmText($session));
        }

        $target = (int) $session->data['cagedTarget'];
        $idempotencyKey = 'ussd-cg-' . $session->sessionId;
        $purchase = $this->platform->purchaseCagedTicket((string) $session->accessToken, $target, (int) $session->data['cgStakeKobo'], $idempotencyKey);

        if (!isset($purchase['reference'])) {
            return Screen::end('Could not place that ticket. Fund your wallet via the Betplus/OPay app and try again.');
        }

        $reveal = $this->platform->revealCagedTicket((string) $session->accessToken, (string) $purchase['reference']);
        $won = (bool) ($reveal['won'] ?? false);
        $escaped = (int) ($reveal['escaped_birds'] ?? 0);
        $net = number_format((int) ($reveal['net_credit_kobo'] ?? 0) / 100, 0);
        $naira = number_format(((int) $session->data['cgStakeKobo']) / 100, 0);

        $this->platform->notifyTicketSms((string) $session->accessToken, (string) $purchase['reference']);

        if ($won) {
            return Screen::end("CAGED WIN!\n$escaped birds escaped the cage!\nYour Target: $target Birds\nWon: NGN $net\nRef: {$purchase['reference']}");
        }

        return Screen::end("CAGED LOSS!\nCage dropped at $escaped bird(s)!\nYour Target: $target Birds\nLost: NGN $naira\nRef: {$purchase['reference']}");
    }

    private function cagedConfirmText(Session $session): string
    {
        $target = (int) $session->data['cagedTarget'];
        $odds = self::CAGED_ODDS[$target];
        $stakeKobo = (int) $session->data['cgStakeKobo'];
        $naira = number_format($stakeKobo / 100, 0);
        $win = number_format(($stakeKobo * $odds) / 100, 0);

        return "Confirm Caged Bet:\nTarget: $target Birds\nOdds: " . number_format($odds, 2) . "x\nStake: NGN $naira\nWin: NGN $win\n1. Confirm & Play\n2. Cancel";
    }
```

- [ ] **Step 9: Run the ussd test suite**

Run (from `apps/ussd`): `vendor/bin/phpunit`
Expected: PASS — all `MenuEngineTest` tests, including the new Caged ones and the updated main-menu test.

- [ ] **Step 10: Run PHPStan (this repo runs it in CI on this app)**

Run (from `apps/ussd`): `vendor/bin/phpstan analyse`
Expected: PASS — no new errors. (If PHPStan flags the `array` return of `respond()` against the new methods' typed returns, that's expected of the existing pattern too — compare against how `purchaseHeritageTicket`/`revealHeritageTicket` already pass today; fix only genuinely new issues introduced by this task's diff.)

- [ ] **Step 11: Commit**

```bash
git add apps/ussd/src/MenuEngine.php apps/ussd/tests/MenuEngineTest.php
git commit -m "feat: add Caged screens to MenuEngine, remove USSD deposit flow"
```

---

## Final verification

- [ ] Run the full platform suite: `cd apps/platform && vendor/bin/phpunit` — expect PASS.
- [ ] Run the full ussd suite: `cd apps/ussd && vendor/bin/phpunit` — expect PASS.
- [ ] Grep for any leftover reference to the removed funding flow outside test files, to confirm nothing else in `apps/ussd` still calls it:
  ```bash
  grep -rn "fundingQuote\|createDeposit\|submitDepositOtp\|fund_amount\|fund_otp\|blackred_pay_otp\|heritage_pay_otp" apps/ussd/src
  ```
  Expected: no output.
