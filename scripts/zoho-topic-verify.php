<?php

/**
 * scripts/zoho-topic-verify.php
 *
 * Empirical-verification diagnostic (CLAUDE.md §1) for the Zoho Campaigns
 * "topic" (rubrique) subscribe flow — the fix for campaigns silently skipping
 * every recipient because contacts were never subscribed to the campaign's
 * topic. This script makes REAL Zoho API calls but NEVER creates or sends a
 * campaign (no /createCampaign, no /sendcampaign call anywhere below).
 *
 * Run via:
 *   php artisan tinker --execute "require 'scripts/zoho-topic-verify.php';"
 *
 * Never prints the access token, refresh token, client secret, or any other
 * credential — only HTTP status + response bodies from Zoho.
 */

// ── EDIT BEFORE RUNNING ─────────────────────────────────────────────────────
// A real, disposable test mailbox you control (step 3/4 will actually
// subscribe it to $verifyListKey under the configured topic).
$verifyEmail = 'CHANGE-ME@example.test';
// An existing Zoho Campaigns list key, e.g. config('services.zoho.campaigns.list_key')
// or a campaign's already-persisted zoho_list_key column.
$verifyListKey = 'CHANGE-ME-LIST-KEY';
// ─────────────────────────────────────────────────────────────────────────────

/** @var \App\Services\Zoho\ZohoAuthService $authService */
$authService = app(\App\Services\Zoho\ZohoAuthService::class);
$apiUrl = rtrim(config('services.zoho.campaigns.api_url', 'https://campaigns.zoho.com/api/v1.1'), '/');
$configuredTopicId = trim((string) config('services.zoho.campaigns.topic_id'));

function zoho_topic_verify_step(string $title): void
{
    echo "\n=== {$title} ===\n";
}

function zoho_topic_verify_summarize(\Illuminate\Http\Client\Response $response): array
{
    echo 'STATUS: ' . $response->status() . "\n";
    $payload = $response->json() ?? [];
    echo 'BODY: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    return $payload;
}

// ── Step 1 — list topics, confirm the configured topic_id is really there ──
zoho_topic_verify_step('1. GET /topics');
try {
    $accessToken = $authService->getAccessToken('campaigns');
    $response = \Illuminate\Support\Facades\Http::withHeaders([
        'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
    ])->timeout(30)->get($apiUrl . '/topics', [
        'resfmt'     => 'JSON',
        'from_index' => 1,
        'range'      => 50,
    ]);
    $payload = zoho_topic_verify_summarize($response);

    $topics = $payload['topic_details'] ?? $payload['list_of_details'] ?? $payload['topics'] ?? [];
    $found = false;
    if (is_array($topics)) {
        foreach ($topics as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $id = (string) ($topic['topicId'] ?? $topic['topic_id'] ?? 'inconnu');
            $name = $topic['topicName'] ?? $topic['topic_name'] ?? 'inconnu';
            echo "  topicId={$id} topicName={$name}\n";
            if ($configuredTopicId !== '' && $id === $configuredTopicId) {
                $found = true;
            }
        }
    }
    echo 'services.zoho.campaigns.topic_id configuré (' . ($configuredTopicId ?: '(vide)') . ') trouvé dans /topics : ' . ($found ? 'OUI' : 'NON') . "\n";
} catch (\Throwable $e) {
    echo 'ERREUR étape 1 : ' . $e->getMessage() . "\n";
}

// ── Step 2 — contact field names ────────────────────────────────────────────
// NOTE: GET /getallcontactfields is NOT a valid v1.1 endpoint — live-verified
// (prod, 2026-07-21) to return Code 1004 "Unable to find the resource you're
// looking for." Do not call it. The correct endpoint is GET /contact/allfields
// (type=json) — live-verified (prod, 2026-07-21) STATUS 200. The company field
// is DISPLAY_NAME "Company Name", FIELD_DISPLAY_NAME "COMPANYNAME",
// FIELD_NAME "companyname" — this is what confirms the $[COMPANYNAME]$ merge
// tag used in ZohoCampaignsDriver::MERGE_TAG_MAP.
zoho_topic_verify_step('2. GET /contact/allfields');
try {
    $accessToken = $authService->getAccessToken('campaigns');
    $response = \Illuminate\Support\Facades\Http::withHeaders([
        'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
    ])->timeout(30)->get($apiUrl . '/contact/allfields', [
        'type' => 'json',
    ]);
    $payload = zoho_topic_verify_summarize($response);

    $fields = $payload['CONTACT_FIELDS'] ?? $payload['contact_fields'] ?? $payload ?? [];
    if (is_array($fields)) {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $displayName = $field['DISPLAY_NAME'] ?? 'inconnu';
            $fieldDisplayName = $field['FIELD_DISPLAY_NAME'] ?? 'inconnu';
            $fieldName = $field['FIELD_NAME'] ?? 'inconnu';
            echo "  DISPLAY_NAME={$displayName} FIELD_DISPLAY_NAME={$fieldDisplayName} FIELD_NAME={$fieldName}\n";
        }
    }
} catch (\Throwable $e) {
    echo 'ERREUR étape 2 : ' . $e->getMessage() . "\n";
}

// ── Step 3 — subscribe $verifyEmail to $verifyListKey under the topic ──────
zoho_topic_verify_step('3. POST /json/listsubscribe (première tentative)');
try {
    $accessToken = $authService->getAccessToken('campaigns');
    $response = \Illuminate\Support\Facades\Http::withHeaders([
        'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
    ])->timeout(30)->asForm()->post($apiUrl . '/json/listsubscribe', [
        'resfmt'      => 'JSON',
        'listkey'     => $verifyListKey,
        'topic_id'    => $configuredTopicId,
        'source'      => 'fretiq-verify',
        'contactinfo' => json_encode(['Contact Email' => $verifyEmail]),
    ]);
    $payload = zoho_topic_verify_summarize($response);
    echo 'code (première tentative) : ' . ($payload['code'] ?? 'inconnu') . "\n";
} catch (\Throwable $e) {
    echo 'ERREUR étape 3 : ' . $e->getMessage() . "\n";
}

// ── Step 4 — repeat verbatim to capture the "already subscribed" code ──────
zoho_topic_verify_step('4. POST /json/listsubscribe (répétition — capture le code « déjà abonné »)');
try {
    $accessToken = $authService->getAccessToken('campaigns');
    $response = \Illuminate\Support\Facades\Http::withHeaders([
        'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
    ])->timeout(30)->asForm()->post($apiUrl . '/json/listsubscribe', [
        'resfmt'      => 'JSON',
        'listkey'     => $verifyListKey,
        'topic_id'    => $configuredTopicId,
        'source'      => 'fretiq-verify',
        'contactinfo' => json_encode(['Contact Email' => $verifyEmail]),
    ]);
    $payload = zoho_topic_verify_summarize($response);
    echo 'code « déjà abonné » (à ajouter à ZohoCampaignsClient::LISTSUBSCRIBE_ACCEPTED_CODES si ce code doit être toléré comme succès) : ' . ($payload['code'] ?? 'inconnu') . "\n";
} catch (\Throwable $e) {
    echo 'ERREUR étape 4 : ' . $e->getMessage() . "\n";
}

// ── Step 5 — dump one subscriber record, check whether topic state is exposed ──
zoho_topic_verify_step('5. GET /getlistsubscribers (un enregistrement)');
try {
    $accessToken = $authService->getAccessToken('campaigns');
    $response = \Illuminate\Support\Facades\Http::withHeaders([
        'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
    ])->timeout(30)->get($apiUrl . '/getlistsubscribers', [
        'resfmt'    => 'JSON',
        'listkey'   => $verifyListKey,
        'fromindex' => 1,
        'range'     => 5,
    ]);
    zoho_topic_verify_summarize($response);
} catch (\Throwable $e) {
    echo 'ERREUR étape 5 : ' . $e->getMessage() . "\n";
}

echo "\n=== Terminé — aucune campagne n'a été créée ou envoyée par ce script. ===\n";
