<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * Read-only lead audit/report generator.
 *
 * This script deliberately contains no INSERT/UPDATE/DELETE/DDL operations.
 * It emits aggregate JSON in --inspect mode. Report generation is added below
 * once the current mirror shape has been verified.
 */

function auditTableCount(string $table, ?callable $scope = null): ?int
{
    if (! Schema::hasTable($table)) {
        return null;
    }

    $query = DB::table($table);
    if ($scope !== null) {
        $scope($query);
    }

    return $query->count();
}

function auditDistribution(string $table, string $column, int $limit = 50): array
{
    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
        return [];
    }

    return DB::table($table)
        ->selectRaw("COALESCE(NULLIF(TRIM({$column}), ''), '[missing]') AS value, COUNT(*) AS total")
        ->whereNull('zoho_deleted_at')
        ->groupBy('value')
        ->orderByDesc('total')
        ->limit($limit)
        ->get()
        ->map(fn (object $row): array => ['value' => (string) $row->value, 'total' => (int) $row->total])
        ->all();
}

function auditRawFieldCoverage(string $table, int $limit = 250): array
{
    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'raw_payload')) {
        return [];
    }

    $coverage = [];
    foreach (DB::table($table)->select('raw_payload')->orderBy('id')->lazy() as $row) {
        $payload = is_string($row->raw_payload)
            ? json_decode($row->raw_payload, true, flags: JSON_THROW_ON_ERROR)
            : (array) $row->raw_payload;

        foreach ($payload as $key => $value) {
            if ($value === null || $value === '' || $value === [] || $value === false) {
                continue;
            }

            $coverage[(string) $key] = ($coverage[(string) $key] ?? 0) + 1;
        }
    }

    arsort($coverage);

    return array_slice($coverage, 0, $limit, true);
}

/** @return list<string> */
function auditLiveLeadFields(): array
{
    return [
        'id', 'Owner', 'First_Name', 'Last_Name', 'Full_Name', 'Company', 'Email', 'Phone', 'Mobile',
        'T_l_phone_2', 'Intitul_de_Poste', 'Type_de_client', 'Type_de_transport_utilis', 'Langue',
        'Adresse', 'City', 'Website', 'Incoterm', 'Destination', 'Volume', 'Prestataire_Concurrent',
        'Provenance_Destination', 'Observation', 'Email_Opt_Out', 'Unsubscribed_Mode', 'Unsubscribed_Time',
        'Last_Activity_Time', 'Tag', 'Converted__s', 'Converted_Account', 'Converted_Contact', 'Converted_Deal',
        'Converted_Date_Time', 'Lead_Status', 'Country', 'Secteur_Activit', 'Created_Time', 'Modified_Time',
        'Lead_Source', 'Actif', 'Ech_bance', 'Type_Affectation_Commercial', 'Num_ro_Comptable', 'ICE', 'R_C',
        'I_F', 'CNSS', 'Patente', 'Centre_R_C', 'Mode_Paiement',
    ];
}

function auditEphemeralZohoToken(bool $forceRefresh = false): string
{
    $row = Schema::hasTable('zoho_tokens')
        ? DB::table('zoho_tokens')->where('service', 'crm')->first()
        : null;

    if (! $forceRefresh && is_string($row?->access_token ?? null) && $row->access_token !== '') {
        $expiresAt = $row?->expires_at ? Carbon::parse($row->expires_at) : null;
        if ($expiresAt?->isAfter(now()->addMinute())) {
            return $row->access_token;
        }
    }

    $refreshToken = is_string($row?->refresh_token ?? null) && $row->refresh_token !== ''
        ? $row->refresh_token
        : (string) config('services.zoho.crm.refresh_token');
    $clientId = (string) config('services.zoho.crm.client_id');
    $clientSecret = (string) config('services.zoho.crm.client_secret');
    if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Zoho CRM OAuth configuration is incomplete.');
    }

    $accountsUrl = rtrim((string) config('services.zoho.crm.accounts_url', 'https://accounts.zoho.com'), '/');
    $response = Http::asForm()->timeout(30)->post($accountsUrl.'/oauth/v2/token', [
        'refresh_token' => $refreshToken,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'refresh_token',
    ]);
    if (! $response->successful() || ! is_string($response->json('access_token'))) {
        $code = $response->json('error') ?? $response->json('code') ?? 'invalid_response';
        $safeCode = is_string($code) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $code) === 1 ? $code : 'invalid_response';
        throw new RuntimeException("Zoho OAuth refresh failed (HTTP {$response->status()}, {$safeCode}).");
    }

    // Deliberately ephemeral: do not persist the refreshed token or touch zoho_tokens.
    return (string) $response->json('access_token');
}

/** @return array{status:int,payload:array<string,mixed>,headers:array<string,?string>} */
function auditZohoGet(string $path, array $query, string &$token, bool &$refreshed): array
{
    if (! str_starts_with($path, '/') || str_contains($path, '://') || str_contains($path, '?')) {
        throw new RuntimeException('Unsafe Zoho path.');
    }

    $api = rtrim((string) config('services.zoho.crm.api_url', 'https://www.zohoapis.com/crm/v8'), '/');
    $origin = preg_replace('#/crm/v\d+$#i', '', $api) ?: $api;
    $url = rtrim($origin, '/').'/crm/v8'.$path;
    $attempt = 0;

    do {
        $response = Http::acceptJson()
            ->timeout(30)
            ->withHeaders(['Authorization' => 'Zoho-oauthtoken '.$token])
            ->get($url, $query);
        $attempt++;

        if ($response->status() === 401 && ! $refreshed) {
            $token = auditEphemeralZohoToken(true);
            $refreshed = true;
            continue;
        }

        if (($response->status() === 429 || $response->serverError()) && $attempt < 4) {
            $retryAfter = is_numeric($response->header('Retry-After'))
                ? min(30, max(1, (int) $response->header('Retry-After')))
                : min(8, 2 ** ($attempt - 1));
            sleep($retryAfter);
            continue;
        }

        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload)) {
            $code = is_array($payload) ? ($payload['code'] ?? null) : null;
            $safeCode = is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $code) === 1 ? $code : 'http_'.$response->status();
            throw new RuntimeException("Zoho GET {$path} failed (HTTP {$response->status()}, {$safeCode}).");
        }

        return [
            'status' => $response->status(),
            'payload' => $payload,
            'headers' => [
                'rate_remaining' => is_string($response->header('X-RateLimit-Remaining')) ? $response->header('X-RateLimit-Remaining') : null,
                'request_id_present' => is_string($response->header('X-Request-Id') ?? $response->header('request_id')) ? 'yes' : null,
            ],
        ];
    } while ($attempt < 4);

    throw new RuntimeException("Zoho GET {$path} exhausted retries.");
}

function auditProbeLiveZoho(): array
{
    $token = auditEphemeralZohoToken();
    $refreshed = false;

    $modules = auditZohoGet('/settings/modules', [], $token, $refreshed);
    $fields = auditZohoGet('/settings/fields', ['module' => 'Leads'], $token, $refreshed);
    $layouts = auditZohoGet('/settings/layouts', ['module' => 'Leads'], $token, $refreshed);
    $related = auditZohoGet('/settings/related_lists', ['module' => 'Leads'], $token, $refreshed);
    $sample = auditZohoGet('/Leads', [
        'fields' => implode(',', auditLiveLeadFields()),
        'converted' => 'both',
        'per_page' => 2,
        'sort_by' => 'id',
        'sort_order' => 'asc',
    ], $token, $refreshed);

    $fieldRows = is_array($fields['payload']['fields'] ?? null) ? $fields['payload']['fields'] : [];
    $leadStatus = collect($fieldRows)->firstWhere('api_name', 'Lead_Status');
    $safePicklist = collect(is_array($leadStatus['pick_list_values'] ?? null) ? $leadStatus['pick_list_values'] : [])
        ->map(fn (array $value): array => [
            'display_value' => is_string($value['display_value'] ?? null) ? $value['display_value'] : null,
            'actual_value' => is_string($value['actual_value'] ?? null) ? $value['actual_value'] : null,
        ])->values()->all();
    $safeRelated = collect(is_array($related['payload']['related_lists'] ?? null) ? $related['payload']['related_lists'] : [])
        ->map(fn (array $value): array => array_filter([
            'api_name' => is_string($value['api_name'] ?? null) ? $value['api_name'] : null,
            'display_label' => is_string($value['display_label'] ?? null) ? $value['display_label'] : null,
            'module' => is_string($value['module'] ?? null) ? $value['module'] : null,
        ], fn ($value): bool => $value !== null))
        ->values()->all();

    return [
        'verified_at' => now()->utc()->toIso8601String(),
        'oauth_refreshed_ephemerally' => $refreshed,
        'statuses' => [
            'modules' => $modules['status'],
            'lead_fields' => $fields['status'],
            'lead_layouts' => $layouts['status'],
            'lead_related_lists' => $related['status'],
            'lead_records_probe' => $sample['status'],
        ],
        'counts' => [
            'modules' => count(is_array($modules['payload']['modules'] ?? null) ? $modules['payload']['modules'] : []),
            'lead_fields' => count($fieldRows),
            'lead_layouts' => count(is_array($layouts['payload']['layouts'] ?? null) ? $layouts['payload']['layouts'] : []),
            'lead_related_lists' => count($safeRelated),
            'probe_records' => count(is_array($sample['payload']['data'] ?? null) ? $sample['payload']['data'] : []),
        ],
        'lead_status_picklist' => $safePicklist,
        'related_lists' => $safeRelated,
        'probe_info' => array_intersect_key(
            is_array($sample['payload']['info'] ?? null) ? $sample['payload']['info'] : [],
            array_flip(['per_page', 'count', 'more_records', 'page']),
        ),
    ];
}

function auditFetchLiveMetadata(): array
{
    $token = auditEphemeralZohoToken();
    $refreshed = false;
    $modules = auditZohoGet('/settings/modules', [], $token, $refreshed);
    $fields = auditZohoGet('/settings/fields', ['module' => 'Leads'], $token, $refreshed);
    $layouts = auditZohoGet('/settings/layouts', ['module' => 'Leads'], $token, $refreshed);
    $related = auditZohoGet('/settings/related_lists', ['module' => 'Leads'], $token, $refreshed);

    $safeFields = collect(is_array($fields['payload']['fields'] ?? null) ? $fields['payload']['fields'] : [])
        ->map(fn (array $field): array => array_filter([
            'api_name' => is_string($field['api_name'] ?? null) ? $field['api_name'] : null,
            'field_label' => is_string($field['field_label'] ?? null) ? $field['field_label'] : null,
            'data_type' => is_string($field['data_type'] ?? null) ? $field['data_type'] : null,
            'required' => is_bool($field['required'] ?? null) ? $field['required'] : null,
            'read_only' => is_bool($field['read_only'] ?? null) ? $field['read_only'] : null,
            'pick_list_values' => collect(is_array($field['pick_list_values'] ?? null) ? $field['pick_list_values'] : [])
                ->map(fn (array $value): array => array_filter([
                    'display_value' => is_string($value['display_value'] ?? null) ? $value['display_value'] : null,
                    'actual_value' => is_string($value['actual_value'] ?? null) ? $value['actual_value'] : null,
                ], fn ($value): bool => $value !== null))->values()->all(),
        ], fn ($value): bool => $value !== null))
        ->values()->all();
    $statusField = collect($safeFields)->firstWhere('api_name', 'Lead_Status');
    $safeRelated = collect(is_array($related['payload']['related_lists'] ?? null) ? $related['payload']['related_lists'] : [])
        ->map(fn (array $value): array => array_filter([
            'api_name' => is_string($value['api_name'] ?? null) ? $value['api_name'] : null,
            'display_label' => is_string($value['display_label'] ?? null) ? $value['display_label'] : null,
            'module' => is_string($value['module'] ?? null) ? $value['module'] : null,
        ], fn ($value): bool => $value !== null))
        ->values()->all();

    return [
        'verified_at' => now()->utc()->toIso8601String(),
        'oauth_refreshed_ephemerally' => $refreshed,
        'statuses' => [
            'modules' => $modules['status'],
            'lead_fields' => $fields['status'],
            'lead_layouts' => $layouts['status'],
            'lead_related_lists' => $related['status'],
        ],
        'fields' => $safeFields,
        'lead_status_picklist' => is_array($statusField['pick_list_values'] ?? null) ? $statusField['pick_list_values'] : [],
        'related_lists' => $safeRelated,
        'module_count' => count(is_array($modules['payload']['modules'] ?? null) ? $modules['payload']['modules'] : []),
        'layout_count' => count(is_array($layouts['payload']['layouts'] ?? null) ? $layouts['payload']['layouts'] : []),
    ];
}

/** @return array{records:array<string,array<string,mixed>>,requests:int,refreshed:bool,first_status:int,last_status:int} */
function auditFetchAllLiveLeads(): array
{
    $token = auditEphemeralZohoToken();
    $refreshed = false;
    $records = [];
    $pageToken = null;
    $requests = 0;
    $firstStatus = 0;
    $lastStatus = 0;

    do {
        $query = [
            'fields' => implode(',', auditLiveLeadFields()),
            'converted' => 'both',
            'per_page' => 200,
            'sort_by' => 'id',
            'sort_order' => 'asc',
        ];
        if ($pageToken !== null) {
            $query['page_token'] = $pageToken;
        }

        $result = auditZohoGet('/Leads', $query, $token, $refreshed);
        $requests++;
        $firstStatus = $firstStatus ?: $result['status'];
        $lastStatus = $result['status'];
        $data = $result['payload']['data'] ?? null;
        $info = $result['payload']['info'] ?? null;
        if (! is_array($data) || ! array_is_list($data) || ! is_array($info)) {
            throw new RuntimeException('Zoho Leads list returned an invalid response shape.');
        }

        foreach ($data as $row) {
            if (! is_array($row) || ! is_scalar($row['id'] ?? null) || (string) $row['id'] === '') {
                throw new RuntimeException('Zoho Leads list returned an invalid record identity.');
            }
            $id = (string) $row['id'];
            if (isset($records[$id])) {
                throw new RuntimeException('Zoho Leads pagination returned a duplicate identity.');
            }
            $records[$id] = $row;
        }

        $more = $info['more_records'] ?? null;
        $next = $info['next_page_token'] ?? null;
        if (! is_bool($more) || ($more && (! is_string($next) || $next === '')) || (! $more && $next !== null)) {
            throw new RuntimeException('Zoho Leads continuation metadata was inconsistent.');
        }
        $pageToken = $more ? $next : null;

        if ($requests > 500 || count($records) > 100000) {
            throw new RuntimeException('Zoho Leads traversal exceeded the safety ceiling.');
        }

        if ($pageToken !== null) {
            usleep(700000);
        }
    } while ($pageToken !== null);

    return [
        'records' => $records,
        'requests' => $requests,
        'refreshed' => $refreshed,
        'first_status' => $firstStatus,
        'last_status' => $lastStatus,
    ];
}

function auditLiveLeadSummary(): array
{
    $live = auditFetchAllLiveLeads();
    $statusCounts = [];
    $converted = 0;
    $missingEmail = 0;
    $optedOut = 0;
    $modified = [];

    foreach ($live['records'] as $record) {
        $status = trim((string) ($record['Lead_Status'] ?? '')) ?: '[missing]';
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        $converted += ($record['Converted__s'] ?? false) === true ? 1 : 0;
        $missingEmail += trim((string) ($record['Email'] ?? '')) === '' ? 1 : 0;
        $optedOut += (($record['Email_Opt_Out'] ?? false) === true || trim((string) ($record['Unsubscribed_Time'] ?? '')) !== '') ? 1 : 0;
        if (is_string($record['Modified_Time'] ?? null) && $record['Modified_Time'] !== '') {
            $modified[] = $record['Modified_Time'];
        }
    }
    arsort($statusCounts);

    $localIds = Schema::hasTable('zoho_leads')
        ? DB::table('zoho_leads')->pluck('zoho_id')->map(fn ($id): string => (string) $id)->all()
        : [];
    $liveIds = array_keys($live['records']);

    return [
        'verified_at' => now()->utc()->toIso8601String(),
        'http_status' => ['first_page' => $live['first_status'], 'last_page' => $live['last_status']],
        'requests' => $live['requests'],
        'oauth_refreshed_ephemerally' => $live['refreshed'],
        'fields_requested' => count(auditLiveLeadFields()),
        'records' => count($live['records']),
        'unique_ids' => count(array_unique($liveIds)),
        'converted' => $converted,
        'unconverted' => count($live['records']) - $converted,
        'missing_email' => $missingEmail,
        'opted_out_or_unsubscribed' => $optedOut,
        'oldest_modified_time' => $modified === [] ? null : min($modified),
        'latest_modified_time' => $modified === [] ? null : max($modified),
        'status_distribution' => $statusCounts,
        'coverage_vs_local' => [
            'local_ids' => count($localIds),
            'missing_live' => count(array_diff($localIds, $liveIds)),
            'new_live' => count(array_diff($liveIds, $localIds)),
        ],
    ];
}

function auditRelationshipSummary(): array
{
    if (! Schema::hasTable('zoho_leads')) {
        return [];
    }

    $leads = DB::table('zoho_leads as lead')->whereNull('lead.zoho_deleted_at');
    $normalizedExpression = "LOWER(TRIM(contact.email))";

    $withExists = static function (string $table, callable $correlation) use ($leads): int {
        return (clone $leads)->whereExists(function ($query) use ($table, $correlation): void {
            $query->selectRaw('1')->from($table.' as related')->whereNull('related.zoho_deleted_at');
            $correlation($query);
        })->count();
    };

    $statusByConversion = DB::table('zoho_leads')
        ->selectRaw("COALESCE(NULLIF(TRIM(status), ''), '[missing]') AS status_value, COALESCE(is_converted, 0) AS converted, COUNT(*) AS total")
        ->whereNull('zoho_deleted_at')
        ->groupBy('status_value', 'converted')
        ->orderBy('status_value')
        ->orderBy('converted')
        ->get()
        ->map(fn (object $row): array => [
            'status' => (string) $row->status_value,
            'converted' => (bool) $row->converted,
            'total' => (int) $row->total,
        ])->all();

    $emailGroups = DB::table('zoho_leads')
        ->select('normalized_email')
        ->selectRaw('COUNT(*) AS total')
        ->whereNull('zoho_deleted_at')
        ->whereNotNull('normalized_email')
        ->where('normalized_email', '<>', '')
        ->groupBy('normalized_email')
        ->havingRaw('COUNT(*) > 1');

    return [
        'status_by_conversion' => $statusByConversion,
        'unique_nonempty_emails' => DB::table('zoho_leads')->whereNull('zoho_deleted_at')->whereNotNull('normalized_email')->where('normalized_email', '<>', '')->distinct()->count('normalized_email'),
        'duplicate_email_groups' => DB::query()->fromSub($emailGroups, 'duplicates')->count(),
        'leads_in_duplicate_email_groups' => (int) DB::query()->fromSub($emailGroups, 'duplicates')->sum('total'),
        'exact_local_contact_email_match' => (clone $leads)->whereExists(function ($query) use ($normalizedExpression): void {
            $query->selectRaw('1')->from('contacts as contact')->whereRaw($normalizedExpression.' = lead.normalized_email');
        })->count(),
        'exact_suppression_match' => Schema::hasTable('suppressions') ? (clone $leads)->whereExists(function ($query): void {
            $query->selectRaw('1')->from('suppressions as suppression')->whereRaw('LOWER(TRIM(suppression.email)) = lead.normalized_email');
        })->count() : null,
        'direct_activity' => $withExists('zoho_activities', fn ($query) => $query->where(function ($nested): void {
            $nested->whereColumn('related.parent_zoho_id', 'lead.zoho_id')->orWhereColumn('related.contact_zoho_id', 'lead.zoho_id');
        })),
        'activity_including_conversion_context' => $withExists('zoho_activities', fn ($query) => $query->where(function ($nested): void {
            $nested->whereColumn('related.parent_zoho_id', 'lead.zoho_id')
                ->orWhereColumn('related.contact_zoho_id', 'lead.zoho_id')
                ->orWhereColumn('related.parent_zoho_id', 'lead.account_zoho_id')
                ->orWhereColumn('related.contact_zoho_id', 'lead.contact_zoho_id')
                ->orWhereColumn('related.parent_zoho_id', 'lead.converted_deal_zoho_id');
        })),
        'commercial_action_context' => $withExists('zoho_actions_commercials', fn ($query) => $query->where(function ($nested): void {
            $nested->whereColumn('related.parent_zoho_id', 'lead.zoho_id')
                ->orWhereColumn('related.parent_zoho_id', 'lead.account_zoho_id')
                ->orWhereColumn('related.parent_zoho_id', 'lead.contact_zoho_id')
                ->orWhereColumn('related.parent_zoho_id', 'lead.converted_deal_zoho_id');
        })),
        'deal_context' => $withExists('zoho_deals', fn ($query) => $query->where(function ($nested): void {
            $nested->whereColumn('related.zoho_id', 'lead.converted_deal_zoho_id')
                ->orWhereColumn('related.account_zoho_id', 'lead.account_zoho_id')
                ->orWhereColumn('related.contact_zoho_id', 'lead.contact_zoho_id');
        })),
        'quote_context' => $withExists('zoho_quotes', fn ($query) => $query->where(function ($nested): void {
            $nested->whereColumn('related.deal_zoho_id', 'lead.converted_deal_zoho_id')
                ->orWhereColumn('related.account_zoho_id', 'lead.account_zoho_id')
                ->orWhereColumn('related.contact_zoho_id', 'lead.contact_zoho_id');
        })),
        'last_activity_recency' => [
            '0_30_days' => (clone $leads)->where('lead.last_activity_at', '>=', now()->subDays(30))->count(),
            '31_90_days' => (clone $leads)->whereBetween('lead.last_activity_at', [now()->subDays(90), now()->subDays(30)])->count(),
            '91_365_days' => (clone $leads)->whereBetween('lead.last_activity_at', [now()->subDays(365), now()->subDays(90)])->count(),
            'older_than_365_days' => (clone $leads)->where('lead.last_activity_at', '<', now()->subDays(365))->count(),
            'missing' => (clone $leads)->whereNull('lead.last_activity_at')->count(),
        ],
    ];
}

$options = getopt('', ['inspect', 'probe-live', 'fetch-live-summary', 'generate', 'live', 'detailed:', 'summary:']);

if (isset($options['probe-live'])) {
    echo json_encode(auditProbeLiveZoho(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if (isset($options['fetch-live-summary'])) {
    echo json_encode(auditLiveLeadSummary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if (isset($options['generate'])) {
    $detailedPath = is_string($options['detailed'] ?? null) ? trim($options['detailed']) : '';
    $summaryPath = is_string($options['summary'] ?? null) ? trim($options['summary']) : '';
    if ($detailedPath === '' || $summaryPath === '') {
        throw new RuntimeException('--generate requires --detailed and --summary output paths.');
    }

    $liveMode = isset($options['live']);
    $metadata = $liveMode ? auditFetchLiveMetadata() : [
        'statuses' => [], 'fields' => [], 'lead_status_picklist' => [], 'related_lists' => [],
    ];
    $live = $liveMode ? auditFetchAllLiveLeads() : [
        'records' => [], 'requests' => 0, 'refreshed' => false, 'first_status' => 0, 'last_status' => 0,
    ];

    require_once __DIR__.'/ZohoLeadAuditGenerator.php';
    $generator = new ZohoLeadAuditGenerator($live['records'], $metadata, $liveMode);
    $reports = $generator->generate();

    foreach ([[$detailedPath, $reports['detailed']], [$summaryPath, $reports['summary']]] as [$path, $contents]) {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create report directory [{$directory}].");
        }
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write report [{$path}].");
        }
    }

    echo json_encode([
        'generated_at' => now()->utc()->toIso8601String(),
        'live_mode' => $liveMode,
        'live_requests' => $live['requests'] + ($liveMode ? 4 : 0),
        'live_records' => count($live['records']),
        'lead_count' => $reports['dataset']['meta']['lead_count'],
        'timeline_event_count' => $reports['dataset']['meta']['timeline_event_count'],
        'category_counts' => $reports['dataset']['category_counts'],
        'detailed' => ['path' => $detailedPath, 'bytes' => filesize($detailedPath), 'sha256' => hash_file('sha256', $detailedPath)],
        'summary' => ['path' => $summaryPath, 'bytes' => filesize($summaryPath), 'sha256' => hash_file('sha256', $summaryPath)],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if (! isset($options['inspect'])) {
    fwrite(STDERR, "Use --inspect, --probe-live, --fetch-live-summary, or --generate.\n");
    exit(2);
}

$leadCount = auditTableCount('zoho_leads') ?? 0;
$currentLeads = auditTableCount('zoho_leads', fn ($q) => $q->whereNull('zoho_deleted_at')) ?? 0;
$deletedLeads = $leadCount - $currentLeads;

$leadMetrics = Schema::hasTable('zoho_leads') ? [
    'converted' => DB::table('zoho_leads')->whereNull('zoho_deleted_at')->where('is_converted', true)->count(),
    'unconverted' => DB::table('zoho_leads')->whereNull('zoho_deleted_at')->where(fn ($q) => $q->where('is_converted', false)->orWhereNull('is_converted'))->count(),
    'missing_email' => DB::table('zoho_leads')->whereNull('zoho_deleted_at')->where(fn ($q) => $q->whereNull('normalized_email')->orWhere('normalized_email', ''))->count(),
    'email_opt_out' => Schema::hasColumn('zoho_leads', 'email_opt_out')
        ? DB::table('zoho_leads')->whereNull('zoho_deleted_at')->where('email_opt_out', true)->count()
        : null,
    'unsubscribed' => Schema::hasColumn('zoho_leads', 'unsubscribed_at')
        ? DB::table('zoho_leads')->whereNull('zoho_deleted_at')->whereNotNull('unsubscribed_at')->count()
        : null,
    'linked_fretiq_contact' => Schema::hasColumn('zoho_leads', 'fretiq_contact_id')
        ? DB::table('zoho_leads')->whereNull('zoho_deleted_at')->whereNotNull('fretiq_contact_id')->count()
        : null,
    'last_synced_at' => DB::table('zoho_leads')->max('last_synced_at'),
    'latest_zoho_modified_at' => DB::table('zoho_leads')->max('zoho_modified_at'),
] : [];

$tables = [
    'zoho_accounts', 'zoho_contacts', 'zoho_leads', 'zoho_deals', 'zoho_quotes',
    'zoho_activities', 'zoho_deal_stage_history', 'zoho_quote_status_history',
    'zoho_actions_commercials', 'zoho_sync_batches', 'zoho_sync_logs',
    'contacts', 'campaigns', 'campaign_runs', 'campaign_recipients',
    'sequence_step_sends', 'email_tracking_events', 'inbox_emails', 'demandes', 'suppressions',
];

$counts = [];
foreach ($tables as $table) {
    $counts[$table] = auditTableCount($table);
}

$result = [
    'generated_at' => now()->utc()->toIso8601String(),
    'database_driver' => DB::connection()->getDriverName(),
    'leads' => [
        'all_rows' => $leadCount,
        'current' => $currentLeads,
        'tombstoned' => $deletedLeads,
        ...$leadMetrics,
        'status_distribution' => auditDistribution('zoho_leads', 'status'),
        'source_distribution' => auditDistribution('zoho_leads', 'lead_source'),
        'country_distribution' => auditDistribution('zoho_leads', 'country', 25),
    ],
    'table_counts' => $counts,
    'activity_types' => auditDistribution('zoho_activities', 'activity_type'),
    'activity_statuses' => auditDistribution('zoho_activities', 'status'),
    'relationship_summary' => auditRelationshipSummary(),
    'lead_raw_field_coverage' => auditRawFieldCoverage('zoho_leads'),
    'relevant_columns' => collect([
        'campaign_recipients', 'campaign_runs', 'campaigns', 'sequence_step_sends',
        'email_tracking_events', 'inbox_emails', 'demandes', 'suppressions', 'contacts',
        'zoho_activities', 'zoho_actions_commercials', 'zoho_deals', 'zoho_quotes',
    ])->mapWithKeys(fn (string $table): array => [
        $table => Schema::hasTable($table) ? Schema::getColumnListing($table) : [],
    ])->all(),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
