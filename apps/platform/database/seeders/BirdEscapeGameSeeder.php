<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Games\BirdEscape\CrashConfigPublicationGate;
use App\Models\CrashConfig;
use App\Models\ExclusionRegistry;
use App\Models\GameRegistry;
use App\Models\StateLicence;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The minimum configuration BirdEscape needs to be playable in dev/staging — mirrors
 * BlackRedGameSeeder exactly: a game registry entry, the same Lagos exclusion-registry
 * and state-licence gates AttributionService requires (safe to seed alongside
 * BlackRedGameSeeder — both use updateOrCreate on the same natural key), and one
 * published crashConfig. Real publication (maker-checker, actuarial cert) is the
 * BackOffice flow; this seeder writes the same shape the gate would accept.
 */
class BirdEscapeGameSeeder extends Seeder
{
    public function run(): void
    {
        GameRegistry::updateOrCreate(
            ['gameCode' => 'BIRDESCAPE'],
            [
                'engineVersion' => 'birdescape-1.0.0',
                'status' => 'ACTIVE',
                'minStakeKobo' => 10_000,
                'maxStakeKobo' => 1_000_000,
                'enabledChannels' => ['web', 'app'],
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

        $config = CrashConfig::updateOrCreate(
            ['gameCode' => 'BIRDESCAPE', 'version' => 'BE-NG-2026.1'],
            [
                'status' => 'draft',
                'houseEdgeBasisPoints' => 500,
                'bettingWindowSeconds' => 7,
                'postCrashIntervalSeconds' => 5,
                'growthRateConstant' => 4000,
                'effectiveAt' => now()->subDay(),
                'actuarialCertRef' => 'PENDING-ACTUARIAL-CERT — placeholder, not a real certification',
            ],
        );

        $errors = app(CrashConfigPublicationGate::class)->validate($config);
        // The only expected failure is the actuarial cert being a placeholder — a real
        // one doesn't exist yet. Anything else is a genuine math error in the seeded
        // config and should fail the seed run.
        $unexpected = array_values(array_filter($errors, fn (string $e) => !str_contains($e, 'actuarial')));
        if ($unexpected !== []) {
            throw new RuntimeException('BirdEscape crash config failed the publication gate: ' . implode('; ', $unexpected));
        }

        $config->update(['status' => 'published', 'publishedAt' => now()]);
    }
}
