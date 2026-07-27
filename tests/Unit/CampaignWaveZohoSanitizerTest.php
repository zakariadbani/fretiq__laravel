<?php

namespace Tests\Unit;

use App\Services\Campaign\CampaignWaveZohoListSyncService;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignWaveZohoSanitizerTest extends TestCase
{
    public function test_sanitizes_em_dash_and_ampersand_to_alphanumerics(): void
    {
        $clean = CampaignWaveZohoListSyncService::sanitizeCampaignName('Famille 3 — Projets & Chantiers', 3);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9 ]+$/', $clean);
    }

    public function test_cjk_only_name_falls_back_to_campaign_placeholder(): void
    {
        $clean = CampaignWaveZohoListSyncService::sanitizeCampaignName('日本語キャンペーン', 7);

        $this->assertSame('Campaign 7', $clean);
    }

    public function test_composed_driver_name_stays_within_zoho_191_char_budget(): void
    {
        $longName = str_repeat('Éléphant café ', 30); // ~300 chars accented

        $safeName = CampaignWaveZohoListSyncService::sanitizeCampaignName($longName, 1);
        $nameSuffix = ' - C1 - R14 - 20260727';
        $composed = 'Fretiq ' . Str::limit($safeName, 191 - mb_strlen('Fretiq ' . $nameSuffix), '') . $nameSuffix;

        $this->assertLessThanOrEqual(191, mb_strlen($composed));
    }
}
