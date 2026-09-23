<?php

namespace App\Providers;

use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\Identity\Vault\VaultCipher;
use App\Domain\Identity\Verification\IdentityVendor;
use App\Domain\Identity\Verification\StubIdentityVendor;
use App\Domain\Games\Draw\DrawPartnerAdapter;
use App\Domain\Games\Draw\StubDrawPartnerAdapter;
use App\Domain\Games\Heritage\HeritageEngineClient;
use App\Domain\Jurisdiction\Signals\LocationSignalProvider;
use App\Domain\Jurisdiction\Signals\StubLocationSignalProvider;
use App\Domain\Payments\Providers\Opay\OpayCollectionGateway;
use App\Domain\Payments\Providers\Opay\OpayCollectionSigner;
use App\Domain\Payments\Providers\Opay\OpayGateway;
use App\Domain\Payments\Providers\Opay\OpayPayoutCallbackVerifier;
use App\Domain\Payments\Providers\Opay\OpayPayoutSigner;
use App\Domain\ResponsibleGaming\Registries\RegistryClient;
use App\Domain\ResponsibleGaming\Registries\StubRegistryClient;
use App\Domain\Ussd\UssdGatewaySigner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OpayPayoutSigner::class, fn () => new OpayPayoutSigner((string) config('opay.payout_private_key')));
        $this->app->singleton(OpayPayoutCallbackVerifier::class, fn () => new OpayPayoutCallbackVerifier((string) config('opay.payout_callback_secret')));

        $this->app->singleton(OpayGateway::class, fn ($app) => new OpayGateway(
            $app->make(OpayPayoutSigner::class),
            (string) config('opay.base_url'),
            (string) config('opay.merchant_id'),
        ));

        $this->app->singleton(OpayCollectionSigner::class, fn () => new OpayCollectionSigner((string) config('opay.collection_secret_key')));

        $this->app->singleton(OpayCollectionGateway::class, fn ($app) => new OpayCollectionGateway(
            $app->make(OpayCollectionSigner::class),
            (string) config('opay.collection_base_url'),
            (string) config('opay.collection_merchant_id'),
            (string) config('opay.collection_callback_url'),
        ));

        $this->app->singleton(VaultCipher::class, fn () => new VaultCipher((string) config('vault.encryption_key')));
        $this->app->singleton(MfaSecretCipher::class, fn () => new MfaSecretCipher((string) config('backoffice.mfa_encryption_key')));

        $this->app->bind(IdentityVendor::class, match (config('identityVendor.driver')) {
            default => StubIdentityVendor::class,
        });

        $this->app->bind(LocationSignalProvider::class, match (config('jurisdiction.driver')) {
            default => StubLocationSignalProvider::class,
        });

        $this->app->bind(RegistryClient::class, match (config('responsibleGaming.registry_driver')) {
            default => StubRegistryClient::class,
        });

        $this->app->singleton(UssdGatewaySigner::class, fn () => new UssdGatewaySigner((string) config('ussd.gateway_shared_secret')));

        $this->app->singleton(HeritageEngineClient::class, fn () => new HeritageEngineClient(
            (string) config('heritage.engine_base_url'),
            (int) config('heritage.engine_timeout_seconds'),
        ));

        // No contracted draw partner exists yet (PRD C-xx items unconfirmed) — same
        // shape as RegistryClient's stub-by-default binding above.
        $this->app->bind(DrawPartnerAdapter::class, match (config('heritage.draw_partner_driver', 'stub')) {
            default => StubDrawPartnerAdapter::class,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
