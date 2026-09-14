# Admin Gaming Economics — Phase 1 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give admins a maker-checker-gated way to select, per game, between the Fixed-RTP model and the Balanced-Hybrid model's exposure cap, with an 88% RTP ceiling enforced platform-wide, across all four engine call sites (BlackRed, Heritage, Caged-USSD, Caged-web/BirdEscape).

**Architecture:** A new `GameEconomicsConfig` maker-checker artifact (same draft → gate → `ReviewableChangeController` approval → publish shape as `PrizeTable`/`CrashConfig`) resolved fresh at ticket/bet-creation time via `EconomicsConfigResolver`. Each of the two Phase-1 models is a small `EconomicsModelStrategy` class, injected into `CreateTicket`, `CreateHeritageTicket`, `CreateCagedTicket`, and `PlaceCrashBet` at the same point `LimitsService::assertStakeWithinLimits` already runs.

**Tech Stack:** Laravel (PHP 8.2+, `declare(strict_types=1)` everywhere), PHPUnit, MySQL/Eloquent migrations.

**Spec:** `docs/superpowers/specs/2026-09-13-admin-gaming-economics-models-design.md`

## Global Constraints

- Money is always an integer count of kobo — never a float — everywhere in this plan.
- RTP/house-edge/probability values are always integer basis points (1/10000ths) — never a float.
- `declare(strict_types=1);` at the top of every new PHP file.
- Every new domain class is `final`.
- A rejection during ticket/bet acceptance throws `App\Domain\Ticket\TicketEligibilityException` with a specific error code — never a generic exception.
- New tables follow the existing camelCase-in-SQL convention (`createdAt`, `updatedAt`, `gameEconomicsConfig`, not snake_case).
- Phases 2 and 3 of the design spec (reserve fund, Daily Loss-Stop, Pari-Mutuel Pool) are **out of scope** for this plan.
- The admin frontend console (React) is **out of scope** for this plan — this plan ships the BackOffice API only, mirroring the fact that `CrashConfigController`/`GameRegistryController` are independently useful and testable via HTTP before any bespoke console exists for them. It becomes its own follow-on plan once this one lands.

---

## Task 1: `gameEconomicsConfig` migration

**Files:**
- Create: `apps/platform/database/migrations/2026_09_14_000100_create_game_economics_config_table.php`
- Test: `apps/platform/tests/Feature/GameEconomicsConfigMigrationTest.php`

**Interfaces:**
- Produces: table `gameEconomicsConfig` with columns `id, gameCode, version, status, activeModel, paramsJson, effectiveAt, publishedAt, createdAt, updatedAt`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GameEconomicsConfigMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_game_economics_config_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('gameEconomicsConfig'));
        $this->assertTrue(Schema::hasColumns('gameEconomicsConfig', [
            'id', 'gameCode', 'version', 'status', 'activeModel', 'paramsJson',
            'effectiveAt', 'publishedAt', 'createdAt', 'updatedAt',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (from `apps/platform`): `php artisan test --filter GameEconomicsConfigMigrationTest`
Expected: FAIL — table `gameEconomicsConfig` does not exist.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same lifecycle shape as crashConfig/prizeTable (draft -> published via
        // maker-checker), but this table doesn't carry odds/prizes itself — it
        // records which economics model + params governs *acceptance-time*
        // enforcement for a game, resolved fresh at ticket/bet-creation time by
        // EconomicsConfigResolver, exactly like CrashConfigResolver/PrizeTableResolver.
        Schema::create('gameEconomicsConfig', function (Blueprint $table) {
            $table->id();
            $table->string('gameCode', 20);
            $table->string('version', 30);
            $table->string('status', 10)->default('draft')->comment('draft | published | retired');
            $table->string('activeModel', 30)->comment('FIXED_RTP | BALANCED_HYBRID | DAILY_LOSS_STOP | PARI_MUTUEL_POOL');
            $table->json('paramsJson')->comment('model-specific param bag, shape depends on activeModel');
            $table->dateTime('effectiveAt');
            $table->dateTime('publishedAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['gameCode', 'version'], 'uniq_gameEconomicsConfig_game_version');
            $table->index(['gameCode', 'status', 'effectiveAt'], 'idx_gameEconomicsConfig_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameEconomicsConfig');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter GameEconomicsConfigMigrationTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/database/migrations/2026_09_14_000100_create_game_economics_config_table.php apps/platform/tests/Feature/GameEconomicsConfigMigrationTest.php
git commit -m "feat: add gameEconomicsConfig table"
```

---

## Task 2: `exposureKobo` column on `crashRound`

**Files:**
- Create: `apps/platform/database/migrations/2026_09_14_000200_add_exposure_kobo_to_crash_round_table.php`
- Modify: `apps/platform/tests/Feature/GameEconomicsConfigMigrationTest.php`

**Interfaces:**
- Produces: `crashRound.exposureKobo` (unsigned big integer, default 0).

- [ ] **Step 1: Add the failing assertion**

```php
    public function test_crash_round_has_an_exposure_kobo_column_defaulting_to_zero(): void
    {
        $this->assertTrue(Schema::hasColumn('crashRound', 'exposureKobo'));
    }
```

Add this method inside `GameEconomicsConfigMigrationTest` (same file as Task 1).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GameEconomicsConfigMigrationTest`
Expected: FAIL — `test_crash_round_has_an_exposure_kobo_column_defaulting_to_zero` fails, column missing.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Running worst-case liability of every PLACED bet this round (stake x the
        // engine's absolute max multiplier, not the eventual — still secret — crash
        // point). BalancedHybridCrashStrategy compares this against a Kelly-style cap
        // before accepting each new bet; PlaceCrashBet increments it in the same
        // transaction it already commits a bet in.
        Schema::table('crashRound', function (Blueprint $table) {
            $table->unsignedBigInteger('exposureKobo')->default(0)->after('crashMultiplierHundredths');
        });
    }

    public function down(): void
    {
        Schema::table('crashRound', function (Blueprint $table) {
            $table->dropColumn('exposureKobo');
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter GameEconomicsConfigMigrationTest`
Expected: PASS (both methods)

- [ ] **Step 5: Commit**

```bash
git add apps/platform/database/migrations/2026_09_14_000200_add_exposure_kobo_to_crash_round_table.php apps/platform/tests/Feature/GameEconomicsConfigMigrationTest.php
git commit -m "feat: add exposureKobo tracking column to crashRound"
```

---

## Task 3: `GameEconomicsConfig` model

**Files:**
- Create: `apps/platform/app/Models/GameEconomicsConfig.php`
- Test: `apps/platform/tests/Feature/GameEconomicsConfigModelTest.php`

**Interfaces:**
- Produces: `App\Models\GameEconomicsConfig` with `$gameCode, $version, $status, $activeModel` (string) and `$paramsJson` (array, cast).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GameEconomicsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GameEconomicsConfigModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_params_json_round_trips_as_an_array(): void
    {
        $config = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED',
            'version' => 'GEC-TEST-1',
            'status' => 'draft',
            'activeModel' => 'BALANCED_HYBRID',
            'paramsJson' => ['kelly_factor_basis_points' => 300],
            'effectiveAt' => now(),
        ]);

        $fresh = GameEconomicsConfig::findOrFail($config->id);

        $this->assertSame(['kelly_factor_basis_points' => 300], $fresh->paramsJson);
        $this->assertSame('BALANCED_HYBRID', $fresh->activeModel);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GameEconomicsConfigModelTest`
Expected: FAIL — class `App\Models\GameEconomicsConfig` not found.

- [ ] **Step 3: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameEconomicsConfig extends Model
{
    protected $table = 'gameEconomicsConfig';

    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id'];

    protected $casts = [
        'paramsJson' => 'array',
        'effectiveAt' => 'datetime',
        'publishedAt' => 'datetime',
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter GameEconomicsConfigModelTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Models/GameEconomicsConfig.php apps/platform/tests/Feature/GameEconomicsConfigModelTest.php
git commit -m "feat: add GameEconomicsConfig model"
```

---

## Task 4: `RtpCeiling` shared constant

**Files:**
- Create: `apps/platform/app/Domain/Games/Economics/RtpCeiling.php`
- Test: `apps/platform/tests/Unit/RtpCeilingTest.php`

**Interfaces:**
- Produces: `App\Domain\Games\Economics\RtpCeiling::BASIS_POINTS` (int, 8800).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\RtpCeiling;
use Tests\TestCase;

final class RtpCeilingTest extends TestCase
{
    public function test_the_platform_wide_rtp_ceiling_is_8800_basis_points(): void
    {
        $this->assertSame(8_800, RtpCeiling::BASIS_POINTS);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter RtpCeilingTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the class**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/**
 * The single, platform-wide RTP ceiling every publication gate enforces —
 * PrizeTablePublicationGate, HeritagePrizeTablePublicationGate,
 * CagedPrizeTablePublicationGate, CrashConfigPublicationGate. One shared
 * constant instead of four duplicated literals, so this number is changed in
 * exactly one place. 88%, tightened down from the platform's original 95%.
 */
final class RtpCeiling
{
    public const BASIS_POINTS = 8_800;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter RtpCeilingTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/Economics/RtpCeiling.php apps/platform/tests/Unit/RtpCeilingTest.php
git commit -m "feat: add shared RtpCeiling constant (8800bp)"
```

---

## Task 5: Apply the 8800bp ceiling to `PrizeTablePublicationGate`

**Files:**
- Modify: `apps/platform/app/Domain/Games/PrizeTable/PrizeTablePublicationGate.php`
- Modify: `apps/platform/app/Domain/Games/PrizeTable/PrizeTablePresetLibrary.php` (doc comment only)
- Modify: `apps/platform/tests/Feature/PrizeTablePublicationGateTest.php:68`

**Interfaces:**
- Consumes: `RtpCeiling::BASIS_POINTS` (Task 4).

- [ ] **Step 1: Update the test's expected ceiling text**

In `apps/platform/tests/Feature/PrizeTablePublicationGateTest.php`, change line 68:

```php
        $this->assertStringContainsString('exceeds the 9500bp ceiling', implode(' ', $errors));
```

to:

```php
        $this->assertStringContainsString('exceeds the 8800bp ceiling', implode(' ', $errors));
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter PrizeTablePublicationGateTest`
Expected: FAIL — the gate still emits "9500bp".

- [ ] **Step 3: Edit the gate to use the shared constant**

In `apps/platform/app/Domain/Games/PrizeTable/PrizeTablePublicationGate.php`, add the import:

```php
use App\Domain\Games\Economics\RtpCeiling;
use App\Models\PrizeTable;
```

Remove the local constant:

```php
    private const RTP_CEILING_BASIS_POINTS = 9500; // 95% (REQ-GEC-023)
```

Replace the ceiling check block:

```php
            if ($grossRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: gross RTP {$grossRtpBasisPoints}bp exceeds the 9500bp ceiling.";
            }
            if ($netRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: net-of-WHT RTP {$netRtpBasisPoints}bp exceeds the 9500bp ceiling.";
            }
```

with:

```php
            if ($grossRtpBasisPoints > RtpCeiling::BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: gross RTP {$grossRtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
            }
            if ($netRtpBasisPoints > RtpCeiling::BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: net-of-WHT RTP {$netRtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
            }
```

- [ ] **Step 4: Document the preset consequence**

The "good" preset's tier-1 (92.50% RTP) and tier-2 (90.00% RTP) now exceed 8800bp — nothing currently published is affected (the ceiling only gates new drafts), but a *new* "good"-preset draft will now fail on those two tiers until the multipliers are recalibrated, which is a business/actuarial call, not something to silently change here. Document it exactly like the existing Heritage 53%-vs-81.6% discrepancy is documented. In `apps/platform/app/Domain/Games/PrizeTable/PrizeTablePresetLibrary.php`, change:

```php
 * - "good" is the approved Betplus design target (Betplus_PRD.md §8.4): margin rises
 *   with variance, 7.5%-18.7% house edge.
```

to:

```php
 * - "good" is the approved Betplus design target (Betplus_PRD.md §8.4): margin rises
 *   with variance, 7.5%-18.7% house edge.
 *   ⚠ Tiers 1 (92.50% RTP) and 2 (90.00% RTP) now exceed the 88% RTP ceiling
 *   (RtpCeiling::BASIS_POINTS) introduced 2026-09-14 — a new "good"-preset draft
 *   will fail PrizeTablePublicationGate on those two tiers until Finance/actuary
 *   recalibrates the multipliers. Tiers 3-5 (87.5%, 84.375%, 81.25%) are unaffected.
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter PrizeTablePublicationGateTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Domain/Games/PrizeTable/PrizeTablePublicationGate.php apps/platform/app/Domain/Games/PrizeTable/PrizeTablePresetLibrary.php apps/platform/tests/Feature/PrizeTablePublicationGateTest.php
git commit -m "feat: enforce 8800bp RTP ceiling in PrizeTablePublicationGate"
```

---

## Task 6: Apply the ceiling to `HeritagePrizeTablePublicationGate`

**Files:**
- Modify: `apps/platform/app/Domain/Games/PrizeTable/HeritagePrizeTablePublicationGate.php`
- Modify: `apps/platform/tests/Feature/HeritagePrizeTablePublicationGateTest.php:64`

**Interfaces:**
- Consumes: `RtpCeiling::BASIS_POINTS` (Task 4).

- [ ] **Step 1: Update the test's comment**

In `apps/platform/tests/Feature/HeritagePrizeTablePublicationGateTest.php`, change line 64:

```php
        // 5000 + 300 = 5300bp (53.0%) — well under the 9500bp ceiling.
```

to:

```php
        // 5000 + 300 = 5300bp (53.0%) — well under the 8800bp ceiling.
```

(This is a comment, not an assertion — the test's numeric behavior is unaffected either way, since 5300bp is under both 9500bp and 8800bp.)

- [ ] **Step 2: Run test to verify it still passes (no assertion changed)**

Run: `php artisan test --filter HeritagePrizeTablePublicationGateTest`
Expected: PASS (unchanged — confirms nothing else in this file hardcodes the old ceiling).

- [ ] **Step 3: Edit the gate to use the shared constant**

In `apps/platform/app/Domain/Games/PrizeTable/HeritagePrizeTablePublicationGate.php`, add the import:

```php
use App\Domain\Games\Economics\RtpCeiling;
use App\Models\PrizeTable;
```

Remove the local constant:

```php
    private const RTP_CEILING_BASIS_POINTS = 9_500; // 95% (REQ-GEC-023)
```

Replace:

```php
        if ($grossRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
            $errors[] = "Gross RTP {$grossRtpBasisPoints}bp exceeds the 9500bp ceiling.";
        }
        if ($netRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
            $errors[] = "Net-of-WHT RTP {$netRtpBasisPoints}bp exceeds the 9500bp ceiling.";
        }
```

with:

```php
        if ($grossRtpBasisPoints > RtpCeiling::BASIS_POINTS) {
            $errors[] = "Gross RTP {$grossRtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
        }
        if ($netRtpBasisPoints > RtpCeiling::BASIS_POINTS) {
            $errors[] = "Net-of-WHT RTP {$netRtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
        }
```

- [ ] **Step 4: Run test to verify it still passes**

Run: `php artisan test --filter HeritagePrizeTablePublicationGateTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/PrizeTable/HeritagePrizeTablePublicationGate.php apps/platform/tests/Feature/HeritagePrizeTablePublicationGateTest.php
git commit -m "feat: enforce 8800bp RTP ceiling in HeritagePrizeTablePublicationGate"
```

---

## Task 7: Apply the ceiling to `CagedPrizeTablePublicationGate`

**Files:**
- Modify: `apps/platform/app/Domain/Games/PrizeTable/CagedPrizeTablePublicationGate.php`

**Interfaces:**
- Consumes: `RtpCeiling::BASIS_POINTS` (Task 4).

- [ ] **Step 1: Run the existing test suite as a baseline**

Run: `php artisan test --filter CagedPrizeTablePublicationGateTest`
Expected: PASS (baseline — this file has no "9500bp" string assertions, confirmed by repo-wide search, so no test text needs updating here).

- [ ] **Step 2: Edit the gate to use the shared constant**

In `apps/platform/app/Domain/Games/PrizeTable/CagedPrizeTablePublicationGate.php`, add the import:

```php
use App\Domain\Games\Economics\RtpCeiling;
use App\Models\PrizeTable;
```

Remove the local constant:

```php
    private const RTP_CEILING_BASIS_POINTS = 9500; // 95%, same ceiling as every other gate
```

Replace:

```php
            if ($grossRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: gross RTP {$grossRtpBasisPoints}bp exceeds the 9500bp ceiling.";
            }
            if ($netRtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: net-of-WHT RTP {$netRtpBasisPoints}bp exceeds the 9500bp ceiling.";
            }
```

with:

```php
            if ($grossRtpBasisPoints > RtpCeiling::BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: gross RTP {$grossRtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
            }
            if ($netRtpBasisPoints > RtpCeiling::BASIS_POINTS) {
                $errors[] = "Tier {$tier->positions}: net-of-WHT RTP {$netRtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
            }
```

- [ ] **Step 3: Run test to verify it still passes**

Run: `php artisan test --filter CagedPrizeTablePublicationGateTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add apps/platform/app/Domain/Games/PrizeTable/CagedPrizeTablePublicationGate.php
git commit -m "feat: enforce 8800bp RTP ceiling in CagedPrizeTablePublicationGate"
```

---

## Task 8: Apply the ceiling to `CrashConfigPublicationGate`

**Files:**
- Modify: `apps/platform/app/Domain/Games/BirdEscape/CrashConfigPublicationGate.php`
- Modify: `apps/platform/tests/Feature/BackOfficeCrashConfigTest.php:101`
- Modify: `apps/platform/tests/Feature/BackOfficeOperationsTest.php:234`

**Interfaces:**
- Consumes: `RtpCeiling::BASIS_POINTS` (Task 4).

- [ ] **Step 1: Update both test assertions**

In `apps/platform/tests/Feature/BackOfficeCrashConfigTest.php`, change line 101:

```php
        $this->assertStringContainsString('exceeds the 9500bp ceiling', $response->json('gate_errors.0'));
```

to:

```php
        $this->assertStringContainsString('exceeds the 8800bp ceiling', $response->json('gate_errors.0'));
```

In `apps/platform/tests/Feature/BackOfficeOperationsTest.php`, change line 234 the same way (`9500bp` → `8800bp`).

- [ ] **Step 2: Run both tests to verify they fail**

Run: `php artisan test --filter BackOfficeCrashConfigTest`
Run: `php artisan test --filter BackOfficeOperationsTest`
Expected: FAIL — the gate still emits "9500bp" (note: the crash game's "fair" preset is 0bp house edge = 10000bp RTP either way, so this failure is purely about the message text, not the pass/fail verdict).

- [ ] **Step 3: Edit the gate to use the shared constant**

In `apps/platform/app/Domain/Games/BirdEscape/CrashConfigPublicationGate.php`, add the import:

```php
use App\Domain\Games\Economics\RtpCeiling;
use App\Models\CrashConfig;
```

Remove the local constant:

```php
    private const RTP_CEILING_BASIS_POINTS = 9500; // 95% (same ceiling as BlackRed's prize table gate)
```

Replace:

```php
        $rtpBasisPoints = 10_000 - $config->houseEdgeBasisPoints;
        if ($rtpBasisPoints > self::RTP_CEILING_BASIS_POINTS) {
            $errors[] = "Modelled RTP {$rtpBasisPoints}bp exceeds the 9500bp ceiling.";
        }
```

with:

```php
        $rtpBasisPoints = 10_000 - $config->houseEdgeBasisPoints;
        if ($rtpBasisPoints > RtpCeiling::BASIS_POINTS) {
            $errors[] = "Modelled RTP {$rtpBasisPoints}bp exceeds the " . RtpCeiling::BASIS_POINTS . 'bp ceiling.';
        }
```

Note: BirdEscape/Caged-web's own seeded config already sits at 5% house edge (95% RTP), which itself now exceeds the new 8800bp ceiling — same documented, not-silently-fixed situation as BlackRed's "good" preset (Task 5) and Heritage's 53% (already documented). Nothing currently published is affected; only a *new* draft at the current house edge would now fail.

- [ ] **Step 4: Run both tests to verify they pass**

Run: `php artisan test --filter BackOfficeCrashConfigTest`
Run: `php artisan test --filter BackOfficeOperationsTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/BirdEscape/CrashConfigPublicationGate.php apps/platform/tests/Feature/BackOfficeCrashConfigTest.php apps/platform/tests/Feature/BackOfficeOperationsTest.php
git commit -m "feat: enforce 8800bp RTP ceiling in CrashConfigPublicationGate"
```

---

## Task 9: `BalancedHybridParams` value object

**Files:**
- Create: `apps/platform/app/Domain/Games/Economics/BalancedHybridParams.php`
- Test: `apps/platform/tests/Unit/BalancedHybridParamsTest.php`

**Interfaces:**
- Produces: `BalancedHybridParams::fromArray(array $data): self`, `$kellyFactorBasisPoints` (int, readonly).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\BalancedHybridParams;
use Tests\TestCase;

final class BalancedHybridParamsTest extends TestCase
{
    public function test_reads_the_kelly_factor_from_the_params_array(): void
    {
        $params = BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 300]);

        $this->assertSame(300, $params->kellyFactorBasisPoints);
    }

    public function test_defaults_to_zero_when_the_key_is_missing(): void
    {
        $params = BalancedHybridParams::fromArray([]);

        $this->assertSame(0, $params->kellyFactorBasisPoints);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BalancedHybridParamsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the value object**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/** Model 1's Phase-1 lever: a Kelly-style stake/exposure cap as a fraction of the current float. */
final class BalancedHybridParams
{
    private function __construct(public readonly int $kellyFactorBasisPoints)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['kelly_factor_basis_points'] ?? 0));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter BalancedHybridParamsTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/Economics/BalancedHybridParams.php apps/platform/tests/Unit/BalancedHybridParamsTest.php
git commit -m "feat: add BalancedHybridParams value object"
```

---

## Task 10: `GameEconomicsModelGate`

**Files:**
- Create: `apps/platform/app/Domain/Games/Economics/GameEconomicsModelGate.php`
- Test: `apps/platform/tests/Unit/GameEconomicsModelGateTest.php`

**Interfaces:**
- Consumes: `App\Models\GameEconomicsConfig` (Task 3).
- Produces: `GameEconomicsModelGate::validate(GameEconomicsConfig $config): array` (`list<string>`, empty = publishable).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\GameEconomicsModelGate;
use App\Models\GameEconomicsConfig;
use Tests\TestCase;

final class GameEconomicsModelGateTest extends TestCase
{
    private function config(string $activeModel, array $params = []): GameEconomicsConfig
    {
        $config = new GameEconomicsConfig();
        $config->activeModel = $activeModel;
        $config->paramsJson = $params;

        return $config;
    }

    public function test_fixed_rtp_needs_no_params(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('FIXED_RTP'));

        $this->assertEmpty($errors);
    }

    public function test_balanced_hybrid_requires_a_kelly_factor_within_range(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('BALANCED_HYBRID', ['kelly_factor_basis_points' => 300]));

        $this->assertEmpty($errors);
    }

    public function test_balanced_hybrid_without_a_kelly_factor_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('BALANCED_HYBRID'));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('kelly_factor_basis_points', $errors[0]);
    }

    public function test_balanced_hybrid_with_an_out_of_range_kelly_factor_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('BALANCED_HYBRID', ['kelly_factor_basis_points' => 5000]));

        $this->assertNotEmpty($errors);
    }

    public function test_an_unknown_model_fails(): void
    {
        $errors = app(GameEconomicsModelGate::class)->validate($this->config('NOT_A_REAL_MODEL'));

        $this->assertNotEmpty($errors);
    }

    public function test_daily_loss_stop_and_pari_mutuel_pool_are_selectable_with_no_params_validated_yet(): void
    {
        $this->assertEmpty(app(GameEconomicsModelGate::class)->validate($this->config('DAILY_LOSS_STOP')));
        $this->assertEmpty(app(GameEconomicsModelGate::class)->validate($this->config('PARI_MUTUEL_POOL')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GameEconomicsModelGateTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the gate**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Models\GameEconomicsConfig;

/**
 * The structural half of GameEconomicsConfig's publication gate, same spirit as
 * PrizeTablePublicationGate/CrashConfigPublicationGate. This config doesn't carry
 * odds itself (the RTP ceiling is enforced where odds actually live — the four
 * PrizeTable*/CrashConfig gates), so this only validates that the chosen model's
 * param shape is sane.
 */
final class GameEconomicsModelGate
{
    private const VALID_MODELS = ['FIXED_RTP', 'BALANCED_HYBRID', 'DAILY_LOSS_STOP', 'PARI_MUTUEL_POOL'];
    private const MIN_KELLY_FACTOR_BASIS_POINTS = 1;
    // 20% of the current float — generous headroom above the 1-5% (100-500bp) the
    // design spec suggests as a working default, without leaving the field unbounded.
    private const MAX_KELLY_FACTOR_BASIS_POINTS = 2_000;

    /** @return list<string> validation errors; empty means the config may publish */
    public function validate(GameEconomicsConfig $config): array
    {
        if (!in_array($config->activeModel, self::VALID_MODELS, true)) {
            return ["activeModel '{$config->activeModel}' is not one of: " . implode(', ', self::VALID_MODELS) . '.'];
        }

        if ($config->activeModel === 'BALANCED_HYBRID') {
            return $this->validateBalancedHybrid($config);
        }

        // DAILY_LOSS_STOP and PARI_MUTUEL_POOL params are not validated yet,
        // deliberately — their real param shapes (DailyLossStopParams,
        // PariMutuelPoolParams) and enforcement land in Phase 2 and Phase 3 of
        // docs/superpowers/specs/2026-09-13-admin-gaming-economics-models-design.md.
        // Selecting either model today is allowed; EconomicsModelStrategyFactory
        // resolves both to a no-op strategy until then.
        return [];
    }

    /** @return list<string> */
    private function validateBalancedHybrid(GameEconomicsConfig $config): array
    {
        $kellyFactorBasisPoints = $config->paramsJson['kelly_factor_basis_points'] ?? null;

        if (!is_int($kellyFactorBasisPoints)) {
            return ['BALANCED_HYBRID requires an integer kelly_factor_basis_points param.'];
        }

        if ($kellyFactorBasisPoints < self::MIN_KELLY_FACTOR_BASIS_POINTS || $kellyFactorBasisPoints > self::MAX_KELLY_FACTOR_BASIS_POINTS) {
            return ["kelly_factor_basis_points {$kellyFactorBasisPoints} is outside the sane " . self::MIN_KELLY_FACTOR_BASIS_POINTS . '-' . self::MAX_KELLY_FACTOR_BASIS_POINTS . ' range.'];
        }

        return [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter GameEconomicsModelGateTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/Economics/GameEconomicsModelGate.php apps/platform/tests/Unit/GameEconomicsModelGateTest.php
git commit -m "feat: add GameEconomicsModelGate"
```

---

## Task 11: `EconomicsConfigResolver`

**Files:**
- Create: `apps/platform/app/Domain/Games/Economics/EconomicsConfigResolver.php`
- Test: `apps/platform/tests/Feature/EconomicsConfigResolverTest.php`

**Interfaces:**
- Consumes: `App\Models\GameEconomicsConfig` (Task 3).
- Produces: `EconomicsConfigResolver::resolveFor(string $gameCode, ?CarbonInterface $at = null): ?GameEconomicsConfig`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Models\GameEconomicsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EconomicsConfigResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_nothing_is_published(): void
    {
        $this->assertNull(app(EconomicsConfigResolver::class)->resolveFor('BLACKRED'));
    }

    public function test_ignores_drafts_and_returns_the_latest_published_row(): void
    {
        GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-DRAFT', 'status' => 'draft',
            'activeModel' => 'FIXED_RTP', 'paramsJson' => [], 'effectiveAt' => now()->subDay(),
        ]);
        GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-OLD', 'status' => 'published',
            'activeModel' => 'FIXED_RTP', 'paramsJson' => [], 'effectiveAt' => now()->subDays(2),
        ]);
        $newest = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-NEW', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => 300], 'effectiveAt' => now()->subDay(),
        ]);

        $resolved = app(EconomicsConfigResolver::class)->resolveFor('BLACKRED');

        $this->assertSame($newest->id, $resolved->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter EconomicsConfigResolverTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the resolver**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Models\GameEconomicsConfig;
use Carbon\CarbonInterface;

/** Mirrors CrashConfigResolver/PrizeTableResolver's resolution shape exactly: latest published config effective by now. */
final class EconomicsConfigResolver
{
    public function resolveFor(string $gameCode, ?CarbonInterface $at = null): ?GameEconomicsConfig
    {
        $at ??= now();

        return GameEconomicsConfig::where('gameCode', $gameCode)
            ->where('status', 'published')
            ->where('effectiveAt', '<=', $at)
            ->orderByDesc('effectiveAt')
            ->first();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter EconomicsConfigResolverTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/Economics/EconomicsConfigResolver.php apps/platform/tests/Feature/EconomicsConfigResolverTest.php
git commit -m "feat: add EconomicsConfigResolver"
```

---

## Task 12: `EconomicsContext`, `EconomicsModelStrategy`, `FixedRtpStrategy`

**Files:**
- Create: `apps/platform/app/Domain/Games/Economics/EconomicsContext.php`
- Create: `apps/platform/app/Domain/Games/Economics/EconomicsModelStrategy.php`
- Create: `apps/platform/app/Domain/Games/Economics/FixedRtpStrategy.php`
- Test: `apps/platform/tests/Unit/FixedRtpStrategyTest.php`

**Interfaces:**
- Produces: `EconomicsContext::forTicket(): self`, `EconomicsContext::forCrashRound(CrashRound $round): self`, `$crashRound` (nullable, readonly). `EconomicsModelStrategy::assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void` (throws `TicketEligibilityException`). `FixedRtpStrategy` implements it as a no-op.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\FixedRtpStrategy;
use Tests\TestCase;

final class FixedRtpStrategyTest extends TestCase
{
    public function test_never_rejects_any_stake(): void
    {
        $strategy = new FixedRtpStrategy();

        $strategy->assertAcceptable('BLACKRED', 1_000_000_000, EconomicsContext::forTicket());

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter FixedRtpStrategyTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write `EconomicsContext`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Models\CrashRound;

/**
 * What an EconomicsModelStrategy needs beyond gameCode/stakeKobo, carrying the one
 * thing that differs by engine shape: the crash engine's strategies need the live
 * round (to read/grow its exposureKobo); the three ticket engines don't have a round
 * at all.
 */
final class EconomicsContext
{
    private function __construct(public readonly ?CrashRound $crashRound)
    {
    }

    public static function forTicket(): self
    {
        return new self(null);
    }

    public static function forCrashRound(CrashRound $round): self
    {
        return new self($round);
    }
}
```

- [ ] **Step 4: Write `EconomicsModelStrategy`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Ticket\TicketEligibilityException;

/**
 * One implementation per economics model (REQ-BO-015-style registration, same spirit
 * as ReviewableChangeApplier: implement this, then wire it into
 * EconomicsModelStrategyFactory). Resolved fresh at ticket/bet-creation time via
 * EconomicsConfigResolver, injected at the same call site LimitsService's own
 * assertStakeWithinLimits already runs from.
 */
interface EconomicsModelStrategy
{
    /** @throws TicketEligibilityException */
    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void;
}
```

- [ ] **Step 5: Write `FixedRtpStrategy`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

/**
 * Model 2 (Fixed RTP) — no acceptance-time throttling beyond the RTP ceiling already
 * enforced when the game's PrizeTable/CrashConfig was published. Also the safe
 * default when no GameEconomicsConfig has been published yet for a game (today's
 * behaviour, unchanged), and the temporary stand-in for DAILY_LOSS_STOP and
 * PARI_MUTUEL_POOL until Phase 2/3 build their real strategies
 * (EconomicsModelStrategyFactory's match default).
 */
final class FixedRtpStrategy implements EconomicsModelStrategy
{
    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void
    {
        // Deliberately empty.
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter FixedRtpStrategyTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add apps/platform/app/Domain/Games/Economics/EconomicsContext.php apps/platform/app/Domain/Games/Economics/EconomicsModelStrategy.php apps/platform/app/Domain/Games/Economics/FixedRtpStrategy.php apps/platform/tests/Unit/FixedRtpStrategyTest.php
git commit -m "feat: add EconomicsContext, EconomicsModelStrategy interface, FixedRtpStrategy"
```

---

## Task 13: `FloatService::currentFloatKobo()`

**Files:**
- Modify: `apps/platform/app/Domain/Payout/Float/FloatService.php`
- Test: `apps/platform/tests/Feature/FloatServiceTest.php`

**Interfaces:**
- Produces: `FloatService::currentFloatKobo(): int` — the latest polled OPay balance, or 0 if none has ever been polled.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payout\Float\FloatService;
use App\Models\FloatSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FloatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zero_when_no_snapshot_has_ever_been_taken(): void
    {
        $this->assertSame(0, app(FloatService::class)->currentFloatKobo());
    }

    public function test_returns_the_most_recently_polled_balance(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 10_000_000, 'alertState' => 'ok', 'polledAt' => now()->subMinutes(10)]);
        FloatSnapshot::create(['opayBalanceKobo' => 20_000_000, 'alertState' => 'ok', 'polledAt' => now()]);

        $this->assertSame(20_000_000, app(FloatService::class)->currentFloatKobo());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter FloatServiceTest`
Expected: FAIL — method does not exist.

- [ ] **Step 3: Add the method**

In `apps/platform/app/Domain/Payout/Float/FloatService.php`, add this method after `latestAlertState()`:

```php
    /** Model 1's Kelly-style stake/exposure caps read this — the current OPay float, in kobo. */
    public function currentFloatKobo(): int
    {
        $latest = FloatSnapshot::orderByDesc('polledAt')->first();

        return $latest?->opayBalanceKobo ?? 0;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter FloatServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Payout/Float/FloatService.php apps/platform/tests/Feature/FloatServiceTest.php
git commit -m "feat: add FloatService::currentFloatKobo()"
```

---

## Task 14: `BirdEscapeEngine::worstCaseLiabilityKobo()`

**Files:**
- Modify: `apps/platform/app/Domain/Games/Engine/BirdEscape/BirdEscapeEngine.php`
- Test: `apps/platform/tests/Unit/BirdEscapeEngineWorstCaseLiabilityTest.php`

**Interfaces:**
- Produces: `BirdEscapeEngine::worstCaseLiabilityKobo(int $stakeKobo): int` (static).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Engine\BirdEscape\BirdEscapeEngine;
use Tests\TestCase;

final class BirdEscapeEngineWorstCaseLiabilityTest extends TestCase
{
    public function test_worst_case_liability_is_stake_times_the_absolute_max_multiplier(): void
    {
        // 200,000 kobo staked, worst case pays at the 35.00x absolute ceiling.
        $this->assertSame(7_000_000, BirdEscapeEngine::worstCaseLiabilityKobo(200_000));
    }

    public function test_zero_stake_has_zero_worst_case_liability(): void
    {
        $this->assertSame(0, BirdEscapeEngine::worstCaseLiabilityKobo(0));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BirdEscapeEngineWorstCaseLiabilityTest`
Expected: FAIL — method does not exist.

- [ ] **Step 3: Add the method**

In `apps/platform/app/Domain/Games/Engine/BirdEscape/BirdEscapeEngine.php`, find:

```php
    public const ABSOLUTE_MAX_MULTIPLIER_HUNDREDTHS = 3500; // 35.00x absolute ceiling
    public const DEFAULT_MAX_CAP_HUNDREDTHS = 2500; // 25.00x regular cap
    public const MAX_MULTIPLIER_HUNDREDTHS = 3500;
```

and add, directly after it:

```php

    /**
     * Worst-case liability a single bet could ever create for the house — stake paid
     * out at the absolute maximum crash multiplier, regardless of what this round
     * actually crashes at (which the bettor never sees before it happens). Used by
     * BalancedHybridCrashStrategy to project a round's aggregate exposure — real
     * disclosed math, not privileged information about the actual crash point.
     */
    public static function worstCaseLiabilityKobo(int $stakeKobo): int
    {
        return intdiv($stakeKobo * self::ABSOLUTE_MAX_MULTIPLIER_HUNDREDTHS, 100);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter BirdEscapeEngineWorstCaseLiabilityTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/Engine/BirdEscape/BirdEscapeEngine.php apps/platform/tests/Unit/BirdEscapeEngineWorstCaseLiabilityTest.php
git commit -m "feat: add BirdEscapeEngine::worstCaseLiabilityKobo()"
```

---

## Task 15: `BalancedHybridTicketStrategy`

**Files:**
- Create: `apps/platform/app/Domain/Games/Economics/BalancedHybridTicketStrategy.php`
- Test: `apps/platform/tests/Feature/BalancedHybridTicketStrategyTest.php`

**Interfaces:**
- Consumes: `FloatService::currentFloatKobo()` (Task 13), `BalancedHybridParams` (Task 9), `EconomicsModelStrategy` (Task 12).
- Produces: rejects with `TicketEligibilityException('KELLY_STAKE_CAP', ...)` when `stakeKobo > floatKobo * kellyFactorBasisPoints / 10000`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\BalancedHybridParams;
use App\Domain\Games\Economics\BalancedHybridTicketStrategy;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Payout\Float\FloatService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BalancedHybridTicketStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_a_stake_within_the_kelly_cap(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $strategy = new BalancedHybridTicketStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        // Cap = 100,000,000 * 500 / 10,000 = 5,000,000 kobo.
        $strategy->assertAcceptable('BLACKRED', 4_000_000, EconomicsContext::forTicket());

        $this->addToAssertionCount(1);
    }

    public function test_rejects_a_stake_over_the_kelly_cap(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $strategy = new BalancedHybridTicketStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        $this->expectException(TicketEligibilityException::class);
        $strategy->assertAcceptable('BLACKRED', 6_000_000, EconomicsContext::forTicket());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BalancedHybridTicketStrategyTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the strategy**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Payout\Float\FloatService;
use App\Domain\Ticket\TicketEligibilityException;

/** Model 1's per-ticket lever for BlackRed, Heritage and Caged-USSD: maxStake = k x currentFloat. */
final class BalancedHybridTicketStrategy implements EconomicsModelStrategy
{
    public function __construct(
        private readonly FloatService $float,
        private readonly BalancedHybridParams $params,
    ) {
    }

    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void
    {
        $capKobo = intdiv($this->float->currentFloatKobo() * $this->params->kellyFactorBasisPoints, 10_000);

        if ($stakeKobo > $capKobo) {
            throw new TicketEligibilityException(
                'KELLY_STAKE_CAP',
                "Stake exceeds the current Kelly-style cap of {$capKobo} kobo for {$gameCode}.",
            );
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter BalancedHybridTicketStrategyTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/Economics/BalancedHybridTicketStrategy.php apps/platform/tests/Feature/BalancedHybridTicketStrategyTest.php
git commit -m "feat: add BalancedHybridTicketStrategy"
```

---

## Task 16: `BalancedHybridCrashStrategy`

**Files:**
- Create: `apps/platform/app/Domain/Games/Economics/BalancedHybridCrashStrategy.php`
- Test: `apps/platform/tests/Feature/BalancedHybridCrashStrategyTest.php`

**Interfaces:**
- Consumes: `FloatService::currentFloatKobo()` (Task 13), `BalancedHybridParams` (Task 9), `BirdEscapeEngine::worstCaseLiabilityKobo()` (Task 14), `App\Models\CrashRound.exposureKobo` (Task 2).
- Produces: rejects with `TicketEligibilityException('ROUND_EXPOSURE_CAP', ...)` when `round.exposureKobo + worstCaseLiabilityKobo(stake) > floatKobo * kellyFactorBasisPoints / 10000`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\Economics\BalancedHybridCrashStrategy;
use App\Domain\Games\Economics\BalancedHybridParams;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\BirdEscape\RoundLifecycleService;
use App\Domain\Payout\Float\FloatService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use Database\Seeders\BirdEscapeGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BalancedHybridCrashStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BirdEscapeGameSeeder::class);
    }

    public function test_accepts_a_bet_whose_worst_case_exposure_stays_under_the_cap(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $strategy = new BalancedHybridCrashStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        // Cap = 100,000,000 * 500 / 10,000 = 5,000,000 kobo.
        // Worst case for a 10,000 kobo stake = 10,000 * 35 = 350,000 kobo.
        $strategy->assertAcceptable('BIRDESCAPE', 10_000, EconomicsContext::forCrashRound($round));

        $this->addToAssertionCount(1);
    }

    public function test_rejects_a_bet_whose_worst_case_exposure_would_exceed_the_cap(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $strategy = new BalancedHybridCrashStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        // Worst case for a 200,000 kobo stake = 200,000 * 35 = 7,000,000 kobo > 5,000,000 cap.
        $this->expectException(TicketEligibilityException::class);
        $strategy->assertAcceptable('BIRDESCAPE', 200_000, EconomicsContext::forCrashRound($round));
    }

    public function test_accounts_for_the_rounds_existing_exposure_before_accepting_a_new_bet(): void
    {
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $round->update(['exposureKobo' => 4_900_000]); // already close to the 5,000,000 cap
        $strategy = new BalancedHybridCrashStrategy(app(FloatService::class), BalancedHybridParams::fromArray(['kelly_factor_basis_points' => 500]));

        // Worst case for a 10,000 kobo stake = 350,000 kobo; 4,900,000 + 350,000 > 5,000,000.
        $this->expectException(TicketEligibilityException::class);
        $strategy->assertAcceptable('BIRDESCAPE', 10_000, EconomicsContext::forCrashRound($round));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BalancedHybridCrashStrategyTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the strategy**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Games\Engine\BirdEscape\BirdEscapeEngine;
use App\Domain\Payout\Float\FloatService;
use App\Domain\Ticket\TicketEligibilityException;
use LogicException;

/**
 * Model 1's per-round lever for the crash engine (Caged-web/BirdEscape) — the
 * highest-value, lowest-risk slice identified in docs/game-engine-economics.md
 * Part 5 #1: bounds a live round's worst-case aggregate liability, not any
 * individual round's actual outcome. A bet-acceptance limit, not an odds change.
 */
final class BalancedHybridCrashStrategy implements EconomicsModelStrategy
{
    public function __construct(
        private readonly FloatService $float,
        private readonly BalancedHybridParams $params,
    ) {
    }

    public function assertAcceptable(string $gameCode, int $stakeKobo, EconomicsContext $context): void
    {
        $round = $context->crashRound;
        if ($round === null) {
            throw new LogicException('BalancedHybridCrashStrategy requires a crash-round context.');
        }

        $capKobo = intdiv($this->float->currentFloatKobo() * $this->params->kellyFactorBasisPoints, 10_000);
        $projectedExposureKobo = $round->exposureKobo + BirdEscapeEngine::worstCaseLiabilityKobo($stakeKobo);

        if ($projectedExposureKobo > $capKobo) {
            throw new TicketEligibilityException(
                'ROUND_EXPOSURE_CAP',
                "This round's worst-case exposure would exceed the {$capKobo} kobo cap for {$gameCode}.",
            );
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter BalancedHybridCrashStrategyTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/Economics/BalancedHybridCrashStrategy.php apps/platform/tests/Feature/BalancedHybridCrashStrategyTest.php
git commit -m "feat: add BalancedHybridCrashStrategy"
```

---

## Task 17: `EconomicsModelStrategyFactory`

**Files:**
- Create: `apps/platform/app/Domain/Games/Economics/EconomicsModelStrategyFactory.php`
- Test: `apps/platform/tests/Unit/EconomicsModelStrategyFactoryTest.php`

**Interfaces:**
- Consumes: `FixedRtpStrategy` (Task 12), `BalancedHybridTicketStrategy` (Task 15), `BalancedHybridCrashStrategy` (Task 16), `BalancedHybridParams` (Task 9).
- Produces: `EconomicsModelStrategyFactory::forTicketGame(?GameEconomicsConfig $config): EconomicsModelStrategy`, `::forCrashGame(?GameEconomicsConfig $config): EconomicsModelStrategy`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Games\Economics\BalancedHybridCrashStrategy;
use App\Domain\Games\Economics\BalancedHybridTicketStrategy;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
use App\Domain\Games\Economics\FixedRtpStrategy;
use App\Models\GameEconomicsConfig;
use Tests\TestCase;

final class EconomicsModelStrategyFactoryTest extends TestCase
{
    private function config(string $activeModel): GameEconomicsConfig
    {
        $config = new GameEconomicsConfig();
        $config->activeModel = $activeModel;
        $config->paramsJson = ['kelly_factor_basis_points' => 300];

        return $config;
    }

    public function test_no_published_config_yields_fixed_rtp_for_ticket_games(): void
    {
        $this->assertInstanceOf(FixedRtpStrategy::class, app(EconomicsModelStrategyFactory::class)->forTicketGame(null));
    }

    public function test_fixed_rtp_model_yields_fixed_rtp_strategy(): void
    {
        $this->assertInstanceOf(FixedRtpStrategy::class, app(EconomicsModelStrategyFactory::class)->forTicketGame($this->config('FIXED_RTP')));
    }

    public function test_balanced_hybrid_model_yields_the_ticket_strategy_for_ticket_games(): void
    {
        $this->assertInstanceOf(BalancedHybridTicketStrategy::class, app(EconomicsModelStrategyFactory::class)->forTicketGame($this->config('BALANCED_HYBRID')));
    }

    public function test_balanced_hybrid_model_yields_the_crash_strategy_for_the_crash_game(): void
    {
        $this->assertInstanceOf(BalancedHybridCrashStrategy::class, app(EconomicsModelStrategyFactory::class)->forCrashGame($this->config('BALANCED_HYBRID')));
    }

    public function test_daily_loss_stop_and_pari_mutuel_pool_fall_back_to_fixed_rtp_for_now(): void
    {
        $factory = app(EconomicsModelStrategyFactory::class);
        $this->assertInstanceOf(FixedRtpStrategy::class, $factory->forTicketGame($this->config('DAILY_LOSS_STOP')));
        $this->assertInstanceOf(FixedRtpStrategy::class, $factory->forCrashGame($this->config('PARI_MUTUEL_POOL')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter EconomicsModelStrategyFactoryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the factory**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Games\Economics;

use App\Domain\Payout\Float\FloatService;
use App\Models\GameEconomicsConfig;

/**
 * Resolves the strategy for the currently-published config of one game, split by
 * engine shape (ticket vs. the crash engine's shared round) because Model 1's
 * mechanism differs by shape even though the model is the same. A null config
 * (nothing published yet) and every model without a real strategy yet
 * (DAILY_LOSS_STOP, PARI_MUTUEL_POOL — Phase 2/3) fall back to FixedRtpStrategy.
 */
final class EconomicsModelStrategyFactory
{
    public function __construct(private readonly FloatService $float)
    {
    }

    public function forTicketGame(?GameEconomicsConfig $config): EconomicsModelStrategy
    {
        return match ($config?->activeModel) {
            'BALANCED_HYBRID' => new BalancedHybridTicketStrategy($this->float, BalancedHybridParams::fromArray($config->paramsJson)),
            default => new FixedRtpStrategy(),
        };
    }

    public function forCrashGame(?GameEconomicsConfig $config): EconomicsModelStrategy
    {
        return match ($config?->activeModel) {
            'BALANCED_HYBRID' => new BalancedHybridCrashStrategy($this->float, BalancedHybridParams::fromArray($config->paramsJson)),
            default => new FixedRtpStrategy(),
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter EconomicsModelStrategyFactoryTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Domain/Games/Economics/EconomicsModelStrategyFactory.php apps/platform/tests/Unit/EconomicsModelStrategyFactoryTest.php
git commit -m "feat: add EconomicsModelStrategyFactory"
```

---

## Task 18: Wire into `CreateTicket` (BlackRed)

**Files:**
- Modify: `apps/platform/app/Domain/Ticket/CreateTicket.php`
- Test: `apps/platform/tests/Feature/BlackRedEconomicsCapTest.php`

**Interfaces:**
- Consumes: `EconomicsConfigResolver` (Task 11), `EconomicsModelStrategyFactory` (Task 17), `EconomicsContext::forTicket()` (Task 12).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ticket\CreateTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BlackRedEconomicsCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
        Queue::fake();
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
    }

    private function fundedPlayer(int fundedKobo = 10_000_000): Player
    {
        $player = Player::create([
            'msisdn' => '+2348033234567', 'registeredName' => 'Nkem Obi', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', '+2348033234567'),
        ]);
        SignupSession::create([
            'msisdn' => $player->msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        app(\App\Domain\Wallet\WalletService::class)->creditPlayBalanceFromOpay($player, $fundedKobo, 'collection', $player->id);

        return $player;
    }

    private function publishBalancedHybrid(int $kellyFactorBasisPoints): void
    {
        GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-BR-1', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => $kellyFactorBasisPoints],
            'effectiveAt' => now()->subMinute(), 'publishedAt' => now(),
        ]);
    }

    public function test_a_stake_within_the_kelly_cap_is_accepted(): void
    {
        $this->publishBalancedHybrid(500); // cap = 100,000,000 * 500 / 10,000 = 5,000,000 kobo
        $player = $this->fundedPlayer();

        $ticket = app(CreateTicket::class)->create($player, ['B'], 4_000_000, 'idem-accept-1');

        $this->assertSame('SETTLED', $ticket->status);
    }

    public function test_a_stake_over_the_kelly_cap_is_rejected(): void
    {
        $this->publishBalancedHybrid(500); // cap = 5,000,000 kobo
        $player = $this->fundedPlayer();

        $this->expectException(TicketEligibilityException::class);
        try {
            app(CreateTicket::class)->create($player, ['B'], 6_000_000, 'idem-reject-1');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('KELLY_STAKE_CAP', $e->errorCode);
            throw $e;
        }
    }

    public function test_with_no_published_config_the_kelly_cap_does_not_apply(): void
    {
        $player = $this->fundedPlayer(fundedKobo: 5_000_000);

        $ticket = app(CreateTicket::class)->create($player, ['B'], 2_000_000, 'idem-no-config-1');

        $this->assertSame('SETTLED', $ticket->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BlackRedEconomicsCapTest`
Expected: FAIL — `test_a_stake_over_the_kelly_cap_is_rejected` does not throw (no enforcement wired in yet).

- [ ] **Step 3: Wire the strategy into `CreateTicket`**

In `apps/platform/app/Domain/Ticket/CreateTicket.php`, add the imports:

```php
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
use App\Domain\Games\Engine\BlackRed\BlackRedEngine;
```

Add two constructor parameters (after `LimitsService $limits`):

```php
        private readonly LimitsService $limits,
        private readonly EconomicsConfigResolver $economicsConfigResolver,
        private readonly EconomicsModelStrategyFactory $economicsStrategyFactory,
        private readonly ProtectionService $protection,
```

After the existing outside-transaction line:

```php
        $this->limits->assertStakeWithinLimits($player, $stakeKobo);
```

add:

```php
        $economicsStrategy = $this->economicsStrategyFactory->forTicketGame($this->economicsConfigResolver->resolveFor('BLACKRED'));
        $economicsStrategy->assertAcceptable('BLACKRED', $stakeKobo, EconomicsContext::forTicket());
```

After the inside-transaction re-assertion line:

```php
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);
```

add:

```php
            $economicsStrategy->assertAcceptable('BLACKRED', $stakeKobo, EconomicsContext::forTicket());
```

(`$economicsStrategy` is captured from the enclosing scope by the transaction closure's `use (...)` clause — add `$economicsStrategy` to that `use` list alongside the existing variables.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter BlackRedEconomicsCapTest`
Expected: PASS

- [ ] **Step 5: Run the full BlackRed ticket suite to check nothing else broke**

Run: `php artisan test --filter BlackRedTicketTest`
Expected: PASS (no config published in that test's setup, so the strategy resolves to `FixedRtpStrategy` and every existing test behaves exactly as before).

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Domain/Ticket/CreateTicket.php apps/platform/tests/Feature/BlackRedEconomicsCapTest.php
git commit -m "feat: enforce economics model in CreateTicket (BlackRed)"
```

---

## Task 19: Wire into `CreateHeritageTicket`

**Files:**
- Modify: `apps/platform/app/Domain/Ticket/CreateHeritageTicket.php`
- Test: `apps/platform/tests/Feature/HeritageEconomicsCapTest.php`

**Interfaces:**
- Consumes: `EconomicsConfigResolver` (Task 11), `EconomicsModelStrategyFactory` (Task 17), `EconomicsContext::forTicket()` (Task 12).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ticket\CreateHeritageTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\HeritageGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class HeritageEconomicsCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HeritageGameSeeder::class);
        Queue::fake();
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        Http::fake(['*/engine/v1/resolve' => Http::response([
            'outcome_tier' => 'TIER_LOSS',
            'gross_prize_kobo' => 0,
            'engine_version' => 'HG-TEST-1',
            'digest' => 'test-digest',
            'engine_state' => [
                'board' => range(0, 8),
                'winning_positions' => [0, 1, 2, 3, 4],
                'selected_positions' => [0, 1, 2, 3, 4],
                'match_count' => 0,
                'tradition' => 'yoruba',
                'leader_type' => 'king',
                'second_chance_stake_kobo' => null,
            ],
        ], 200)]);
    }

    private function fundedPlayer(int $fundedKobo = 10_000_000): Player
    {
        $player = Player::create([
            'msisdn' => '+2348033234567', 'registeredName' => 'Nkem Obi', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', '+2348033234567'),
        ]);
        SignupSession::create([
            'msisdn' => $player->msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        app(\App\Domain\Wallet\WalletService::class)->creditPlayBalanceFromOpay($player, $fundedKobo, 'collection', $player->id);

        return $player;
    }

    private function publishBalancedHybrid(int $kellyFactorBasisPoints): void
    {
        GameEconomicsConfig::create([
            'gameCode' => 'HERITAGE', 'version' => 'GEC-HG-1', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => $kellyFactorBasisPoints],
            'effectiveAt' => now()->subMinute(), 'publishedAt' => now(),
        ]);
    }

    public function test_a_stake_over_the_kelly_cap_is_rejected(): void
    {
        $this->publishBalancedHybrid(500); // cap = 5,000,000 kobo
        $player = $this->fundedPlayer();

        $this->expectException(TicketEligibilityException::class);
        try {
            app(CreateHeritageTicket::class)->create($player, [0, 1, 2, 3, 4], 6_000_000, 'idem-hg-reject-1', 'yoruba', 'king');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('KELLY_STAKE_CAP', $e->errorCode);
            throw $e;
        }
    }

    public function test_a_stake_within_the_kelly_cap_is_accepted(): void
    {
        $this->publishBalancedHybrid(500);
        $player = $this->fundedPlayer();

        $ticket = app(CreateHeritageTicket::class)->create($player, [0, 1, 2, 3, 4], 4_000_000, 'idem-hg-accept-1', 'yoruba', 'king');

        $this->assertSame('SETTLED', $ticket->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter HeritageEconomicsCapTest`
Expected: FAIL — `test_a_stake_over_the_kelly_cap_is_rejected` does not throw.

- [ ] **Step 3: Wire the strategy into `CreateHeritageTicket`**

In `apps/platform/app/Domain/Ticket/CreateHeritageTicket.php`, add the imports:

```php
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
```

Add two constructor parameters (after `LimitsService $limits`):

```php
        private readonly LimitsService $limits,
        private readonly EconomicsConfigResolver $economicsConfigResolver,
        private readonly EconomicsModelStrategyFactory $economicsStrategyFactory,
        private readonly ProtectionService $protection,
```

After the outside-transaction:

```php
        $this->limits->assertStakeWithinLimits($player, $stakeKobo);
```

add:

```php
        $economicsStrategy = $this->economicsStrategyFactory->forTicketGame($this->economicsConfigResolver->resolveFor(self::GAME_CODE));
        $economicsStrategy->assertAcceptable(self::GAME_CODE, $stakeKobo, EconomicsContext::forTicket());
```

After the inside-transaction re-assertion:

```php
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);
```

add:

```php
            $economicsStrategy->assertAcceptable(self::GAME_CODE, $stakeKobo, EconomicsContext::forTicket());
```

Add `$economicsStrategy` to the transaction closure's `use (...)` list.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter HeritageEconomicsCapTest`
Expected: PASS

- [ ] **Step 5: Run the full Heritage ticket suite to check nothing else broke**

Run: `php artisan test --filter HeritageTicketTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Domain/Ticket/CreateHeritageTicket.php apps/platform/tests/Feature/HeritageEconomicsCapTest.php
git commit -m "feat: enforce economics model in CreateHeritageTicket"
```

---

## Task 20: Wire into `CreateCagedTicket`

**Files:**
- Modify: `apps/platform/app/Domain/Ticket/CreateCagedTicket.php`
- Test: `apps/platform/tests/Feature/CagedEconomicsCapTest.php`

**Interfaces:**
- Consumes: `EconomicsConfigResolver` (Task 11), `EconomicsModelStrategyFactory` (Task 17), `EconomicsContext::forTicket()` (Task 12).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ticket\CreateCagedTicket;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\CagedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CagedEconomicsCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CagedGameSeeder::class);
        Queue::fake();
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
    }

    private function fundedPlayer(int $fundedKobo = 10_000_000): Player
    {
        $player = Player::create([
            'msisdn' => '+2348033234567', 'registeredName' => 'Nkem Obi', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', '+2348033234567'),
        ]);
        SignupSession::create([
            'msisdn' => $player->msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        app(\App\Domain\Wallet\WalletService::class)->creditPlayBalanceFromOpay($player, $fundedKobo, 'collection', $player->id);

        return $player;
    }

    private function publishBalancedHybrid(int $kellyFactorBasisPoints): void
    {
        GameEconomicsConfig::create([
            'gameCode' => 'CAGED', 'version' => 'GEC-CG-1', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => $kellyFactorBasisPoints],
            'effectiveAt' => now()->subMinute(), 'publishedAt' => now(),
        ]);
    }

    public function test_a_stake_over_the_kelly_cap_is_rejected(): void
    {
        $this->publishBalancedHybrid(500); // cap = 5,000,000 kobo
        $player = $this->fundedPlayer();

        $this->expectException(TicketEligibilityException::class);
        try {
            app(CreateCagedTicket::class)->create($player, 2, 6_000_000, 'idem-cg-reject-1');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('KELLY_STAKE_CAP', $e->errorCode);
            throw $e;
        }
    }

    public function test_a_stake_within_the_kelly_cap_is_accepted(): void
    {
        $this->publishBalancedHybrid(500);
        $player = $this->fundedPlayer();

        $ticket = app(CreateCagedTicket::class)->create($player, 2, 4_000_000, 'idem-cg-accept-1');

        $this->assertSame('SETTLED', $ticket->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter CagedEconomicsCapTest`
Expected: FAIL — `test_a_stake_over_the_kelly_cap_is_rejected` does not throw.

- [ ] **Step 3: Wire the strategy into `CreateCagedTicket`**

In `apps/platform/app/Domain/Ticket/CreateCagedTicket.php`, add the imports:

```php
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
```

Add two constructor parameters (after `LimitsService $limits`):

```php
        private readonly LimitsService $limits,
        private readonly EconomicsConfigResolver $economicsConfigResolver,
        private readonly EconomicsModelStrategyFactory $economicsStrategyFactory,
        private readonly ProtectionService $protection,
```

After the outside-transaction:

```php
        $this->limits->assertStakeWithinLimits($player, $stakeKobo);
```

add:

```php
        $economicsStrategy = $this->economicsStrategyFactory->forTicketGame($this->economicsConfigResolver->resolveFor(self::GAME_CODE));
        $economicsStrategy->assertAcceptable(self::GAME_CODE, $stakeKobo, EconomicsContext::forTicket());
```

After the inside-transaction re-assertion:

```php
            $this->limits->assertStakeWithinLimits($player, $stakeKobo);
```

add:

```php
            $economicsStrategy->assertAcceptable(self::GAME_CODE, $stakeKobo, EconomicsContext::forTicket());
```

Add `$economicsStrategy` to the transaction closure's `use (...)` list.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter CagedEconomicsCapTest`
Expected: PASS

- [ ] **Step 5: Run the full Caged ticket suite to check nothing else broke**

Run: `php artisan test --filter CagedTicketTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Domain/Ticket/CreateCagedTicket.php apps/platform/tests/Feature/CagedEconomicsCapTest.php
git commit -m "feat: enforce economics model in CreateCagedTicket"
```

---

## Task 21: Wire into `PlaceCrashBet` + exposure increment

**Files:**
- Modify: `apps/platform/app/Domain/Games/BirdEscape/PlaceCrashBet.php`
- Test: `apps/platform/tests/Feature/BirdEscapeEconomicsCapTest.php`

**Interfaces:**
- Consumes: `EconomicsConfigResolver` (Task 11), `EconomicsModelStrategyFactory` (Task 17), `EconomicsContext::forCrashRound()` (Task 12), `BirdEscapeEngine::worstCaseLiabilityKobo()` (Task 14).
- Produces: `CrashRound.exposureKobo` grows by each accepted bet's worst-case liability.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Games\BirdEscape\PlaceCrashBet;
use App\Domain\Games\BirdEscape\RoundLifecycleService;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\FloatSnapshot;
use App\Models\GameEconomicsConfig;
use App\Models\Player;
use App\Models\SignupSession;
use Database\Seeders\BirdEscapeGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BirdEscapeEconomicsCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BirdEscapeGameSeeder::class);
        FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
        GameEconomicsConfig::create([
            'gameCode' => 'BIRDESCAPE', 'version' => 'GEC-BE-1', 'status' => 'published',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => 500], // cap = 5,000,000 kobo
            'effectiveAt' => now()->subMinute(), 'publishedAt' => now(),
        ]);
    }

    private function fundedPlayer(int $fundedKobo = 10_000_000): Player
    {
        $player = Player::create([
            'msisdn' => '+2348033234567', 'registeredName' => 'Nkem Obi', 'registrationChannel' => 'web',
            'kycTier' => 1, 'ninHash' => hash('sha256', '+2348033234567'),
        ]);
        SignupSession::create([
            'msisdn' => $player->msisdn, 'otpHash' => hash('sha256', '123456'),
            'otpExpiresAt' => now()->addMinutes(5), 'otpVerifiedAt' => now(), 'expiresAt' => now()->addMinutes(5),
        ]);
        app(\App\Domain\Wallet\WalletService::class)->creditPlayBalanceFromOpay($player, $fundedKobo, 'collection', $player->id);

        return $player;
    }

    public function test_a_bet_within_the_exposure_cap_is_accepted_and_grows_exposure(): void
    {
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $player = $this->fundedPlayer();

        // Worst case for 10,000 kobo = 350,000 kobo, well under the 5,000,000 cap.
        app(PlaceCrashBet::class)->place($player, $round, 10_000, null, 'idem-be-accept-1');

        $this->assertSame(350_000, $round->refresh()->exposureKobo);
    }

    public function test_a_bet_over_the_exposure_cap_is_rejected(): void
    {
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $player = $this->fundedPlayer();

        // Worst case for 200,000 kobo = 7,000,000 kobo > 5,000,000 cap.
        $this->expectException(TicketEligibilityException::class);
        try {
            app(PlaceCrashBet::class)->place($player, $round, 200_000, null, 'idem-be-reject-1');
        } catch (TicketEligibilityException $e) {
            $this->assertSame('ROUND_EXPOSURE_CAP', $e->errorCode);
            throw $e;
        }
    }

    public function test_a_second_bet_that_would_push_cumulative_exposure_over_the_cap_is_rejected(): void
    {
        $round = app(RoundLifecycleService::class)->startRound('BIRDESCAPE');
        $player = $this->fundedPlayer();
        // First bet: worst case 3,500,000 kobo (100,000 stake) — accepted, exposure now 3,500,000.
        app(PlaceCrashBet::class)->place($player, $round, 100_000, null, 'idem-be-first-1');

        // Second bet: worst case 1,750,000 kobo (50,000 stake) — 3,500,000 + 1,750,000 = 5,250,000 > 5,000,000 cap.
        $this->expectException(TicketEligibilityException::class);
        app(PlaceCrashBet::class)->place($player, $round, 50_000, null, 'idem-be-second-1');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BirdEscapeEconomicsCapTest`
Expected: FAIL — no enforcement or exposure tracking wired in yet.

- [ ] **Step 3: Wire the strategy and exposure increment into `PlaceCrashBet`**

In `apps/platform/app/Domain/Games/BirdEscape/PlaceCrashBet.php`, add the imports:

```php
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\EconomicsContext;
use App\Domain\Games\Economics\EconomicsModelStrategyFactory;
use App\Domain\Games\Engine\BirdEscape\BirdEscapeEngine;
```

Add two constructor parameters (after `LimitsService $limits`):

```php
        private readonly LimitsService $limits,
        private readonly EconomicsConfigResolver $economicsConfigResolver,
        private readonly EconomicsModelStrategyFactory $economicsStrategyFactory,
        private readonly ProtectionService $protection,
```

After the existing outside-transaction line:

```php
        $this->limits->assertStakeWithinLimits($player, $stakeKobo);
```

add:

```php
        $economicsStrategy = $this->economicsStrategyFactory->forCrashGame($this->economicsConfigResolver->resolveFor($round->gameCode));
        $economicsStrategy->assertAcceptable($round->gameCode, $stakeKobo, EconomicsContext::forCrashRound($round));
```

Inside the transaction, immediately after the existing re-fetch-and-lock block:

```php
            $freshRound = CrashRound::where('id', $round->id)->where('status', 'BETTING')->lockForUpdate()->first();
            if ($freshRound === null) {
                throw new TicketEligibilityException('ROUND_NOT_ACCEPTING_BETS', 'This round is no longer accepting bets.');
            }
```

add:

```php

            // Re-check against the FRESH row's exposureKobo — a concurrent bet could
            // have grown it since the read above.
            $economicsStrategy->assertAcceptable($freshRound->gameCode, $stakeKobo, EconomicsContext::forCrashRound($freshRound));
```

Immediately after the wallet reservation line:

```php
            $this->wallet->reserveStake($player, $stakeKobo, 'crash_bet', $bet->id, $attribution['stateCode']);
```

add:

```php
            $freshRound->increment('exposureKobo', BirdEscapeEngine::worstCaseLiabilityKobo($stakeKobo));
```

Add `$economicsStrategy` to the transaction closure's `use (...)` list.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter BirdEscapeEconomicsCapTest`
Expected: PASS

- [ ] **Step 5: Run the full BirdEscape round suite to check nothing else broke**

Run: `php artisan test --filter BirdEscapeRoundTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Domain/Games/BirdEscape/PlaceCrashBet.php apps/platform/tests/Feature/BirdEscapeEconomicsCapTest.php
git commit -m "feat: enforce economics model and track round exposure in PlaceCrashBet"
```

---

## Task 22: `GameEconomicsConfigPublishApplier`

**Files:**
- Create: `apps/platform/app/Domain/BackOffice/MakerChecker/GameEconomicsConfigPublishApplier.php`
- Modify: `apps/platform/app/Domain/BackOffice/MakerChecker/MakerCheckerService.php`
- Test: `apps/platform/tests/Feature/GameEconomicsConfigPublishApplierTest.php`

**Interfaces:**
- Consumes: `GameEconomicsModelGate` (Task 10), `ReviewableChangeApplier` interface, `MakerCheckerService::APPLIERS`.
- Produces: registers `change_type` `'game_economics_config_publish'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\BackOffice\MakerChecker\GameEconomicsConfigPublishApplier;
use App\Models\GameEconomicsConfig;
use App\Models\ReviewableChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class GameEconomicsConfigPublishApplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishes_a_config_that_passes_the_gate(): void
    {
        $config = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-APPLY-1', 'status' => 'draft',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => ['kelly_factor_basis_points' => 300],
            'effectiveAt' => now(),
        ]);
        $change = ReviewableChange::create([
            'changeType' => 'game_economics_config_publish', 'status' => 'AWAITING_APPROVAL',
            'payload' => ['game_economics_config_id' => $config->id], 'beforeSnapshot' => null,
            'makerId' => 1, 'makerJustification' => 'test', 'submittedAt' => now(),
        ]);

        app(GameEconomicsConfigPublishApplier::class)->apply($change);

        $this->assertSame('published', $config->refresh()->status);
        $this->assertNotNull($config->publishedAt);
    }

    public function test_refuses_to_publish_a_config_that_fails_the_gate_at_approval_time(): void
    {
        $config = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'GEC-APPLY-2', 'status' => 'draft',
            'activeModel' => 'BALANCED_HYBRID', 'paramsJson' => [], // missing kelly_factor_basis_points
            'effectiveAt' => now(),
        ]);
        $change = ReviewableChange::create([
            'changeType' => 'game_economics_config_publish', 'status' => 'AWAITING_APPROVAL',
            'payload' => ['game_economics_config_id' => $config->id], 'beforeSnapshot' => null,
            'makerId' => 1, 'makerJustification' => 'test', 'submittedAt' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        app(GameEconomicsConfigPublishApplier::class)->apply($change);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GameEconomicsConfigPublishApplierTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the applier**

```php
<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\MakerChecker;

use App\Domain\Games\Economics\GameEconomicsModelGate;
use App\Models\GameEconomicsConfig;
use App\Models\ReviewableChange;
use RuntimeException;

/**
 * Payload: {game_economics_config_id: int}. Re-runs the gate at APPROVAL time, not
 * just proposal time — mirrors CrashConfigPublishApplier/PrizeTablePublishApplier
 * exactly.
 */
final class GameEconomicsConfigPublishApplier implements ReviewableChangeApplier
{
    public function __construct(private readonly GameEconomicsModelGate $gate)
    {
    }

    public function apply(ReviewableChange $change): void
    {
        $config = GameEconomicsConfig::findOrFail($change->payload['game_economics_config_id']);

        $errors = $this->gate->validate($config);
        if ($errors !== []) {
            throw new RuntimeException('Game economics config failed the publication gate at approval time: ' . implode('; ', $errors));
        }

        $config->update(['status' => 'published', 'publishedAt' => now()]);
    }
}
```

- [ ] **Step 4: Register it in `MakerCheckerService`**

In `apps/platform/app/Domain/BackOffice/MakerChecker/MakerCheckerService.php`, no new import is needed — `MakerCheckerService` only ever references the applier's class-string, never the gate directly. Add to the `APPLIERS` array:

```php
    private const APPLIERS = [
        'prize_table_publish' => PrizeTablePublishApplier::class,
        'manual_credit_debit' => ManualCreditDebitApplier::class,
        'crash_config_publish' => CrashConfigPublishApplier::class,
        'game_economics_config_publish' => GameEconomicsConfigPublishApplier::class,
    ];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter GameEconomicsConfigPublishApplierTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Domain/BackOffice/MakerChecker/GameEconomicsConfigPublishApplier.php apps/platform/app/Domain/BackOffice/MakerChecker/MakerCheckerService.php apps/platform/tests/Feature/GameEconomicsConfigPublishApplierTest.php
git commit -m "feat: add GameEconomicsConfigPublishApplier, register game_economics_config_publish"
```

---

## Task 23: BackOffice form requests

**Files:**
- Create: `apps/platform/app/Http/Requests/BackOffice/CreateGameEconomicsConfigDraftRequest.php`
- Create: `apps/platform/app/Http/Requests/BackOffice/UpdateGameEconomicsConfigDraftRequest.php`

**Interfaces:**
- Produces: validated fields `game_code`, `version`, `active_model` (one of the 4 known strings), `params` (nullable array), `effective_at`.

- [ ] **Step 1: Write the classes**

No test file for this task — `FormRequest` validation rules are exercised indirectly through Task 24's controller feature tests (mirrors how `CreateCrashConfigDraftRequest` has no dedicated test file either).

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class CreateGameEconomicsConfigDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'game_code' => ['required', 'string', 'max:20'],
            'version' => ['required', 'string', 'max:30'],
            'active_model' => ['required', 'string', 'in:FIXED_RTP,BALANCED_HYBRID,DAILY_LOSS_STOP,PARI_MUTUEL_POOL'],
            'params' => ['nullable', 'array'],
            'effective_at' => ['required', 'date'],
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGameEconomicsConfigDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:30'],
            'active_model' => ['required', 'string', 'in:FIXED_RTP,BALANCED_HYBRID,DAILY_LOSS_STOP,PARI_MUTUEL_POOL'],
            'params' => ['nullable', 'array'],
            'effective_at' => ['required', 'date'],
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add apps/platform/app/Http/Requests/BackOffice/CreateGameEconomicsConfigDraftRequest.php apps/platform/app/Http/Requests/BackOffice/UpdateGameEconomicsConfigDraftRequest.php
git commit -m "feat: add GameEconomicsConfig draft form requests"
```

---

## Task 24: `GameEconomicsConfigController` + routes

**Files:**
- Create: `apps/platform/app/Http/Controllers/BackOffice/GameEconomicsConfigController.php`
- Modify: `apps/platform/routes/backoffice.php`
- Test: `apps/platform/tests/Feature/BackOfficeGameEconomicsConfigTest.php`

**Interfaces:**
- Consumes: `CreateGameEconomicsConfigDraftRequest`/`UpdateGameEconomicsConfigDraftRequest` (Task 23), `GameEconomicsModelGate` (Task 10).
- Produces: `GET/POST/PATCH/DELETE /backoffice/v1/game-economics-configs[...]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\TotpService;
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Models\GameEconomicsConfig;
use App\Models\InstitutionUser;
use Database\Seeders\BlackRedGameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackOfficeGameEconomicsConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BlackRedGameSeeder::class);
    }

    private function institutionToken(string $role): string
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = InstitutionUser::create([
            'email' => strtolower($role) . random_int(1000, 9999) . '@betplus.test',
            'displayName' => 'Operator', 'passwordHash' => password_hash('x', PASSWORD_BCRYPT),
            'role' => $role, 'status' => 'active',
            'mfaSecretEncrypted' => app(MfaSecretCipher::class)->encrypt($secret),
        ]);
        $signIn = $this->postJson('/backoffice/v1/auth/sign-in', ['email' => $user->email, 'password' => 'x'])->json();
        $mfa = $this->postJson('/backoffice/v1/auth/mfa', ['challenge_id' => $signIn['challenge_id'], 'code' => app(TotpService::class)->currentCode($secret)])->json();

        return $mfa['access_token'];
    }

    private function draftPayload(string $version, string $activeModel = 'BALANCED_HYBRID', array $params = ['kelly_factor_basis_points' => 300]): array
    {
        return [
            'game_code' => 'BLACKRED', 'version' => $version, 'active_model' => $activeModel,
            'params' => $params, 'effective_at' => now()->toDateString(),
        ];
    }

    public function test_creating_a_draft_reports_gate_errors_without_publishing(): void
    {
        $token = $this->institutionToken('game_ops');

        $response = $this->withToken($token)->postJson('/backoffice/v1/game-economics-configs', $this->draftPayload('DRAFT-1', 'BALANCED_HYBRID', []));

        $response->assertOk();
        $this->assertSame('draft', $response->json('status'));
        $this->assertNotEmpty($response->json('gate_errors'));
    }

    public function test_a_valid_draft_can_be_proposed_and_approved_for_publication(): void
    {
        $maker = $this->institutionToken('game_ops');
        $draft = $this->withToken($maker)->postJson('/backoffice/v1/game-economics-configs', $this->draftPayload('DRAFT-2'))->json();
        $this->assertEmpty($draft['gate_errors']);

        $proposeResponse = $this->withToken($maker)->postJson('/backoffice/v1/changes', [
            'change_type' => 'game_economics_config_publish',
            'payload' => ['game_economics_config_id' => $draft['id']],
            'justification' => 'Switching BlackRed to Balanced Hybrid.',
        ]);
        $proposeResponse->assertStatus(201);

        $checker = $this->institutionToken('finance');
        $this->withToken($checker)->postJson("/backoffice/v1/changes/{$proposeResponse->json('id')}/approve")->assertOk();

        $this->assertDatabaseHas('gameEconomicsConfig', ['id' => $draft['id'], 'status' => 'published']);
        $resolved = app(EconomicsConfigResolver::class)->resolveFor('BLACKRED');
        $this->assertSame('BALANCED_HYBRID', $resolved->activeModel);
    }

    public function test_a_published_config_can_neither_be_edited_nor_deleted(): void
    {
        $config = GameEconomicsConfig::create([
            'gameCode' => 'BLACKRED', 'version' => 'PUBLISHED-1', 'status' => 'published',
            'activeModel' => 'FIXED_RTP', 'paramsJson' => [], 'effectiveAt' => now(), 'publishedAt' => now(),
        ]);
        $token = $this->institutionToken('game_ops');

        $this->withToken($token)->patchJson("/backoffice/v1/game-economics-configs/{$config->id}", $this->draftPayload('PUBLISHED-1'))->assertStatus(409);
        $this->withToken($token)->deleteJson("/backoffice/v1/game-economics-configs/{$config->id}")->assertStatus(409);
    }

    public function test_draft_create_and_delete_each_write_a_real_audit_log_entry(): void
    {
        $token = $this->institutionToken('game_ops');
        $draft = $this->withToken($token)->postJson('/backoffice/v1/game-economics-configs', $this->draftPayload('DRAFT-AUDIT-1'))->json();
        $this->assertDatabaseHas('auditLog', ['action' => 'game_economics_config_draft_created', 'targetTable' => 'gameEconomicsConfig', 'targetId' => $draft['id']]);

        $this->withToken($token)->deleteJson("/backoffice/v1/game-economics-configs/{$draft['id']}");
        $this->assertDatabaseHas('auditLog', ['action' => 'game_economics_config_draft_deleted', 'targetTable' => 'gameEconomicsConfig', 'targetId' => $draft['id']]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BackOfficeGameEconomicsConfigTest`
Expected: FAIL — routes/controller don't exist (404s).

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Games\Economics\GameEconomicsModelGate;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\CreateGameEconomicsConfigDraftRequest;
use App\Http\Requests\BackOffice\UpdateGameEconomicsConfigDraftRequest;
use App\Models\AuditLog;
use App\Models\GameEconomicsConfig;
use App\Models\InstitutionUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Same maker-checker-gated shape as CrashConfigController/GameRegistryController's
 * prize-table section. Publication itself does not happen here — a draft is
 * proposed for publication through ReviewableChangeController
 * (change_type='game_economics_config_publish'), which runs GameEconomicsModelGate
 * at approval time (GameEconomicsConfigPublishApplier).
 */
class GameEconomicsConfigController extends Controller
{
    public function __construct(private readonly GameEconomicsModelGate $gate)
    {
    }

    /** GET /backoffice/v1/game-economics-configs?game_code=BLACKRED */
    public function index(Request $request): JsonResponse
    {
        $configs = GameEconomicsConfig::when($request->query('game_code'), fn ($q, $code) => $q->where('gameCode', $code))
            ->orderByDesc('id')
            ->get();

        return response()->json(['game_economics_configs' => $configs->map(fn (GameEconomicsConfig $c) => $this->shape($c))->values()]);
    }

    /** GET /backoffice/v1/game-economics-configs/{id} */
    public function show(int $id): JsonResponse
    {
        return response()->json($this->shape(GameEconomicsConfig::findOrFail($id)));
    }

    /** POST /backoffice/v1/game-economics-configs — creates a DRAFT; does not publish. */
    public function store(CreateGameEconomicsConfigDraftRequest $request): JsonResponse
    {
        $config = GameEconomicsConfig::create([
            'gameCode' => $request->string('game_code')->toString(),
            'version' => $request->string('version')->toString(),
            'status' => 'draft',
            'activeModel' => $request->string('active_model')->toString(),
            'paramsJson' => $request->input('params', []),
            'effectiveAt' => $request->string('effective_at')->toString(),
        ]);

        $this->audit($request, 'game_economics_config_draft_created', $config, null);

        $errors = $this->gate->validate($config);

        return response()->json(array_merge($this->shape($config), ['gate_errors' => $errors]));
    }

    /** PATCH /backoffice/v1/game-economics-configs/{id} — only status=draft is editable. */
    public function update(UpdateGameEconomicsConfigDraftRequest $request, int $id): JsonResponse
    {
        $config = GameEconomicsConfig::findOrFail($id);
        if ($config->status !== 'draft') {
            return response()->json(['message' => 'Only a draft game economics config may be edited.'], 409);
        }
        $before = $this->shape($config);

        $config->update([
            'version' => $request->string('version')->toString(),
            'activeModel' => $request->string('active_model')->toString(),
            'paramsJson' => $request->input('params', []),
            'effectiveAt' => $request->string('effective_at')->toString(),
        ]);

        $this->audit($request, 'game_economics_config_draft_updated', $config, $before);

        $errors = $this->gate->validate($config);

        return response()->json(array_merge($this->shape($config), ['gate_errors' => $errors]));
    }

    /** DELETE /backoffice/v1/game-economics-configs/{id} — a draft only. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $config = GameEconomicsConfig::findOrFail($id);
        if ($config->status !== 'draft') {
            return response()->json(['message' => 'Only a draft game economics config may be deleted.'], 409);
        }
        $before = $this->shape($config);

        $config->delete();

        $this->audit($request, 'game_economics_config_draft_deleted', $config, $before, deleted: true);

        return response()->json(['id' => $id, 'deleted' => true]);
    }

    /** @param array<string, mixed>|null $before */
    private function audit(Request $request, string $action, GameEconomicsConfig $config, ?array $before, bool $deleted = false): void
    {
        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => $action,
            'targetTable' => 'gameEconomicsConfig',
            'targetId' => $config->id,
            'before' => $before,
            'after' => $deleted ? null : $this->shape($config),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(GameEconomicsConfig $config): array
    {
        return [
            'id' => $config->id,
            'game_code' => $config->gameCode,
            'version' => $config->version,
            'status' => $config->status,
            'active_model' => $config->activeModel,
            'params' => $config->paramsJson,
            'effective_at' => $config->effectiveAt->toIso8601String(),
            'published_at' => $config->publishedAt?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Register the routes**

In `apps/platform/routes/backoffice.php`, add the import:

```php
use App\Http\Controllers\BackOffice\GameEconomicsConfigController;
```

After the existing "BirdEscape crash config" block (after its closing `});`), add:

```php
        // Game economics model switcher — same maker-checker-gated shape as crash
        // configs and prize tables above (Phase 1 of docs/superpowers/specs/
        // 2026-09-13-admin-gaming-economics-models-design.md).
        Route::get('/game-economics-configs', [GameEconomicsConfigController::class, 'index']);
        Route::get('/game-economics-configs/{id}', [GameEconomicsConfigController::class, 'show']);
        Route::middleware('institution.role:game_ops,system_admin')->group(function () {
            Route::post('/game-economics-configs', [GameEconomicsConfigController::class, 'store']);
            Route::patch('/game-economics-configs/{id}', [GameEconomicsConfigController::class, 'update']);
            Route::delete('/game-economics-configs/{id}', [GameEconomicsConfigController::class, 'destroy']);
        });
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter BackOfficeGameEconomicsConfigTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Http/Controllers/BackOffice/GameEconomicsConfigController.php apps/platform/routes/backoffice.php apps/platform/tests/Feature/BackOfficeGameEconomicsConfigTest.php
git commit -m "feat: add GameEconomicsConfigController and backoffice routes"
```

---

## Task 25: Full regression pass

**Files:**
- None (verification only).

- [ ] **Step 1: Run the entire platform test suite**

Run (from `apps/platform`): `php artisan test`
Expected: PASS, in full — this is the check that Tasks 5-8's ceiling change and Tasks 18-21's wiring haven't broken any pre-existing test anywhere else in the suite (in particular `BackOfficeOperationsTest`, `BirdEscapeRoundTest`, `BlackRedTicketTest`, `HeritageTicketTest`, `CagedTicketTest`, `CagedPrizeTablePublicationGateTest`).

- [ ] **Step 2: If anything fails, fix forward**

Any failure here means a test elsewhere depended on the pre-8800bp ceiling text/value, or on `CreateTicket`/`CreateHeritageTicket`/`CreateCagedTicket`/`PlaceCrashBet`'s constructor signature (a test manually instantiating one of those classes with `new` instead of `app(...)` would break — fix by switching that call site to `app(ClassName::class)`, which resolves the two new constructor dependencies automatically via the container). Do not weaken the new behavior to make an old test pass — the old test's expectation was built for the previous ceiling/signature and needs updating instead.

- [ ] **Step 3: Commit (only if fixes were needed)**

```bash
git add -A
git commit -m "fix: address regressions from the 8800bp ceiling and economics-model wiring"
```

---

## Task 26: PHPStan / static analysis pass

**Files:**
- None (verification only).

- [ ] **Step 1: Run PHPStan**

Run (from `apps/platform`): `vendor/bin/phpstan analyse --memory-limit=512M`
Expected: no new errors introduced by this plan's files (all new classes are fully typed with `declare(strict_types=1)`, matching the project's existing PHPStan baseline conventions).

- [ ] **Step 2: Fix any new errors**

Common ones to expect and fix: a missing `@param`/`@return` array-shape docblock on `GameEconomicsModelGate::validate()` or `EconomicsModelStrategyFactory`'s `match()` returning a union PHPStan can't narrow — add explicit docblocks matching the style already used in `PrizeTablePublicationGate`/`CrashConfigPublicationGate` (e.g. `/** @return list<string> */`).

- [ ] **Step 3: Commit (only if fixes were needed)**

```bash
git add -A
git commit -m "fix: address PHPStan findings in the economics-model feature"
```
