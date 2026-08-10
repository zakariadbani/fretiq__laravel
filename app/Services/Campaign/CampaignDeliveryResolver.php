<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use Illuminate\Contracts\Container\Container;

class CampaignDeliveryResolver
{
    public function __construct(
        private readonly Container $container,
        private readonly LocalCampaignsDriver $localDriver,
        private readonly ZohoCampaignsDriver $zohoDriver,
        private readonly SmtpCampaignsDriver $smtpDriver,
    ) {
    }

    public function resolve(Campaign $campaign): CampaignsClient
    {
        if ($campaign->delivery_channel === null) {
            // Legacy campaigns intentionally keep following the global driver.
            // Resolve it at execution time so long-lived services do not retain
            // a driver selected before configuration or test overrides changed.
            return $this->container->make(CampaignsClient::class);
        }

        if ($campaign->delivery_channel === 'smtp') {
            return $this->smtpDriver;
        }

        return config('services.zoho.driver', 'local') === 'zoho'
            ? $this->zohoDriver
            : $this->localDriver;
    }
}
