<?php

namespace App\Services\Zoho;

use App\Models\CampaignTemplate;
use App\Models\ZohoSyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ZohoCrmTemplatesService — imports Zoho CRM email templates into campaign_templates.
 *
 * VERIFIED 2026-06-10 — live tinker STATUS 200 for:
 *   GET /crm/v8/settings/modules                       → 200, array under "modules"
 *   GET /crm/v8/settings/email_templates?module=Contacts  → 200, array under "email_templates"
 *   GET /crm/v8/settings/email_templates?module=Leads     → 200, same shape
 *   GET /crm/v8/settings/email_templates/{id}             → 200, wrapped in PLURAL "email_templates"
 *     as a single-item array (live-verified 2026-06-10).
 *     • `content` holds full HTML body; `mail_content` is a duplicate — use `content`.
 *     • Legacy singular "email_template" wrapper kept as fallback; object-or-array unwrap handled defensively.
 *
 * HTTP error-handling mirrors RealCrmClient:
 *   401 → invalidate('crm') + single retry
 *   429 → sleep(min(Retry-After, 5)) + single retry
 *   other failure → RuntimeException with status + body
 */
class ZohoCrmTemplatesService
{
    /** Fallback modules used when settings/modules is unavailable. */
    private const DEFAULT_TEMPLATE_MODULES = ['Contacts', 'Leads'];

    /** Safety cap: maximum pages fetched per module to prevent runaway. */
    private const MAX_PAGES = 10;

    /** Base API URL for Zoho CRM v8. */
    private const API_BASE = 'https://www.zohoapis.com/crm/v8';

    public function __construct(
        private readonly ZohoAuthService $auth,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch all email templates from Zoho CRM (Contacts + Leads modules),
     * dedup by id across modules, then detail-fetch each for `content`.
     *
     * @return array<int, array{zoho_id: string, name: string, subject: string, content: string, module: string}>
     *
     * @throws \RuntimeException  On unrecoverable HTTP error.
     */
    public function fetchRemote(): array
    {
        $byId = [];

        foreach ($this->templateModules() as $module) {
            $page = 1;

            do {
                try {
                    $body = $this->httpGet('settings/email_templates', [
                        'module'   => $module,
                        'per_page' => 200,
                        'page'     => $page,
                    ]);
                } catch (\RuntimeException $e) {
                    if ($this->isInvalidEmailTemplateModule($e)) {
                        Log::debug("[ZohoCrmTemplatesService] Module {$module} ignored — no email_templates support");
                        break;
                    }

                    throw $e;
                }

                $items     = $body['email_templates'] ?? [];
                $morePages = (bool) ($body['info']['more_records'] ?? false);

                foreach ($items as $item) {
                    $id = (string) ($item['id'] ?? '');
                    if ($id === '' || isset($byId[$id])) {
                        // Already seen from the other module or missing id — skip.
                        continue;
                    }

                    $byId[$id] = [
                        'zoho_id' => $id,
                        'name'    => (string) ($item['name'] ?? ''),
                        'subject' => (string) ($item['subject'] ?? ''),
                        'content' => '',     // filled in the detail pass
                        'module'  => $module,
                    ];
                }

                $page++;
            } while ($morePages && $page <= self::MAX_PAGES);
        }

        // ── Detail pass: fetch content for each template ───────────────────
        foreach (array_keys($byId) as $id) {
            $body = $this->httpGet("settings/email_templates/{$id}", []);

            // Wrapper may be object directly OR single-item array
            $raw = $body['email_templates'] ?? $body['email_template'] ?? [];
            if (is_array($raw) && isset($raw[0])) {
                $raw = $raw[0];
            }

            $byId[$id]['content'] = (string) ($raw['content'] ?? '');
            // Fallback to mail_content if content is empty
            if ($byId[$id]['content'] === '') {
                $byId[$id]['content'] = (string) ($raw['mail_content'] ?? '');
            }
        }

        return array_values($byId);
    }

    /**
     * Import Zoho email templates into campaign_templates.
     *
     * Idempotency rules:
     *   1. Find by zoho_template_id → update.
     *   2. Else find by exact name and null zoho_template_id → adopt (set id) + update.
     *   3. Else create new row.
     *
     * Skip rule: template where subject or content is empty after detail fetch.
     *
     * Writes one ZohoSyncLog row (module='EmailTemplates') with counts + status.
     *
     * @return array{imported: int, updated: int, skipped: int}
     *
     * @throws \RuntimeException  Re-thrown after the sync log is written.
     */
    public function import(): array
    {
        $imported  = 0;
        $updated   = 0;
        $skipped   = 0;
        $startedAt = microtime(true);
        $status    = 'success';
        $error     = null;

        try {
            $templates = $this->fetchRemote();

            foreach ($templates as $tpl) {
                $subject = trim($tpl['subject'] ?? '');
                $content = trim($tpl['content'] ?? '');

                if ($subject === '' || $content === '') {
                    $skipped++;
                    Log::debug("[ZohoCrmTemplatesService] Skipped template {$tpl['zoho_id']} — empty subject or content");
                    continue;
                }

                // ── 1. Find by zoho_template_id ───────────────────────────────
                $row = CampaignTemplate::where('zoho_template_id', $tpl['zoho_id'])->first();

                if ($row !== null) {
                    // Update existing row.
                    $row->name         = $tpl['name'];
                    $row->subject      = $subject;
                    $row->html_content = $content;
                    $row->save();
                    $updated++;
                    continue;
                }

                // ── 2. Adopt by exact name match (null zoho_template_id) ──────
                $row = CampaignTemplate::whereNull('zoho_template_id')
                    ->where('name', $tpl['name'])
                    ->first();

                if ($row !== null) {
                    $row->zoho_template_id = $tpl['zoho_id'];
                    $row->subject          = $subject;
                    $row->html_content     = $content;
                    $row->save();
                    $updated++;
                    continue;
                }

                // ── 3. Create new ─────────────────────────────────────────────
                CampaignTemplate::create([
                    'name'             => $tpl['name'],
                    'subject'          => $subject,
                    'html_content'     => $content,
                    'zoho_template_id' => $tpl['zoho_id'],
                ]);
                $imported++;
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $error  = $e->getMessage();
            Log::error("[ZohoCrmTemplatesService] import() failed: {$error}");
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        ZohoSyncLog::create([
            'module'         => 'EmailTemplates',
            'synced_at'      => now(),
            'records_synced' => $imported + $updated,
            'status'         => $status,
            'error'          => $error,
            'duration_ms'    => $durationMs,
        ]);

        if ($status === 'error') {
            // Re-throw so the controller can flash the error.
            throw new \RuntimeException($error ?? 'Import failed');
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Discover CRM modules that can carry email templates.
     *
     * Zoho stores CRM email templates per module. Fetching only Contacts + Leads
     * hides templates attached to Deals, Quotes, and custom modules. The modules
     * endpoint is therefore used as the source of truth, with the historical
     * Contacts/Leads pair kept first and as a safe fallback.
     *
     * @return array<int, string>
     */
    private function templateModules(): array
    {
        try {
            $body = $this->httpGet('settings/modules', []);
        } catch (\Throwable $e) {
            Log::warning('[ZohoCrmTemplatesService] settings/modules unavailable — falling back to Contacts + Leads', [
                'error' => $e->getMessage(),
            ]);

            return self::DEFAULT_TEMPLATE_MODULES;
        }

        $modules = [];

        foreach (($body['modules'] ?? []) as $module) {
            $apiName = trim((string) ($module['api_name'] ?? ''));
            if ($apiName === '') {
                continue;
            }

            if (array_key_exists('api_supported', $module) && ! (bool) $module['api_supported']) {
                continue;
            }

            $modules[] = $apiName;
        }

        return array_values(array_unique(array_merge(
            self::DEFAULT_TEMPLATE_MODULES,
            $modules ?: self::DEFAULT_TEMPLATE_MODULES,
        )));
    }

    /**
     * Some system/subform modules are listed by settings/modules but rejected by
     * settings/email_templates. Skip only that exact Zoho module-validation case.
     */
    private function isInvalidEmailTemplateModule(\RuntimeException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'INVALID_DATA')
            && str_contains($message, 'param_name')
            && str_contains($message, 'module');
    }

    /**
     * Authenticated GET against the Zoho CRM v8 API.
     *
     * Error handling mirrors RealCrmClient:
     *   401 → invalidate + single retry
     *   429 → bounded sleep + single retry
     *   204/304 → return []
     *   other failure → RuntimeException
     *
     * @param  string  $path   Relative path (e.g. 'settings/email_templates')
     * @param  array   $query  Query parameters
     */
    private function httpGet(string $path, array $query): array
    {
        $url     = self::API_BASE . '/' . ltrim($path, '/');
        $token   = $this->auth->getAccessToken('crm');
        $headers = ['Authorization' => 'Zoho-oauthtoken ' . $token];

        $response = Http::withHeaders($headers)->timeout(30)->get($url, $query);

        // ── 401 → refresh once and retry ─────────────────────────────────────
        if ($response->status() === 401) {
            Log::info("[ZohoCrmTemplatesService] 401 on {$path} — refreshing token and retrying");
            $this->auth->invalidate('crm');
            $token    = $this->auth->getAccessToken('crm');
            $headers  = ['Authorization' => 'Zoho-oauthtoken ' . $token];
            $response = Http::withHeaders($headers)->timeout(30)->get($url, $query);
        }

        // ── 429 → Retry-After sleep + single retry ────────────────────────────
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 2);
            $sleep      = min($retryAfter, 5);
            Log::warning("[ZohoCrmTemplatesService] 429 on {$path} — sleeping {$sleep}s then retry");
            sleep($sleep);
            $response = Http::withHeaders($headers)->timeout(30)->get($url, $query);
        }

        // ── No-content — treat as empty ───────────────────────────────────────
        if (in_array($response->status(), [204, 304], true)) {
            return [];
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "[ZohoCrmTemplatesService] HTTP {$response->status()} on GET {$path}: " . $response->body()
            );
        }

        return $response->json() ?? [];
    }
}
