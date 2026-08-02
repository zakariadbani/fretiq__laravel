<?php

/**
 * scripts/zoho-stats-verify.php
 *
 * Empirical-verification diagnostic (CLAUDE.md §1) for Zoho Campaigns
 * report/read endpoints — probes campaign stats and recipient breakdowns for
 * a real, already-sent campaign. READ-ONLY: every call below is a GET
 * against a report endpoint. This script never creates, sends, subscribes,
 * or mutates anything in Zoho.
 *
 * ROUND 3: round 2 established that getcampaignrecipientsdata action names
 * WITHOUT the "email" prefix are the valid family, and that the response
 * `Code` distinguishes valid-with-data (0) from valid-but-empty (6303) from
 * invalid action name (1001): openedcontacts + sentcontacts -> 0;
 * clickedcontacts + spamcontacts -> 6303; unsubscribedcontacts +
 * optincontacts -> 1001. This round pins down the bounce action name and
 * the remaining action names in that family.
 *
 * Run via:
 *   php artisan tinker --execute "require 'scripts/zoho-stats-verify.php';"
 *
 * Never prints the access token, refresh token, client secret, or any other
 * credential — only HTTP status + response bodies from Zoho.
 */

// run id 2 / campaign 4 — change if needed
const CAMPAIGN_KEY = '3zf263a6dbc000d8a8f5d441e9e20788e028d055801bd458c9967609617d24ee49';

/** @var \App\Services\Zoho\ZohoAuthService $authService */
$authService = app(\App\Services\Zoho\ZohoAuthService::class);
$apiUrl = rtrim(config('services.zoho.campaigns.api_url', 'https://campaigns.zoho.com/api/v1.1'), '/');

function zoho_stats_verify_probe(string $label, callable $request): void
{
    echo "PROBE {$label}\n";
    try {
        $response = $request();
        echo 'STATUS: ' . $response->status() . "\n";
        echo 'BODY: ' . substr($response->body(), 0, 1200) . "\n";
    } catch (\Throwable $e) {
        echo "STATUS: ERROR\n";
        echo 'BODY: ' . substr($e->getMessage(), 0, 1200) . "\n";
    }
    echo "\n";
}

$accessToken = $authService->getAccessToken('campaigns');

$authedGet = function (string $path, array $query) use ($apiUrl, $accessToken) {
    return \Illuminate\Support\Facades\Http::withHeaders([
        'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
    ])->timeout(30)->get($apiUrl . $path, $query);
};

// 1. campaignreports — sanity, kept from round 1
zoho_stats_verify_probe('1 campaignreports', fn () => $authedGet('/campaignreports', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
]));

// 2. getcampaignrecipientsdata — bouncedcontacts (retry — round 1 gave 1001, may have been transient)
zoho_stats_verify_probe('2 getcampaignrecipientsdata bouncedcontacts', fn () => $authedGet('/getcampaignrecipientsdata', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
    'action'      => 'bouncedcontacts',
    'fromindex'   => 1,
    'range'       => 20,
]));

// 3. getcampaignrecipientsdata — hardbouncedcontacts
zoho_stats_verify_probe('3 getcampaignrecipientsdata hardbouncedcontacts', fn () => $authedGet('/getcampaignrecipientsdata', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
    'action'      => 'hardbouncedcontacts',
    'fromindex'   => 1,
    'range'       => 20,
]));

// 4. getcampaignrecipientsdata — softbouncedcontacts
zoho_stats_verify_probe('4 getcampaignrecipientsdata softbouncedcontacts', fn () => $authedGet('/getcampaignrecipientsdata', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
    'action'      => 'softbouncedcontacts',
    'fromindex'   => 1,
    'range'       => 20,
]));

// 5. getcampaignrecipientsdata — unopenedcontacts
zoho_stats_verify_probe('5 getcampaignrecipientsdata unopenedcontacts', fn () => $authedGet('/getcampaignrecipientsdata', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
    'action'      => 'unopenedcontacts',
    'fromindex'   => 1,
    'range'       => 20,
]));

// 6. getcampaignrecipientsdata — autoreplycontacts
zoho_stats_verify_probe('6 getcampaignrecipientsdata autoreplycontacts', fn () => $authedGet('/getcampaignrecipientsdata', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
    'action'      => 'autoreplycontacts',
    'fromindex'   => 1,
    'range'       => 20,
]));

// 7. getcampaignrecipientsdata — forwardedcontacts
zoho_stats_verify_probe('7 getcampaignrecipientsdata forwardedcontacts', fn () => $authedGet('/getcampaignrecipientsdata', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
    'action'      => 'forwardedcontacts',
    'fromindex'   => 1,
    'range'       => 20,
]));

// 8. getcampaignrecipientsdata — unsentcontacts
zoho_stats_verify_probe('8 getcampaignrecipientsdata unsentcontacts', fn () => $authedGet('/getcampaignrecipientsdata', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
    'action'      => 'unsentcontacts',
    'fromindex'   => 1,
    'range'       => 20,
]));

// 9. getcampaignrecipientsdata — optoutcontacts
zoho_stats_verify_probe('9 getcampaignrecipientsdata optoutcontacts', fn () => $authedGet('/getcampaignrecipientsdata', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
    'action'      => 'optoutcontacts',
    'fromindex'   => 1,
    'range'       => 20,
]));

// 10. getcampaignrecipientsdata — unsubscribecontacts (singular variant)
zoho_stats_verify_probe('10 getcampaignrecipientsdata unsubscribecontacts', fn () => $authedGet('/getcampaignrecipientsdata', [
    'resfmt'      => 'JSON',
    'campaignkey' => CAMPAIGN_KEY,
    'action'      => 'unsubscribecontacts',
    'fromindex'   => 1,
    'range'       => 20,
]));

echo "DONE - read-only probes complete\n";
