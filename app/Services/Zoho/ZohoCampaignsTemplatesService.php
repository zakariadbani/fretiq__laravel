<?php

namespace App\Services\Zoho;

use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;
use App\Models\ZohoSyncLog;
use App\Support\ApiLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Imports sent Zoho Campaigns as reusable campaign_templates.
 *
 * This mirrors the FreightFlow approach: use the normal Zoho Campaigns OAuth
 * API to list sent campaigns, then pull the public preview HTML for each one.
 * No separate Zoho Email API key is required.
 */
class ZohoCampaignsTemplatesService
{
    private const SOURCE_PREFIX = 'campaigns:';
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 10;

    private string $apiUrl;

    public function __construct(
        private readonly ZohoAuthService $auth,
    ) {
        $this->apiUrl = rtrim(
            config('services.zoho.campaigns.api_url', 'https://campaigns.zoho.com/api/v1.1'),
            '/',
        );
    }

    /**
     * Fetch sent campaigns from Zoho Campaigns and hydrate each with preview HTML.
     *
     * @return array<int, array{zoho_id: string, import_id: string, name: string, subject: string, content: string, status: string, sent_date: string|null, preview_url: string|null}>
     */
    public function fetchRemote(): array
    {
        $byKey = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $fromIndex = ($page * self::PAGE_SIZE) + 1;

            $body = $this->campaignsGet('recentcampaigns', [
                'resfmt' => 'JSON',
                'sort' => 'desc',
                'fromindex' => $fromIndex,
                'range' => self::PAGE_SIZE,
                'status' => 'sent',
            ]);

            $items = $this->extractCampaigns($body);

            foreach ($items as $item) {
                $key = trim((string) ($item['campaign_key'] ?? ''));
                if ($key === '' || isset($byKey[$key])) {
                    continue;
                }

                $name = trim((string) ($item['campaign_name'] ?? ''));
                if ($name === '') {
                    $name = 'Campagne Zoho ' . $key;
                }

                $subject = trim((string) ($item['subject'] ?? $item['email_subject'] ?? ''));
                if ($subject === '') {
                    $subject = $name;
                }

                $previewUrl = $this->normalizePreviewUrl((string) ($item['campaign_preview'] ?? ''));

                $byKey[$key] = [
                    'zoho_id' => $key,
                    'import_id' => self::SOURCE_PREFIX . $key,
                    'name' => $name,
                    'subject' => $subject,
                    'content' => $previewUrl ? $this->fetchPreviewHtml($previewUrl) : '',
                    'status' => (string) ($item['campaign_status'] ?? ''),
                    'sent_date' => $item['sent_date_string'] ?? null,
                    'preview_url' => $previewUrl,
                ];
            }

            if (count($items) < self::PAGE_SIZE) {
                break;
            }
        }

        return array_values($byKey);
    }

    /**
     * Import Zoho Campaigns into campaign_templates.
     *
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function import(): array
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $startedAt = microtime(true);
        $status = 'success';
        $error = null;

        try {
            foreach ($this->fetchRemote() as $tpl) {
                $name = trim($tpl['name'] ?? '');
                $subject = trim($tpl['subject'] ?? '');
                $content = trim($tpl['content'] ?? '');

                if ($name === '' || $content === '') {
                    $skipped++;
                    Log::debug("[ZohoCampaignsTemplatesService] Skipped campaign {$tpl['zoho_id']} — empty name or preview content");
                    continue;
                }

                $row = CampaignTemplate::where('zoho_template_id', $tpl['import_id'])->first();
                $sourceLanguage = $this->inferSourceLanguage($subject, $content);

                if ($row === null) {
                    $row = CampaignTemplate::whereNull('zoho_template_id')
                        ->where('name', $name)
                        ->first();

                    if ($row !== null) {
                        $row->zoho_template_id = $tpl['import_id'];
                    }
                }

                if ($row !== null) {
                    $this->applyImportedTemplate($row, $name, $subject, $content, $sourceLanguage);
                    $updated++;
                    continue;
                }

                $row = CampaignTemplate::create([
                    'name' => $name,
                    'subject' => $sourceLanguage === 'en' ? '' : ($subject !== '' ? $subject : $name),
                    'html_content' => $sourceLanguage === 'en' ? '' : $content,
                    'zoho_template_id' => $tpl['import_id'],
                ]);

                if ($sourceLanguage === 'en') {
                    $this->upsertEnglishTranslation($row, $subject !== '' ? $subject : $name, $content);
                }

                $imported++;
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $error = $e->getMessage();
            Log::error("[ZohoCampaignsTemplatesService] import() failed: {$error}");
        }

        ZohoSyncLog::create([
            'module' => 'CampaignsSentTemplates',
            'synced_at' => now(),
            'records_synced' => $imported + $updated,
            'status' => $status,
            'error' => $error,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        if ($status === 'error') {
            throw new \RuntimeException($error ?? 'Import failed');
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    private function applyImportedTemplate(
        CampaignTemplate $row,
        string $name,
        string $subject,
        string $content,
        string $sourceLanguage,
    ): void {
        $row->name = $name;

        if ($sourceLanguage === 'en') {
            // Imported sent Zoho Campaigns are often already English. Fretiq stores
            // FR/base copy on campaign_templates and EN copy in translations, so do
            // not let English provider HTML appear in the FR editor. If an existing
            // row already contains English in base fields from an older import, move
            // it out of the FR section; preserve a human-written French base.
            if ($this->inferSourceLanguage((string) $row->subject, (string) $row->html_content) === 'en') {
                $row->subject = '';
                $row->html_content = '';
            }

            $row->save();
            $this->upsertEnglishTranslation($row, $subject, $content);

            return;
        }

        $row->subject = $subject !== '' ? $subject : $name;
        $row->html_content = $content;
        $row->save();
    }

    private function upsertEnglishTranslation(CampaignTemplate $row, string $subject, string $content): void
    {
        $hashes = $row->sourceHashes();

        CampaignTemplateTranslation::updateOrCreate(
            [
                'campaign_template_id' => $row->id,
                'language' => 'en',
            ],
            [
                'subject' => $subject,
                'html_content' => $content,
                'preview_text' => null,
                'is_ai_generated' => false,
                'reviewed_at' => null,
                'src_subject_hash' => $hashes['subject'],
                'src_preview_hash' => $hashes['preview'],
                'src_body_hash' => $hashes['body'],
            ],
        );
    }

    private function inferSourceLanguage(string $subject, string $content): string
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($subject . ' ' . $content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        $englishMarkers = [
            'dear ', ' partners', ' hope ', ' please ', ' your ', ' you ', ' we ',
            ' operate ', ' weekly ', ' trailers ', ' freight ', ' solution ', ' morocco ',
        ];
        $frenchMarkers = [
            'bonjour', ' vous', ' votre', ' vos ', ' nous ', ' nos ', ' sujet',
            'relance', ' suite ', ' échange', ' decouvrez', ' découvrez', ' services',
            'cordialement', 'maritime', 'transport',
        ];

        $englishScore = $this->markerScore($text, $englishMarkers);
        $frenchScore = $this->markerScore($text, $frenchMarkers);

        return $englishScore >= 3 && $englishScore >= ($frenchScore + 2) ? 'en' : 'fr';
    }

    /**
     * @param  array<int, string>  $markers
     */
    private function markerScore(string $text, array $markers): int
    {
        $score = 0;
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                $score++;
            }
        }

        return $score;
    }

    private function campaignsGet(string $path, array $query): array
    {
        $url = $this->apiUrl . '/' . ltrim($path, '/');
        $token = $this->auth->getAccessToken('campaigns');
        $headers = ['Authorization' => 'Zoho-oauthtoken ' . $token];

        $response = Http::withHeaders($headers)->acceptJson()->timeout(30)->get($url, $query);

        if ($response->status() === 401) {
            $this->auth->invalidate('campaigns');
            $token = $this->auth->getAccessToken('campaigns');
            $headers = ['Authorization' => 'Zoho-oauthtoken ' . $token];
            $response = Http::withHeaders($headers)->acceptJson()->timeout(30)->get($url, $query);
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "[ZohoCampaignsTemplatesService] HTTP {$response->status()} on GET {$path}: " . ApiLog::excerpt($response->body(), 300),
            );
        }

        return $response->json() ?? [];
    }

    private function fetchPreviewHtml(string $url): string
    {
        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            Log::warning('[ZohoCampaignsTemplatesService] Preview fetch failed', [
                'url_host' => parse_url($url, PHP_URL_HOST),
                'status' => $response->status(),
            ]);

            return '';
        }

        return $response->body();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractCampaigns(array $body): array
    {
        $campaigns = $body['recent_campaigns'] ?? [];

        return is_array($campaigns) ? $campaigns : [];
    }

    private function normalizePreviewUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://' . ltrim($url, '/');
        }

        return $url;
    }
}
