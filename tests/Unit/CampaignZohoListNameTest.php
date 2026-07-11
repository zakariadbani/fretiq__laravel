<?php

namespace Tests\Unit;

use App\Models\Campaign;
use App\Services\Campaign\CampaignZohoListSyncService;
use ReflectionMethod;
use Tests\TestCase;

class CampaignZohoListNameTest extends TestCase
{
    public function test_campaign_list_name_is_ascii_safe_and_preserves_campaign_identity(): void
    {
        $campaign = new Campaign([
            'name' => 'Campagne Exemple — Prospection FR',
        ]);
        $campaign->setAttribute('id', 1);

        $service = (new \ReflectionClass(CampaignZohoListSyncService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CampaignZohoListSyncService::class, 'campaignListName');
        $method->setAccessible(true);
        $listName = $method->invoke($service, $campaign);

        $this->assertSame('Fretiq Campaign 1 Campagne Exemple Prospection FR', $listName);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9 ]+\z/', $listName);
        $this->assertLessThanOrEqual(191, strlen($listName));
    }
}
