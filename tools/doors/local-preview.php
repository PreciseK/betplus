<?php

declare(strict_types=1);

// Isolated browser integration fixture. Never runs against the normal platform DB.
$root = dirname(__DIR__, 2);
require $root . '/apps/platform/vendor/autoload.php';
$app = require $root . '/apps/platform/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = (string) config('database.connections.sqlite.database');
if (!app()->environment('doors-local') || config('database.default') !== 'sqlite' || basename($database) !== 'doors-local-preview.sqlite') {
    throw new RuntimeException('Preview fixture requires the dedicated doors-local environment and SQLite database.');
}
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Database\Seeders\BlackRedGameSeeder::class, '--force' => true]);
Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Database\Seeders\DoorsGameSeeder::class, '--force' => true]);
App\Models\GameRegistry::where('gameCode', 'DOORS')->update(['status' => 'ACTIVE']);
$config = App\Models\GameEconomicsConfig::where('gameCode', 'DOORS')->firstOrFail();
$params = $config->paramsJson;
$params['float_max_age_seconds'] = 3600;
$params['doors']['certification_ref'] = 'LOCAL-TEST-NOT-CERTIFIED';
$config->update(['status' => 'published', 'publishedAt' => now(), 'paramsJson' => $params]);
App\Models\FloatSnapshot::create(['opayBalanceKobo' => 100_000_000, 'alertState' => 'ok', 'polledAt' => now()]);
$player = App\Models\Player::firstOrCreate(['msisdn' => '+2348000000001'], [
    'registeredName' => 'Doors Local Preview', 'registrationChannel' => 'web', 'kycTier' => 1,
    'ninHash' => hash('sha256', 'doors-local-preview'), 'residencyStatus' => 'resident',
]);
App\Models\RegistryExclusion::updateOrCreate(['ninHash' => $player->ninHash], ['registryName' => 'Local preview stub', 'excluded' => false, 'checkedAt' => now()]);
$wallet = app(App\Domain\Wallet\WalletService::class);
if ($wallet->walletFor($player)->playBalanceKobo < 100_000) {
    $wallet->creditPlayBalanceFromOpay($player, 1_000_000, 'local-preview', $player->id);
}
$tokens = app(App\Domain\Identity\SessionService::class)->issueTokensFor($player, $player->msisdn, 'web');
$cookies = [];
foreach (['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'betplus_signed_in' => '1'] as $name => $value) {
    $cookies[] = ['name' => $name, 'value' => $value, 'domain' => 'localhost', 'path' => '/', 'expires' => time() + 1700, 'httpOnly' => $name !== 'betplus_signed_in', 'secure' => false, 'sameSite' => 'Lax'];
}
$path = $root . '/output/playwright/doors-local-auth.json';
if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
file_put_contents($path, json_encode(['cookies' => $cookies, 'origins' => []], JSON_THROW_ON_ERROR));
echo "Isolated Doors preview ready. Auth state saved under ignored output/playwright/. No external payments dispatched.\n";
