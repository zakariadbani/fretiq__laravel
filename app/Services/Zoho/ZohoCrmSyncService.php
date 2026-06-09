<?php

namespace App\Services\Zoho;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ZohoCrmSyncService — lean pull of Zoho CRM Accounts + Contacts into fretiq.
 *
 * ── Empirical verification (MANDATORY per CLAUDE.md Zoho rule) ───────────────
 * Verified: 2026-06-07, Zoho CRM API v2 (https://www.zohoapis.com/crm/v2).
 *
 *   GET /Accounts?fields=Account_Name,Website,Industry,Billing_Country,Phone&per_page=200
 *     → STATUS 200; each record contains `id` + requested fields.
 *       Industry may be absent/null. Accounts carry NO client email.
 *
 *   GET /Contacts?fields=Email,Full_Name,Title,Phone,Account_Name&per_page=200
 *     → STATUS 200; Account_Name is a LOOKUP OBJECT {"name":..,"id":..}.
 *       Email is sparse (~16 % populated). Full_Name is a plain string.
 *
 * ── Design constraints ────────────────────────────────────────────────────────
 *   - NEVER map owner_email to any contact/send field (it is the internal TCL owner).
 *   - Idempotent: re-running updates existing rows — never duplicates.
 *   - Testable: inject any CrmClient implementation (LocalCrmClient in tests).
 *   - No multi-worker claim logic; single-process, sequential module order.
 *   - Pagination cap: max MAX_PAGES pages per module to prevent runaway.
 */
class ZohoCrmSyncService
{
    /** Safety cap: maximum pages fetched per module in a single sync run. */
    private const MAX_PAGES = 50;

    /**
     * ISO-2 country code map for the most common Billing_Country values seen
     * in the FreightFlow dataset. Keys are case-insensitive after strtolower().
     * Values longer than 2 chars are mapped here; bare 2-char codes pass through.
     */
    private const COUNTRY_MAP = [
        'france'        => 'FR',
        'maroc'         => 'MA',
        'morocco'       => 'MA',
        'espagne'       => 'ES',
        'spain'         => 'ES',
        'belgique'      => 'BE',
        'belgium'       => 'BE',
        'allemagne'     => 'DE',
        'germany'       => 'DE',
        'italie'        => 'IT',
        'italy'         => 'IT',
        'portugal'      => 'PT',
        'pays-bas'      => 'NL',
        'netherlands'   => 'NL',
        'suisse'        => 'CH',
        'switzerland'   => 'CH',
        'sénégal'       => 'SN',
        'senegal'       => 'SN',
        'côte d\'ivoire' => 'CI',
        'ivory coast'   => 'CI',
        'tunisie'       => 'TN',
        'tunisia'       => 'TN',
        'algérie'       => 'DZ',
        'algeria'       => 'DZ',
        'chine'         => 'CN',
        'china'         => 'CN',
        'états-unis'    => 'US',
        'united states' => 'US',
        'usa'           => 'US',
        'royaume-uni'   => 'GB',
        'united kingdom' => 'GB',
        'uk'            => 'GB',
        'turquie'       => 'TR',
        'turkey'        => 'TR',
        'pologne'       => 'PL',
        'poland'        => 'PL',
        'roumanie'      => 'RO',
        'romania'       => 'RO',
    ];

    public function __construct(
        private readonly CrmClient $client,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sync Zoho Accounts → fretiq companies.
     *
     * Pagination: loops while info.more_records === true, capped at MAX_PAGES.
     * Upsert order: zoho_account_id first, then domain (normalized), then create.
     *
     * @return array{records: int, skipped: int, status: string}
     */
    public function syncAccounts(): array
    {
        $synced  = 0;
        $skipped = 0;
        $page    = 1;

        do {
            $response = $this->client->get('Accounts', [
                'fields'   => 'Account_Name,Website,Industry,Billing_Country,Phone',
                'per_page' => 200,
                'page'     => $page,
            ]);

            $records    = $response['data'] ?? [];
            $morePages  = (bool) ($response['info']['more_records'] ?? false);

            foreach ($records as $record) {
                $zohoId = (string) ($record['id'] ?? '');
                if ($zohoId === '') {
                    $skipped++;
                    continue;
                }

                try {
                    $this->upsertCompany($record, $zohoId);
                    $synced++;
                } catch (\Throwable $e) {
                    $skipped++;
                    Log::warning("[ZohoCrmSyncService] syncAccounts: skipped record {$zohoId} — " . $e->getMessage());
                }
            }

            $page++;
        } while ($morePages && $page <= self::MAX_PAGES);

        return ['records' => $synced, 'skipped' => $skipped, 'status' => 'success'];
    }

    /**
     * Sync Zoho Contacts → fretiq contacts.
     *
     * Skip rules (counted in $skipped):
     *   1. Email is empty  → contact not mailable; skip entirely.
     *   2. No resolvable Account (Account_Name absent AND stub-company creation not possible)
     *      → contacts.company_id is NOT NULL → skip.
     *
     * @return array{records: int, skipped: int, status: string}
     */
    public function syncContacts(): array
    {
        $synced  = 0;
        $skipped = 0;
        $page    = 1;

        do {
            $response = $this->client->get('Contacts', [
                'fields'   => 'Email,Full_Name,Title,Phone,Account_Name',
                'per_page' => 200,
                'page'     => $page,
            ]);

            $records   = $response['data'] ?? [];
            $morePages = (bool) ($response['info']['more_records'] ?? false);

            foreach ($records as $record) {
                $zohoId = (string) ($record['id'] ?? '');

                // ── Rule 1: skip contacts without an email ────────────────────
                $email = trim((string) ($record['Email'] ?? ''));
                if ($email === '') {
                    $skipped++;
                    continue;
                }

                // ── Rule 2: resolve company — required (NOT NULL FK) ──────────
                $accId   = (string) (data_get($record, 'Account_Name.id') ?? '');
                $accName = (string) (data_get($record, 'Account_Name.name') ?? '');

                $company = $this->resolveOrCreateCompany($accId, $accName);

                if ($company === null) {
                    // No Account_Name present at all — cannot satisfy NOT NULL FK.
                    $skipped++;
                    Log::debug("[ZohoCrmSyncService] syncContacts: skipped contact {$zohoId} — no Account_Name");
                    continue;
                }

                try {
                    $this->upsertContact($record, $email, $company->id, $zohoId);
                    $synced++;
                } catch (\Throwable $e) {
                    $skipped++;
                    Log::warning("[ZohoCrmSyncService] syncContacts: skipped contact {$zohoId} — " . $e->getMessage());
                }
            }

            $page++;
        } while ($morePages && $page <= self::MAX_PAGES);

        return ['records' => $synced, 'skipped' => $skipped, 'status' => 'success'];
    }

    /**
     * Orchestrate a full sync: Accounts then Contacts (order matters — contacts link to companies).
     *
     * Writes a ZohoSyncLog row and updates ZohoSyncCheckpoint for each module.
     * A per-module try/catch logs partial errors without aborting the other module.
     *
     * @param  string|null  $module  'Accounts'|'Contacts'|null (null = both)
     */
    public function sync(?string $module = null): void
    {
        $modules = match (true) {
            $module === 'Accounts' => ['Accounts'],
            $module === 'Contacts' => ['Contacts'],
            default                => ['Accounts', 'Contacts'],
        };

        foreach ($modules as $mod) {
            $startedAt = microtime(true);
            $status    = 'success';
            $error     = null;
            $records   = 0;

            try {
                $result  = match ($mod) {
                    'Accounts' => $this->syncAccounts(),
                    'Contacts' => $this->syncContacts(),
                };
                $records = $result['records'];

                Log::info("[ZohoCrmSyncService] sync({$mod}): {$records} records upserted, {$result['skipped']} skipped");
            } catch (\Throwable $e) {
                $status = 'error';
                $error  = $e->getMessage();
                Log::error("[ZohoCrmSyncService] sync({$mod}) failed: {$error}");
            }

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            // ── Write sync log ────────────────────────────────────────────────
            ZohoSyncLog::create([
                'module'         => $mod,
                'synced_at'      => now(),
                'records_synced' => $records,
                'status'         => $status,
                'error'          => $error,
                'duration_ms'    => $durationMs,
            ]);

            // ── Update checkpoint (best-effort) ───────────────────────────────
            try {
                ZohoSyncCheckpoint::updateOrCreate(
                    ['module' => $mod],
                    [
                        'status'               => $status,
                        'cursor_modified_time' => now(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("[ZohoCrmSyncService] Failed to update checkpoint for {$mod}: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert a single Zoho Account record into the companies table.
     *
     * Match order (idempotency keys):
     *   1. companies.zoho_account_id == $zohoId
     *   2. companies.domain == $normalizedDomain  (if non-null)
     *   3. Create new
     *
     * When a previously-discovered company is matched by domain (key 2),
     * its zoho_account_id and relationship are also updated (merge rule).
     */
    private function upsertCompany(array $record, string $zohoId): Company
    {
        $normalizedDomain = $this->normalizeDomain($record['Website'] ?? null);
        $country          = $this->mapCountryToIso2($record['Billing_Country'] ?? null);

        // ── Try zoho_account_id first ─────────────────────────────────────────
        $company = Company::query()->where('zoho_account_id', $zohoId)->first();

        // ── Then domain ───────────────────────────────────────────────────────
        if ($company === null && $normalizedDomain !== null) {
            $company = Company::query()->where('domain', $normalizedDomain)->first();
            // Merge rule: flip relationship + set zoho_account_id
            if ($company !== null) {
                $company->zoho_account_id = $zohoId;
                $company->relationship    = 'client';
            }
        }

        // ── Otherwise create ──────────────────────────────────────────────────
        if ($company === null) {
            $company = new Company();
        }

        $company->name            = (string) ($record['Account_Name'] ?? '');
        $company->domain          = $normalizedDomain;
        $company->sector          = $record['Industry'] ?? null;
        $company->country         = $country;
        $company->phone           = $record['Phone'] ?? null;
        $company->relationship    = 'client';
        $company->source          = 'zoho';
        $company->zoho_account_id = $zohoId;
        $company->save();

        return $company;
    }

    /**
     * Upsert a single Zoho Contact into the contacts table.
     * Idempotency key: email (globally unique).
     */
    private function upsertContact(array $record, string $email, int $companyId, string $zohoId): Contact
    {
        $contact = Contact::withTrashed()->where('email', $email)->first();

        if ($contact === null) {
            $contact = new Contact();
            $contact->status = 'new';
        }

        $contact->email           = $email;
        $contact->name            = (string) ($record['Full_Name'] ?? $email);
        $contact->position        = $record['Title'] ?? null;
        $contact->phone           = $record['Phone'] ?? null;
        $contact->company_id      = $companyId;
        $contact->source          = 'zoho';
        $contact->legal_basis     = 'relationship';
        $contact->email_kind      = 'role';
        $contact->zoho_contact_id = $zohoId;

        // Restore soft-deleted contacts that reappear in Zoho
        if ($contact->trashed()) {
            $contact->restore();
        }

        $contact->save();

        return $contact;
    }

    /**
     * Resolve an existing company by zoho_account_id, or create a minimal stub.
     * Returns null only when $accId is blank AND $accName is blank (no account at all).
     */
    private function resolveOrCreateCompany(string $accId, string $accName): ?Company
    {
        // No account information — cannot satisfy company_id NOT NULL.
        if ($accId === '' && $accName === '') {
            return null;
        }

        // Prefer lookup by zoho_account_id (fastest, most precise)
        if ($accId !== '') {
            $company = Company::query()->where('zoho_account_id', $accId)->first();
            if ($company !== null) {
                return $company;
            }
        }

        // Create a minimal stub so the contact can be persisted.
        // This company will be fully enriched when syncAccounts() runs next.
        $company = Company::create([
            'name'            => $accName ?: '(inconnu)',
            'zoho_account_id' => $accId ?: null,
            'relationship'    => 'client',
            'source'          => 'zoho',
        ]);

        return $company;
    }

    /**
     * Normalize a Website URL to a plain lowercase hostname (no scheme, no www, no path).
     *
     * Examples:
     *   https://www.example.com/page  → example.com
     *   http://subdomain.example.fr   → subdomain.example.fr
     *   null | ""                     → null
     */
    private function normalizeDomain(?string $website): ?string
    {
        if ($website === null || trim($website) === '') {
            return null;
        }

        $url = trim($website);

        // Prepend scheme if absent so parse_url can work
        if (! str_contains($url, '://')) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false || $host === '') {
            return null;
        }

        $host = strtolower($host);

        // Strip leading www.
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host ?: null;
    }

    /**
     * Map a Billing_Country string to an ISO-3166-1 alpha-2 code.
     *
     * Strategy:
     *   1. Null / empty → null.
     *   2. Already 2 chars (e.g. "FR") → return uppercased.
     *   3. Lookup in COUNTRY_MAP (case-insensitive).
     *   4. Unmapped strings longer than 2 chars → null (prefer null over a bad code).
     */
    private function mapCountryToIso2(?string $billingCountry): ?string
    {
        if ($billingCountry === null || trim($billingCountry) === '') {
            return null;
        }

        $trimmed = trim($billingCountry);

        if (strlen($trimmed) === 2) {
            return strtoupper($trimmed);
        }

        $key = strtolower($trimmed);

        return self::COUNTRY_MAP[$key] ?? null;
    }
}
