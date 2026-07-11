<?php

namespace App\Providers;

use App\Core\KTBootstrap;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Zoho CRM HTTP client — concrete implementation selected by crm_driver config.
        $this->app->bind(
            \App\Services\Zoho\CrmClient::class,
            fn () => \App\Services\Zoho\CrmClientFactory::make()
        );

        // Campaign email driver — LocalCampaignsDriver is the default (driver='local').
        // ZohoCampaignsDriver is activated by setting config('services.zoho.driver') = 'zoho'.
        //
        // IMPORTANT: The Zoho driver is UNVERIFIED (Campaigns OAuth not provisioned).
        // Do NOT flip driver to 'zoho' without:
        //   1. Live Campaigns OAuth credentials wired in config/services.php.
        //   2. Empirical tinker verification (STATUS 200) of each API call.
        //   3. CampaignsReadinessService::check() returning ready=true.
        $this->app->bind(
            \App\Services\Campaign\CampaignsClient::class,
            fn () => config('services.zoho.driver', 'local') === 'zoho'
                ? new \App\Services\Campaign\ZohoCampaignsDriver(
                    zohoClient: app(\App\Services\Zoho\ZohoCampaignsClient::class),
                )
                : new \App\Services\Campaign\LocalCampaignsDriver(),
        );

        $this->app->singleton(
            \App\Services\Zoho\ZohoRecipientListGateway::class,
            \App\Services\Zoho\LiveZohoRecipientListGateway::class,
        );

        // ── Sprint-3b: Automation engine bindings ─────────────────────────────
        // All Sprint-3b services are concrete classes with concrete constructor deps.
        // Laravel's reflection-based auto-wiring handles them, but we register them
        // explicitly here so the container resolution is self-documenting and
        // swappable without touching call sites.

        $this->app->singleton(
            \App\Services\Campaign\CampaignSchedulerService::class,
            \App\Services\Campaign\CampaignSchedulerService::class,
        );

        $this->app->singleton(
            \App\Services\Campaign\SendWindowGuard::class,
            \App\Services\Campaign\SendWindowGuard::class,
        );

        $this->app->singleton(
            \App\Services\Campaign\SequenceService::class,
            \App\Services\Campaign\SequenceService::class,
        );

        // CampaignService depends on SendWindowGuard and SequenceService — bind
        // explicitly so the container injects the singleton instances correctly.
        $this->app->singleton(
            \App\Services\Campaign\CampaignService::class,
            fn ($app) => new \App\Services\Campaign\CampaignService(
                segmentService:   $app->make(\App\Services\Campaign\SegmentService::class),
                sendWindowGuard:  $app->make(\App\Services\Campaign\SendWindowGuard::class),
                sequenceService:  $app->make(\App\Services\Campaign\SequenceService::class),
            ),
        );

        // DemandeCaptureService depends on SequenceService — resolved via singleton.
        $this->app->singleton(
            \App\Services\Demande\DemandeCaptureService::class,
            fn ($app) => new \App\Services\Demande\DemandeCaptureService(
                sequenceService: $app->make(\App\Services\Campaign\SequenceService::class),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Update defaultStringLength
        Builder::defaultStringLength(191);

        KTBootstrap::init();
    }
}
