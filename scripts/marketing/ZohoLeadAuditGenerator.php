<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ZohoLeadAuditGenerator
{
    private CarbonImmutable $asOf;

    /** @param array<string,array<string,mixed>> $liveRecords */
    public function __construct(
        private readonly array $liveRecords,
        private readonly array $liveMetadata,
        private readonly bool $liveMode,
    ) {
        $this->asOf = CarbonImmutable::now('Africa/Casablanca');
    }

    /** @return array{detailed:string,summary:string,dataset:array<string,mixed>} */
    public function generate(): array
    {
        $universe = $this->loadUniverse();
        $leads = $this->buildLeads($universe);
        $categories = $this->categoryDefinitions();
        $categoryCounts = array_fill_keys(array_keys($categories), 0);
        foreach ($leads as $lead) {
            $categoryCounts[$lead['category']]++;
        }

        uasort($categoryCounts, static fn (int $a, int $b): int => $b <=> $a);
        $topTargets = collect($leads)
            ->filter(fn (array $lead): bool => in_array($lead['category'], [
                'human_follow_up', 'converted_active', 'warm_engaged', 'warm_prospect', 'converted_reactivation',
            ], true))
            ->sortByDesc(fn (array $lead): int => $lead['priority_score'])
            ->take(50)
            ->values()
            ->map(fn (array $lead): array => array_intersect_key($lead, array_flip([
                'zoho_id', 'name', 'company', 'email', 'status', 'converted', 'category', 'category_label',
                'priority_score', 'recommended_when', 'recommended_date', 'why', 'confidence', 'owner',
            ])))
            ->all();

        $timelineTotal = array_sum(array_map(fn (array $lead): int => count($lead['timeline']), $leads));
        $sourceSummary = $this->sourceSummary($leads, $universe, $timelineTotal);
        $strategies = $this->strategyDefinitions($categories, $categoryCounts);
        $additional = $this->additionalZohoData();

        $dataset = [
            'meta' => [
                'title' => 'Zoho lead-by-lead marketing audit',
                'generated_at' => $this->asOf->toIso8601String(),
                'report_date' => $this->asOf->toDateString(),
                'timezone' => 'Africa/Casablanca',
                'live_mode' => $this->liveMode,
                'lead_count' => count($leads),
                'timeline_event_count' => $timelineTotal,
                'local_mirror_count' => count($universe['localLeads']),
                'live_count' => count($this->liveRecords),
                'local_mirror_synced_at' => $universe['localMirrorSyncedAt'],
                'pii_notice' => 'Internal report. Contains business contact data and CRM history; do not publish or forward externally.',
            ],
            'categories' => array_map(function (array $category, string $key) use ($categoryCounts): array {
                return ['key' => $key, ...$category, 'count' => $categoryCounts[$key] ?? 0];
            }, $categories, array_keys($categories)),
            'category_counts' => $categoryCounts,
            'strategies' => $strategies,
            'source_summary' => $sourceSummary,
            'additional_zoho_data' => $additional,
            'status_picklist' => $this->liveMetadata['lead_status_picklist'] ?? [],
            'top_targets' => $topTargets,
            'leads' => array_values($leads),
        ];

        return [
            'detailed' => $this->renderDetailed($dataset),
            'summary' => $this->renderSummary($dataset),
            'dataset' => $dataset,
        ];
    }

    private function loadUniverse(): array
    {
        $localLeads = [];
        $localMirrorSyncedAt = null;
        foreach (DB::table('zoho_leads')->whereNull('zoho_deleted_at')->orderBy('id')->get() as $row) {
            $array = (array) $row;
            $array['raw_payload'] = $this->decodeJson($array['raw_payload'] ?? null);
            $localLeads[(string) $row->zoho_id] = $array;
            $synced = $this->date($row->last_synced_at ?? null);
            if ($synced !== null && ($localMirrorSyncedAt === null || $synced->greaterThan($localMirrorSyncedAt))) {
                $localMirrorSyncedAt = $synced;
            }
        }

        $owners = [];
        if (Schema::hasTable('zoho_users')) {
            foreach (DB::table('zoho_users')->whereNull('zoho_deleted_at')->get(['zoho_id', 'full_name', 'email']) as $row) {
                $owners[(string) $row->zoho_id] = $this->text($row->full_name) ?? $this->text($row->email) ?? 'Unknown owner';
            }
        }

        $accounts = $this->rowsByZohoId('zoho_accounts', [
            'zoho_id', 'name', 'account_type', 'active_status', 'account_status', 'industry', 'country', 'city',
            'website', 'phone', 'transport_type', 'volume', 'origin_destination', 'observation', 'last_activity_at',
        ]);
        $zohoContacts = $this->rowsByZohoId('zoho_contacts', [
            'zoho_id', 'account_zoho_id', 'full_name', 'email', 'normalized_email', 'title', 'company_name', 'country',
            'city', 'lead_source', 'email_opt_out', 'email_opened', 'link_clicked', 'unsubscribed_at', 'last_activity_at',
        ]);

        [$activities, $activityIndex] = $this->loadActivities();
        [$actions, $actionIndex] = $this->loadCommercialActions();
        [$deals, $dealsByAccount, $dealsByContact] = $this->loadDeals();
        [$quotes, $quotesByDeal, $quotesByAccount, $quotesByContact] = $this->loadQuotes();
        $dealHistory = $this->loadHistory('zoho_deal_stage_history', 'deal_zoho_id', [
            'zoho_id', 'deal_zoho_id', 'stage', 'previous_stage', 'moved_to_stage', 'occurred_at', 'amount',
            'probability', 'expected_revenue', 'currency_code', 'closing_date',
        ]);
        $quoteHistory = $this->loadHistory('zoho_quote_status_history', 'quote_zoho_id', [
            'zoho_id', 'quote_zoho_id', 'status', 'previous_status', 'occurred_at',
        ]);
        [$localContacts, $localContactsByEmail] = $this->loadLocalContacts();
        $campaignRecipients = $this->loadCampaignRecipients();
        $sequenceSends = $this->loadSequenceSends();
        $inbox = $this->loadInbox();
        $demandes = $this->loadDemandes();
        $suppressions = $this->loadSuppressions();

        return compact(
            'localLeads', 'localMirrorSyncedAt', 'owners', 'accounts', 'zohoContacts', 'activities', 'activityIndex',
            'actions', 'actionIndex', 'deals', 'dealsByAccount', 'dealsByContact', 'quotes', 'quotesByDeal',
            'quotesByAccount', 'quotesByContact', 'dealHistory', 'quoteHistory', 'localContacts',
            'localContactsByEmail', 'campaignRecipients', 'sequenceSends', 'inbox', 'demandes', 'suppressions',
        );
    }

    /** @return array{0:array<string,array<string,mixed>>,1:array<string,list<string>>} */
    private function loadActivities(): array
    {
        $entities = [];
        $index = [];
        foreach (DB::table('zoho_activities')->whereNull('zoho_deleted_at')->orderBy('id')->get() as $row) {
            $raw = $this->decodeJson($row->raw_payload ?? null);
            $id = 'activity:'.(string) $row->activity_type.':'.(string) $row->zoho_id;
            $detail = $this->firstText($raw, [
                'Note_Content', 'Description', 'Call_Agenda', 'Call_Purpose', 'Call_Result', 'Venue', 'Location',
            ]);
            $entities[$id] = [
                'id' => $id,
                'kind' => (string) $row->activity_type,
                'zoho_id' => (string) $row->zoho_id,
                'parent_id' => $this->text($row->parent_zoho_id),
                'contact_id' => $this->text($row->contact_zoho_id),
                'date' => $this->dateString($row->activity_at ?? $row->start_at ?? $row->zoho_created_at ?? null),
                'due' => $this->dateString($row->due_at ?? null),
                'title' => $this->text($row->subject) ?? ucfirst((string) $row->activity_type),
                'status' => $this->text($row->status),
                'detail' => $this->truncate($detail, 500),
            ];
            $this->index($index, $row->parent_zoho_id ?? null, $id);
            $this->index($index, $row->contact_zoho_id ?? null, $id);
        }

        return [$entities, $index];
    }

    /** @return array{0:array<string,array<string,mixed>>,1:array<string,list<string>>} */
    private function loadCommercialActions(): array
    {
        $entities = [];
        $index = [];
        foreach (DB::table('zoho_actions_commercials')->whereNull('zoho_deleted_at')->orderBy('id')->get() as $row) {
            $id = 'action:'.(string) $row->zoho_id;
            $entities[$id] = [
                'id' => $id,
                'kind' => 'commercial_action',
                'zoho_id' => (string) $row->zoho_id,
                'parent_id' => $this->text($row->parent_zoho_id),
                'date' => $this->dateString($row->action_at ?? $row->zoho_created_at ?? null),
                'due' => $this->dateString($row->due_at ?? null),
                'title' => $this->text($row->name) ?? 'Commercial action',
                'status' => $this->text($row->status),
                'detail' => $this->truncate($this->text($row->comment), 500),
                'priority' => $this->text($row->priority),
            ];
            $this->index($index, $row->parent_zoho_id ?? null, $id);
        }

        return [$entities, $index];
    }

    /** @return array{0:array<string,array<string,mixed>>,1:array<string,list<string>>,2:array<string,list<string>>} */
    private function loadDeals(): array
    {
        $entities = [];
        $byAccount = [];
        $byContact = [];
        foreach (DB::table('zoho_deals')->whereNull('zoho_deleted_at')->orderBy('id')->get() as $row) {
            $id = (string) $row->zoho_id;
            $entities[$id] = [
                'id' => $id,
                'name' => $this->text($row->name) ?? 'Deal',
                'stage' => $this->text($row->stage),
                'account_id' => $this->text($row->account_zoho_id),
                'contact_id' => $this->text($row->contact_zoho_id),
                'date' => $this->dateString($row->stage_modified_at ?? $row->last_activity_at ?? $row->zoho_created_at ?? null),
                'closing_date' => $this->dateString($row->closing_date ?? null),
                'amount' => $row->amount !== null ? (float) $row->amount : null,
                'currency' => $this->text($row->currency_code),
                'origin' => $this->text($row->origin),
                'destination' => $this->text($row->destination),
                'transport' => $this->text($row->transport_type),
                'cargo' => $this->truncate($this->text($row->cargo_description), 250),
            ];
            $this->index($byAccount, $row->account_zoho_id ?? null, $id);
            $this->index($byContact, $row->contact_zoho_id ?? null, $id);
        }

        return [$entities, $byAccount, $byContact];
    }

    /** @return array{0:array<string,array<string,mixed>>,1:array<string,list<string>>,2:array<string,list<string>>,3:array<string,list<string>>} */
    private function loadQuotes(): array
    {
        $entities = [];
        $byDeal = [];
        $byAccount = [];
        $byContact = [];
        foreach (DB::table('zoho_quotes')->whereNull('zoho_deleted_at')->orderBy('id')->get() as $row) {
            $id = (string) $row->zoho_id;
            $transport = $this->decodeJson($row->transport_type ?? null);
            $entities[$id] = [
                'id' => $id,
                'number' => $this->text($row->quote_number),
                'subject' => $this->text($row->subject) ?? 'Quote',
                'status' => $this->text($row->status),
                'follow_up_status' => $this->text($row->follow_up_status),
                'deal_id' => $this->text($row->deal_zoho_id),
                'account_id' => $this->text($row->account_zoho_id),
                'contact_id' => $this->text($row->contact_zoho_id),
                'date' => $this->dateString($row->quote_date ?? $row->zoho_created_at ?? null),
                'last_activity_at' => $this->dateString($row->last_activity_at ?? null),
                'valid_till' => $this->dateString($row->valid_till ?? null),
                'total' => $row->line_items_total !== null ? (float) $row->line_items_total : null,
                'total_complete' => (bool) $row->line_items_total_complete,
                'currency' => $this->text($row->currency_code),
                'origin' => $this->text($row->origin),
                'destination' => $this->text($row->destination),
                'transport' => $transport !== [] ? $transport : $this->text($row->transport_type),
                'dangerous_goods' => $this->text($row->dangerous_goods_status),
            ];
            $this->index($byDeal, $row->deal_zoho_id ?? null, $id);
            $this->index($byAccount, $row->account_zoho_id ?? null, $id);
            $this->index($byContact, $row->contact_zoho_id ?? null, $id);
        }

        return [$entities, $byDeal, $byAccount, $byContact];
    }

    private function loadHistory(string $table, string $groupColumn, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        $grouped = [];
        foreach (DB::table($table)->whereNull('zoho_deleted_at')->orderBy('id')->get($columns) as $row) {
            $group = $this->text($row->{$groupColumn} ?? null);
            if ($group === null) {
                continue;
            }
            $grouped[$group][] = (array) $row;
        }

        return $grouped;
    }

    /** @return array{0:array<int,array<string,mixed>>,1:array<string,list<int>>} */
    private function loadLocalContacts(): array
    {
        $contacts = [];
        $byEmail = [];
        foreach (DB::table('contacts')->orderBy('id')->get() as $row) {
            $id = (int) $row->id;
            $contacts[$id] = (array) $row;
            $email = $this->normalizeEmail($row->email ?? null);
            if ($email !== null) {
                $byEmail[$email][] = $id;
            }
        }

        return [$contacts, $byEmail];
    }

    private function loadCampaignRecipients(): array
    {
        if (! Schema::hasTable('campaign_recipients')) {
            return [];
        }
        $grouped = [];
        $rows = DB::table('campaign_recipients as recipient')
            ->leftJoin('campaign_runs as run', 'run.id', '=', 'recipient.campaign_run_id')
            ->leftJoin('campaigns as campaign', 'campaign.id', '=', 'run.campaign_id')
            ->select([
                'recipient.id', 'recipient.contact_id', 'recipient.status', 'recipient.skip_reason',
                'recipient.bounce_reason', 'recipient.bounce_type', 'recipient.sent_at', 'recipient.opened_at',
                'recipient.clicked_at', 'recipient.bounced_at', 'recipient.replied_at', 'recipient.created_at',
                'run.id as run_id', 'run.status as run_status', 'campaign.id as campaign_id',
                'campaign.name as campaign_name', 'campaign.subject as campaign_subject',
            ])->orderBy('recipient.id')->get();
        foreach ($rows as $row) {
            $grouped[(int) $row->contact_id][] = (array) $row;
        }

        return $grouped;
    }

    private function loadSequenceSends(): array
    {
        if (! Schema::hasTable('sequence_step_sends') || ! Schema::hasTable('sequence_enrollments')) {
            return [];
        }
        $grouped = [];
        $rows = DB::table('sequence_step_sends as send')
            ->join('sequence_enrollments as enrollment', 'enrollment.id', '=', 'send.enrollment_id')
            ->leftJoin('sequences as sequence', 'sequence.id', '=', 'enrollment.sequence_id')
            ->leftJoin('campaigns as campaign', 'campaign.id', '=', 'enrollment.campaign_id')
            ->select([
                'send.id', 'send.status', 'send.step_no', 'send.sent_at', 'send.opened_at', 'send.bounced_at',
                'send.bounce_type', 'send.bounce_reason', 'send.created_at', 'enrollment.contact_id',
                'enrollment.status as enrollment_status', 'enrollment.stopped_reason', 'sequence.name as sequence_name',
                'campaign.name as campaign_name',
            ])->orderBy('send.id')->get();
        foreach ($rows as $row) {
            $grouped[(int) $row->contact_id][] = (array) $row;
        }

        return $grouped;
    }

    private function loadInbox(): array
    {
        if (! Schema::hasTable('inbox_emails')) {
            return ['by_contact' => [], 'by_email' => []];
        }
        $byContact = [];
        $byEmail = [];
        foreach (DB::table('inbox_emails')->orderBy('id')->get() as $row) {
            $record = (array) $row;
            if ($row->contact_id !== null) {
                $byContact[(int) $row->contact_id][] = $record;
            }
            $email = $this->normalizeEmail($row->from_email ?? null);
            if ($email !== null) {
                $byEmail[$email][] = $record;
            }
        }

        return ['by_contact' => $byContact, 'by_email' => $byEmail];
    }

    private function loadDemandes(): array
    {
        if (! Schema::hasTable('demandes')) {
            return [];
        }
        $grouped = [];
        foreach (DB::table('demandes')->orderBy('id')->get() as $row) {
            if ($row->contact_id !== null) {
                $grouped[(int) $row->contact_id][] = (array) $row;
            }
        }

        return $grouped;
    }

    private function loadSuppressions(): array
    {
        if (! Schema::hasTable('suppressions')) {
            return ['by_email' => [], 'by_contact' => []];
        }
        $byEmail = [];
        $byContact = [];
        foreach (DB::table('suppressions')->orderBy('id')->get() as $row) {
            $record = (array) $row;
            $email = $this->normalizeEmail($row->email ?? null);
            if ($email !== null) {
                $byEmail[$email][] = $record;
            }
            if ($row->contact_id !== null) {
                $byContact[(int) $row->contact_id][] = $record;
            }
        }

        return ['by_email' => $byEmail, 'by_contact' => $byContact];
    }

    private function rowsByZohoId(string $table, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        $rows = [];
        foreach (DB::table($table)->whereNull('zoho_deleted_at')->get($columns) as $row) {
            $rows[(string) $row->zoho_id] = (array) $row;
        }

        return $rows;
    }

    private function index(array &$index, mixed $rawKey, string $value): void
    {
        $key = $this->text($rawKey);
        if ($key !== null) {
            $index[$key][] = $value;
        }
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function text(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach (['name', 'display_value', 'actual_value', 'id'] as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    return $this->text($value[$key]);
                }
            }
            return null;
        }
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function firstText(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->text($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function truncate(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? $value);

        return mb_strlen($clean) <= $limit ? $clean : mb_substr($clean, 0, $limit - 1).'…';
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = $this->text($value);
        if ($email === null) {
            return null;
        }
        $email = mb_strtolower($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        $raw = $this->text($value);
        if ($raw === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw)->setTimezone('Africa/Casablanca');
        } catch (Throwable) {
            return null;
        }
    }

    private function dateString(mixed $value): ?string
    {
        return $this->date($value)?->toIso8601String();
    }

    /** @return array<string,array<string,mixed>> */
    private function buildLeads(array $u): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (int|string $id): string => (string) $id,
            array_merge(array_keys($u['localLeads']), array_keys($this->liveRecords)),
        )));
        sort($ids, SORT_STRING);

        $emailCounts = [];
        foreach ($ids as $id) {
            $local = $u['localLeads'][$id] ?? [];
            $payload = array_replace($local['raw_payload'] ?? [], $this->liveRecords[$id] ?? []);
            $email = $this->normalizeEmail($payload['Email'] ?? $local['email'] ?? null);
            if ($email !== null) {
                $emailCounts[$email] = ($emailCounts[$email] ?? 0) + 1;
            }
        }

        $leads = [];
        foreach ($ids as $id) {
            $local = $u['localLeads'][$id] ?? [];
            $payload = array_replace($local['raw_payload'] ?? [], $this->liveRecords[$id] ?? []);
            $lead = $this->buildLead($id, $local, $payload, $u, $emailCounts);
            $leads[$id] = $lead;
        }

        uasort($leads, static function (array $a, array $b): int {
            return [$b['priority_score'], $a['company'], $a['name'], $a['zoho_id']]
                <=> [$a['priority_score'], $b['company'], $b['name'], $b['zoho_id']];
        });

        return $leads;
    }

    private function buildLead(string $id, array $local, array $payload, array $u, array $emailCounts): array
    {
        $name = $this->text($payload['Full_Name'] ?? null)
            ?? trim(implode(' ', array_filter([
                $this->text($payload['First_Name'] ?? $local['first_name'] ?? null),
                $this->text($payload['Last_Name'] ?? $local['last_name'] ?? null),
            ])))
            ?: 'Unnamed lead';
        $company = $this->text($payload['Company'] ?? $local['company_name'] ?? null) ?? 'Unknown company';
        $rawEmail = $this->text($payload['Email'] ?? $local['email'] ?? null);
        $email = $this->normalizeEmail($rawEmail);
        $emailValid = $email !== null;
        $emailDomain = $email !== null ? substr(strrchr($email, '@') ?: '', 1) : null;
        $consumerDomains = [
            'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.fr', 'hotmail.com', 'hotmail.fr', 'outlook.com',
            'live.com', 'live.fr', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com', 'orange.fr', 'wanadoo.fr',
        ];
        $consumerEmail = $emailDomain !== null && in_array($emailDomain, $consumerDomains, true);

        $status = $this->text($payload['Lead_Status'] ?? $local['status'] ?? null);
        $converted = ($payload['Converted__s'] ?? $local['is_converted'] ?? false) === true
            || (int) ($payload['Converted__s'] ?? $local['is_converted'] ?? 0) === 1;
        $accountId = $this->lookupId($payload['Converted_Account'] ?? $local['account_zoho_id'] ?? null);
        $contactId = $this->lookupId($payload['Converted_Contact'] ?? $local['contact_zoho_id'] ?? null);
        $dealId = $this->lookupId($payload['Converted_Deal'] ?? $local['converted_deal_zoho_id'] ?? null);
        $ownerId = $this->lookupId($payload['Owner'] ?? $local['owner_zoho_id'] ?? null);
        $owner = $ownerId !== null ? ($u['owners'][$ownerId] ?? 'Unknown owner') : 'Unassigned / unknown';
        $industry = $this->text($payload['Secteur_Activit'] ?? $local['industry'] ?? null);
        $country = $this->text($payload['Country'] ?? $local['country'] ?? null);
        $title = $this->text($payload['Intitul_de_Poste'] ?? $local['title'] ?? null);
        $createdAt = $this->date($payload['Created_Time'] ?? $local['zoho_created_at'] ?? null);
        $convertedAt = $this->date($payload['Converted_Date_Time'] ?? $local['converted_at'] ?? null);
        $lastActivity = $this->date($payload['Last_Activity_Time'] ?? $local['last_activity_at'] ?? null);
        $modifiedAt = $this->date($payload['Modified_Time'] ?? $local['zoho_modified_at'] ?? null);
        $optedOut = ($payload['Email_Opt_Out'] ?? $local['email_opt_out'] ?? false) === true
            || (int) ($payload['Email_Opt_Out'] ?? $local['email_opt_out'] ?? 0) === 1
            || $this->text($payload['Unsubscribed_Time'] ?? $local['unsubscribed_at'] ?? null) !== null;

        $relatedKeys = array_filter([
            $id => 'lead direct',
            $contactId => 'converted contact',
            $accountId => 'converted account context',
            $dealId => 'converted deal',
        ], fn ($value, $key): bool => (string) $key !== '', ARRAY_FILTER_USE_BOTH);

        [$timeline, $signals] = $this->timelineForLead($id, $relatedKeys, $accountId, $contactId, $dealId, $createdAt, $convertedAt, $u);

        $localContactIds = $email !== null ? array_values(array_unique($u['localContactsByEmail'][$email] ?? [])) : [];
        $localContacts = array_values(array_filter(array_map(fn (int $contact): ?array => $u['localContacts'][$contact] ?? null, $localContactIds)));
        $localSignals = $this->localMarketingSignals($email, $localContactIds, $localContacts, $u);
        $timeline = array_merge($timeline, $localSignals['timeline']);
        usort($timeline, fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        $latestEvidenceAt = $this->latestDate(array_filter([
            $lastActivity,
            $signals['latest_event_at'],
            $signals['latest_quote_at'],
            $signals['latest_deal_at'],
            $localSignals['latest_event_at'],
        ]));
        $recencyDays = $latestEvidenceAt !== null
            ? (int) floor($latestEvidenceAt->diffInDays($this->asOf, false))
            : null;
        $duplicateCount = $email !== null ? ($emailCounts[$email] ?? 0) : 0;
        $invalidVerification = in_array($localSignals['verification_status'], ['invalid', 'undeliverable', 'disposable'], true);
        $suppression = $optedOut || $localSignals['suppressed'] || $localSignals['hard_bounce'] || $invalidVerification;
        $riskyVerification = in_array($localSignals['verification_status'], ['risky', 'accept_all', 'unknown'], true);
        $statusConflict = $status === 'Qualifié';
        $disqualified = in_array($status, ['Non qualifié', 'FAIBLE', 'Prospect perdu'], true);
        $fitSignals = array_filter([
            'company' => $company !== 'Unknown company',
            'industry' => $industry !== null,
            'business_email' => $emailValid && ! $consumerEmail,
            'role' => $title !== null,
            'geography' => $country !== null,
            'trade_context' => $this->firstText($payload, ['Type_de_transport_utilis', 'Incoterm', 'Destination', 'Provenance_Destination', 'Volume']) !== null,
            'official_identifier' => $this->firstText($payload, ['ICE', 'R_C', 'I_F', 'CNSS', 'Patente', 'Num_ro_Comptable']) !== null,
        ]);

        $classification = $this->classifyLead([
            'converted' => $converted,
            'suppressed' => $suppression,
            'suppression_reason' => $this->suppressionReason($optedOut, $localSignals, $invalidVerification),
            'email_valid' => $emailValid,
            'consumer_email' => $consumerEmail,
            'duplicate_count' => $duplicateCount,
            'status_conflict' => $statusConflict,
            'disqualified' => $disqualified,
            'status' => $status,
            'recency_days' => $recencyDays,
            'has_reply' => $localSignals['has_reply'],
            'has_demande' => $localSignals['has_demande'],
            'campaign_engaged' => $localSignals['campaign_engaged'],
            'open_task_due' => $signals['open_task_due'],
            'latest_quote_at' => $signals['latest_quote_at'],
            'has_deal' => $signals['deal_count'] > 0,
            'has_quote' => $signals['quote_count'] > 0,
            'fit_count' => count($fitSignals),
            'risky_verification' => $riskyVerification,
        ]);

        $score = $this->priorityScore([
            ...$classification,
            'converted' => $converted,
            'status' => $status,
            'recency_days' => $recencyDays,
            'has_reply' => $localSignals['has_reply'],
            'has_demande' => $localSignals['has_demande'],
            'campaign_engaged' => $localSignals['campaign_engaged'],
            'campaign_clicked' => $localSignals['campaign_clicked'],
            'open_task_due' => $signals['open_task_due'],
            'latest_quote_at' => $signals['latest_quote_at'],
            'fit_count' => count($fitSignals),
            'email_valid' => $emailValid,
            'consumer_email' => $consumerEmail,
            'duplicate_count' => $duplicateCount,
        ]);

        $why = $this->whyList(
            $classification,
            $status,
            $converted,
            $recencyDays,
            $signals,
            $localSignals,
            $fitSignals,
            $duplicateCount,
            $consumerEmail,
        );
        $confidence = $this->confidence(
            isset($this->liveRecords[$id]),
            $signals['direct_activity_count'],
            $signals['context_activity_count'],
            count($localContactIds),
            $duplicateCount,
        );
        $category = $classification['category'];
        $categoryDefinition = $this->categoryDefinitions()[$category];

        return [
            'zoho_id' => $id,
            'name' => $name,
            'company' => $company,
            'email' => $rawEmail,
            'normalized_email' => $email,
            'phone' => $this->text($payload['Phone'] ?? $local['phone'] ?? null),
            'mobile' => $this->text($payload['Mobile'] ?? $local['mobile'] ?? null),
            'secondary_phone' => $this->text($payload['T_l_phone_2'] ?? $local['secondary_phone'] ?? null),
            'title' => $title,
            'owner' => $owner,
            'owner_zoho_id' => $ownerId,
            'status' => $status,
            'status_integrity_note' => $statusConflict
                ? 'Display label “Qualifié” maps to Zoho actual value “Junk Lead”; owner review required.'
                : null,
            'converted' => $converted,
            'converted_at' => $convertedAt?->toIso8601String(),
            'account_zoho_id' => $accountId,
            'contact_zoho_id' => $contactId,
            'deal_zoho_id' => $dealId,
            'account' => $accountId !== null ? ($u['accounts'][$accountId] ?? null) : null,
            'converted_contact' => $contactId !== null ? ($u['zohoContacts'][$contactId] ?? null) : null,
            'industry' => $industry,
            'country' => $country,
            'city' => $this->text($payload['City'] ?? $local['city'] ?? null),
            'language' => $this->text($payload['Langue'] ?? $local['language'] ?? null),
            'client_type' => $this->text($payload['Type_de_client'] ?? $local['client_type'] ?? null),
            'transport_type' => $this->text($payload['Type_de_transport_utilis'] ?? $local['transport_type'] ?? null),
            'incoterm' => $this->text($payload['Incoterm'] ?? $local['incoterm'] ?? null),
            'destination' => $this->text($payload['Destination'] ?? $local['destination'] ?? null),
            'origin_destination' => $this->text($payload['Provenance_Destination'] ?? $local['origin_destination'] ?? null),
            'volume' => $this->text($payload['Volume'] ?? $local['volume'] ?? null),
            'competitor' => $this->text($payload['Prestataire_Concurrent'] ?? $local['competitor'] ?? null),
            'observation' => $this->truncate($this->text($payload['Observation'] ?? $local['observation'] ?? null), 750),
            'tags' => $this->stringList($payload['Tag'] ?? $local['tags'] ?? null),
            'created_at' => $createdAt?->toIso8601String(),
            'modified_at' => $modifiedAt?->toIso8601String(),
            'last_activity_at' => $lastActivity?->toIso8601String(),
            'latest_evidence_at' => $latestEvidenceAt?->toIso8601String(),
            'recency_days' => $recencyDays,
            'email_valid' => $emailValid,
            'consumer_email_domain' => $consumerEmail,
            'email_opt_out' => $optedOut,
            'suppressed' => $suppression,
            'suppression_reason' => $classification['suppression_reason'] ?? null,
            'duplicate_email_count' => $duplicateCount,
            'local_contact_ids' => $localContactIds,
            'local_contact_statuses' => array_values(array_filter(array_map(fn (array $contact): ?string => $this->text($contact['status'] ?? null), $localContacts))),
            'email_verification_status' => $localSignals['verification_status'],
            'legal_basis' => $localSignals['legal_basis'],
            'source_url_available' => $localSignals['source_url_available'],
            'activity_count' => $signals['activity_count'],
            'direct_activity_count' => $signals['direct_activity_count'],
            'context_activity_count' => $signals['context_activity_count'],
            'deal_count' => $signals['deal_count'],
            'quote_count' => $signals['quote_count'],
            'campaign_touch_count' => $localSignals['campaign_touch_count'],
            'has_reply' => $localSignals['has_reply'],
            'has_demande' => $localSignals['has_demande'],
            'campaign_engaged' => $localSignals['campaign_engaged'],
            'open_task_due' => $signals['open_task_due']?->toIso8601String(),
            'fit_signals' => array_keys($fitSignals),
            'category' => $category,
            'category_label' => $categoryDefinition['label'],
            'category_color' => $categoryDefinition['color'],
            'priority_score' => $score,
            'recommended_when' => $classification['recommended_when'],
            'recommended_date' => $classification['recommended_date'],
            'recommended_channel' => $classification['recommended_channel'],
            'why' => $why,
            'confidence' => $confidence,
            'timeline' => $timeline,
            'provenance' => [
                'live_lead_record' => isset($this->liveRecords[$id]),
                'local_mirror_record' => isset($u['localLeads'][$id]),
                'local_history_as_of' => $u['localMirrorSyncedAt']?->toIso8601String(),
                'relationship_rule' => 'Direct IDs and exact normalized email only; account-level items are labeled context.',
            ],
        ];
    }

    private function lookupId(mixed $value): ?string
    {
        if (is_array($value)) {
            return $this->text($value['id'] ?? null);
        }

        return $this->text($value);
    }

    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = $this->decodeJson($value);
            if ($decoded !== []) {
                $value = $decoded;
            } else {
                return [$value];
            }
        }
        if (! is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $text = $this->text($item);
            if ($text !== null) {
                $result[] = $text;
            }
        }

        return array_values(array_unique($result));
    }

    /** @return array{0:list<array<string,mixed>>,1:array<string,mixed>} */
    private function timelineForLead(
        string $leadId,
        array $relatedKeys,
        ?string $accountId,
        ?string $contactId,
        ?string $convertedDealId,
        ?CarbonImmutable $createdAt,
        ?CarbonImmutable $convertedAt,
        array $u,
    ): array {
        $timeline = [];
        $seen = [];
        $activityIds = [];
        $directScopes = ['lead direct', 'converted contact', 'converted deal'];
        foreach ($relatedKeys as $key => $scope) {
            foreach ($u['activityIndex'][$key] ?? [] as $activityId) {
                $currentScope = $activityIds[$activityId] ?? null;
                if ($currentScope === null
                    || (! in_array($currentScope, $directScopes, true) && in_array($scope, $directScopes, true))) {
                    $activityIds[$activityId] = $scope;
                }
            }
        }

        $directActivityCount = 0;
        $contextActivityCount = 0;
        $latestEvent = null;
        $openTaskDue = null;
        foreach ($activityIds as $activityId => $scope) {
            $activity = $u['activities'][$activityId] ?? null;
            if ($activity === null) {
                continue;
            }
            $direct = in_array($scope, $directScopes, true);
            $direct ? $directActivityCount++ : $contextActivityCount++;
            $date = $this->date($activity['date'] ?? null);
            $latestEvent = $this->later($latestEvent, $date);
            if (($activity['kind'] ?? null) === 'task' && ! $this->isCompletedStatus($activity['status'] ?? null)) {
                $due = $this->date($activity['due'] ?? null);
                if ($due !== null
                    && $due->greaterThanOrEqualTo($this->asOf->subDays(30))
                    && ($openTaskDue === null || $due->lessThan($openTaskDue))) {
                    $openTaskDue = $due;
                }
            }
            $this->addTimeline($timeline, $seen, [
                'key' => $activityId,
                'date' => $activity['date'],
                'kind' => $activity['kind'],
                'title' => $activity['title'],
                'status' => $activity['status'],
                'detail' => $activity['detail'],
                'due' => $activity['due'],
                'scope' => $scope,
                'source' => 'Zoho local activity mirror',
            ]);
        }

        $actionIds = [];
        foreach ($relatedKeys as $key => $scope) {
            foreach ($u['actionIndex'][$key] ?? [] as $actionId) {
                $actionIds[$actionId] = $scope;
            }
        }
        foreach ($actionIds as $actionId => $scope) {
            $action = $u['actions'][$actionId] ?? null;
            if ($action === null) {
                continue;
            }
            $date = $this->date($action['date'] ?? null);
            $latestEvent = $this->later($latestEvent, $date);
            $this->addTimeline($timeline, $seen, [
                'key' => $actionId,
                'date' => $action['date'],
                'kind' => 'commercial_action',
                'title' => $action['title'],
                'status' => $action['status'],
                'detail' => $action['detail'],
                'due' => $action['due'],
                'scope' => $scope,
                'source' => 'Zoho commercial action mirror',
            ]);
        }

        $dealScopes = [];
        if ($convertedDealId !== null && isset($u['deals'][$convertedDealId])) {
            $dealScopes[$convertedDealId] = 'converted deal';
        }
        if ($contactId !== null) {
            foreach ($u['dealsByContact'][$contactId] ?? [] as $dealId) {
                $dealScopes[$dealId] = 'converted contact';
            }
        }
        if ($accountId !== null) {
            foreach ($u['dealsByAccount'][$accountId] ?? [] as $dealId) {
                $dealScopes[$dealId] ??= 'converted account context';
            }
        }

        $latestDeal = null;
        foreach ($dealScopes as $dealId => $scope) {
            $deal = $u['deals'][$dealId];
            $date = $this->date($deal['date'] ?? null);
            $latestDeal = $this->later($latestDeal, $date);
            $latestEvent = $this->later($latestEvent, $date);
            $detail = $this->joinNonEmpty([
                $deal['stage'] ? 'Stage: '.$deal['stage'] : null,
                $deal['origin'] || $deal['destination'] ? trim(($deal['origin'] ?? '?').' → '.($deal['destination'] ?? '?')) : null,
                $deal['transport'] ? 'Mode: '.$deal['transport'] : null,
            ]);
            $this->addTimeline($timeline, $seen, [
                'key' => 'deal:'.$dealId,
                'date' => $deal['date'],
                'kind' => 'deal',
                'title' => $deal['name'],
                'status' => $deal['stage'],
                'detail' => $detail,
                'scope' => $scope,
                'source' => 'Zoho deal mirror',
            ]);
            foreach ($u['dealHistory'][$dealId] ?? [] as $history) {
                $this->addTimeline($timeline, $seen, [
                    'key' => 'deal-history:'.(string) $history['zoho_id'],
                    'date' => $this->dateString($history['occurred_at'] ?? null),
                    'kind' => 'deal_stage_history',
                    'title' => 'Deal stage changed',
                    'status' => $this->text($history['stage'] ?? $history['moved_to_stage'] ?? null),
                    'detail' => $this->joinNonEmpty([
                        $this->text($history['previous_stage'] ?? null) ? 'From: '.$this->text($history['previous_stage']) : null,
                        $this->text($history['moved_to_stage'] ?? null) ? 'To: '.$this->text($history['moved_to_stage']) : null,
                    ]),
                    'scope' => $scope,
                    'source' => 'Zoho deal stage history mirror',
                ]);
            }
        }

        $quoteScopes = [];
        foreach ($dealScopes as $dealId => $scope) {
            foreach ($u['quotesByDeal'][$dealId] ?? [] as $quoteId) {
                $quoteScopes[$quoteId] = $scope;
            }
        }
        if ($contactId !== null) {
            foreach ($u['quotesByContact'][$contactId] ?? [] as $quoteId) {
                $quoteScopes[$quoteId] = 'converted contact';
            }
        }
        if ($accountId !== null) {
            foreach ($u['quotesByAccount'][$accountId] ?? [] as $quoteId) {
                $quoteScopes[$quoteId] ??= 'converted account context';
            }
        }

        $latestQuote = null;
        foreach ($quoteScopes as $quoteId => $scope) {
            $quote = $u['quotes'][$quoteId];
            $date = $this->date($quote['date'] ?? $quote['last_activity_at'] ?? null);
            $latestQuote = $this->later($latestQuote, $date);
            $latestEvent = $this->later($latestEvent, $date);
            $transport = is_array($quote['transport']) ? implode(', ', array_map('strval', $quote['transport'])) : $quote['transport'];
            $this->addTimeline($timeline, $seen, [
                'key' => 'quote:'.$quoteId,
                'date' => $quote['date'],
                'kind' => 'quote',
                'title' => trim(($quote['number'] ? $quote['number'].' · ' : '').$quote['subject']),
                'status' => $quote['follow_up_status'] ?? $quote['status'],
                'detail' => $this->joinNonEmpty([
                    $quote['origin'] || $quote['destination'] ? trim(($quote['origin'] ?? '?').' → '.($quote['destination'] ?? '?')) : null,
                    $transport ? 'Mode: '.$transport : null,
                    $quote['valid_till'] ? 'Valid until: '.$quote['valid_till'] : null,
                ]),
                'scope' => $scope,
                'source' => 'Zoho quote mirror',
            ]);
            foreach ($u['quoteHistory'][$quoteId] ?? [] as $history) {
                $this->addTimeline($timeline, $seen, [
                    'key' => 'quote-history:'.(string) $history['zoho_id'],
                    'date' => $this->dateString($history['occurred_at'] ?? null),
                    'kind' => 'quote_status_history',
                    'title' => 'Quote status changed',
                    'status' => $this->text($history['status'] ?? null),
                    'detail' => $this->text($history['previous_status'] ?? null) ? 'Previous: '.$this->text($history['previous_status']) : null,
                    'scope' => $scope,
                    'source' => 'Zoho quote status history mirror',
                ]);
            }
        }

        if ($createdAt !== null) {
            $this->addTimeline($timeline, $seen, [
                'key' => 'lead-created:'.$leadId,
                'date' => $createdAt->toIso8601String(),
                'kind' => 'lead_created',
                'title' => 'Lead created in Zoho',
                'status' => null,
                'detail' => null,
                'scope' => 'lead direct',
                'source' => 'Zoho Lead',
            ]);
        }
        if ($convertedAt !== null) {
            $latestEvent = $this->later($latestEvent, $convertedAt);
            $this->addTimeline($timeline, $seen, [
                'key' => 'lead-converted:'.$leadId,
                'date' => $convertedAt->toIso8601String(),
                'kind' => 'lead_converted',
                'title' => 'Lead converted',
                'status' => 'Converted',
                'detail' => null,
                'scope' => 'lead direct',
                'source' => 'Zoho Lead',
            ]);
        }

        usort($timeline, fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        return [$timeline, [
            'activity_count' => count($activityIds),
            'direct_activity_count' => $directActivityCount,
            'context_activity_count' => $contextActivityCount,
            'commercial_action_count' => count($actionIds),
            'deal_count' => count($dealScopes),
            'quote_count' => count($quoteScopes),
            'latest_event_at' => $latestEvent,
            'latest_deal_at' => $latestDeal,
            'latest_quote_at' => $latestQuote,
            'open_task_due' => $openTaskDue,
        ]];
    }

    private function localMarketingSignals(?string $email, array $contactIds, array $contacts, array $u): array
    {
        $timeline = [];
        $seen = [];
        $latest = null;
        $touches = 0;
        $hasReply = false;
        $hasDemande = false;
        $clicked = false;
        $engaged = false;
        $hardBounce = false;
        $suppressed = $email !== null && ($u['suppressions']['by_email'][$email] ?? []) !== [];
        $verificationStatuses = [];
        $legalBases = [];
        $sourceUrlAvailable = false;

        foreach ($contacts as $contact) {
            $verification = $this->text($contact['email_verification_status'] ?? null);
            if ($verification !== null) {
                $verificationStatuses[] = mb_strtolower($verification);
            }
            $basis = $this->text($contact['legal_basis'] ?? null);
            if ($basis !== null && $basis !== 'unknown') {
                $legalBases[] = $basis;
            }
            $sourceUrlAvailable = $sourceUrlAvailable || $this->text($contact['source_url'] ?? null) !== null;
            if (($contact['deleted_at'] ?? null) !== null) {
                $suppressed = true;
            }
        }

        foreach ($contactIds as $contactId) {
            $suppressed = $suppressed || ($u['suppressions']['by_contact'][$contactId] ?? []) !== [];
            foreach ($u['campaignRecipients'][$contactId] ?? [] as $recipient) {
                $touches++;
                $campaign = $this->text($recipient['campaign_name'] ?? null) ?? 'Campaign';
                foreach ([
                    'sent_at' => ['campaign_sent', 'Campaign email sent'],
                    'opened_at' => ['campaign_opened', 'Campaign email opened'],
                    'clicked_at' => ['campaign_clicked', 'Campaign link clicked'],
                    'bounced_at' => ['campaign_bounced', 'Campaign email bounced'],
                    'replied_at' => ['campaign_replied', 'Campaign reply recorded'],
                ] as $column => [$kind, $title]) {
                    $date = $this->date($recipient[$column] ?? null);
                    if ($date === null) {
                        continue;
                    }
                    $latest = $this->later($latest, $date);
                    $engaged = $engaged || in_array($column, ['opened_at', 'clicked_at', 'replied_at'], true);
                    $clicked = $clicked || $column === 'clicked_at';
                    $hasReply = $hasReply || $column === 'replied_at';
                    $hardBounce = $hardBounce || $column === 'bounced_at';
                    $this->addTimeline($timeline, $seen, [
                        'key' => 'campaign-recipient:'.(string) $recipient['id'].':'.$column,
                        'date' => $date->toIso8601String(),
                        'kind' => $kind,
                        'title' => $title,
                        'status' => $this->text($recipient['status'] ?? null),
                        'detail' => $campaign.($recipient['campaign_subject'] ? ' · '.$recipient['campaign_subject'] : ''),
                        'scope' => 'exact email → local contact',
                        'source' => 'Fretiq campaign history',
                    ]);
                }
                $hardBounce = $hardBounce || in_array(mb_strtolower((string) ($recipient['status'] ?? '')), ['bounced', 'failed'], true);
            }

            foreach ($u['sequenceSends'][$contactId] ?? [] as $send) {
                $touches++;
                foreach ([
                    'sent_at' => ['sequence_sent', 'Sequence step sent'],
                    'opened_at' => ['sequence_opened', 'Sequence step opened'],
                    'bounced_at' => ['sequence_bounced', 'Sequence step bounced'],
                ] as $column => [$kind, $title]) {
                    $date = $this->date($send[$column] ?? null);
                    if ($date === null) {
                        continue;
                    }
                    $latest = $this->later($latest, $date);
                    $engaged = $engaged || $column === 'opened_at';
                    $hardBounce = $hardBounce || $column === 'bounced_at';
                    $this->addTimeline($timeline, $seen, [
                        'key' => 'sequence-send:'.(string) $send['id'].':'.$column,
                        'date' => $date->toIso8601String(),
                        'kind' => $kind,
                        'title' => $title,
                        'status' => $this->text($send['status'] ?? null),
                        'detail' => $this->joinNonEmpty([
                            $this->text($send['sequence_name'] ?? null),
                            'Step '.(string) ($send['step_no'] ?? '?'),
                        ]),
                        'scope' => 'exact email → local contact',
                        'source' => 'Fretiq sequence history',
                    ]);
                }
            }

            foreach ($u['inbox']['by_contact'][$contactId] ?? [] as $inbox) {
                $hasReply = true;
                $engaged = true;
                $date = $this->date($inbox['received_at'] ?? $inbox['created_at'] ?? null);
                $latest = $this->later($latest, $date);
                $this->addTimeline($timeline, $seen, [
                    'key' => 'inbox:'.(string) $inbox['id'],
                    'date' => $date?->toIso8601String(),
                    'kind' => 'inbox_reply',
                    'title' => 'Inbound email received',
                    'status' => $this->text($inbox['triage_action'] ?? $inbox['status'] ?? null),
                    'detail' => $this->truncate($this->text($inbox['subject'] ?? null), 250),
                    'scope' => 'exact email → local contact',
                    'source' => 'Fretiq inbox',
                ]);
            }

            foreach ($u['demandes'][$contactId] ?? [] as $demande) {
                $hasDemande = true;
                $date = $this->date($demande['captured_at'] ?? $demande['created_at'] ?? null);
                $latest = $this->later($latest, $date);
                $this->addTimeline($timeline, $seen, [
                    'key' => 'demande:'.(string) $demande['id'],
                    'date' => $date?->toIso8601String(),
                    'kind' => 'demande',
                    'title' => 'Commercial request captured',
                    'status' => $this->text($demande['status'] ?? null),
                    'detail' => $this->truncate($this->text($demande['notes'] ?? null), 350),
                    'scope' => 'exact email → local contact',
                    'source' => 'Fretiq demandes',
                ]);
            }
        }

        if ($email !== null) {
            foreach ($u['inbox']['by_email'][$email] ?? [] as $inbox) {
                $hasReply = true;
                $engaged = true;
                $date = $this->date($inbox['received_at'] ?? $inbox['created_at'] ?? null);
                $latest = $this->later($latest, $date);
                $this->addTimeline($timeline, $seen, [
                    'key' => 'inbox-email:'.(string) $inbox['id'],
                    'date' => $date?->toIso8601String(),
                    'kind' => 'inbox_reply',
                    'title' => 'Inbound email received',
                    'status' => $this->text($inbox['triage_action'] ?? $inbox['status'] ?? null),
                    'detail' => $this->truncate($this->text($inbox['subject'] ?? null), 250),
                    'scope' => 'exact sender email',
                    'source' => 'Fretiq inbox',
                ]);
            }
        }

        usort($timeline, fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        $verificationStatus = null;
        foreach (['invalid', 'undeliverable', 'disposable', 'risky', 'accept_all', 'unknown', 'valid', 'deliverable'] as $candidate) {
            if (in_array($candidate, $verificationStatuses, true)) {
                $verificationStatus = $candidate;
                break;
            }
        }

        return [
            'timeline' => $timeline,
            'latest_event_at' => $latest,
            'campaign_touch_count' => $touches,
            'has_reply' => $hasReply,
            'has_demande' => $hasDemande,
            'campaign_engaged' => $engaged,
            'campaign_clicked' => $clicked,
            'hard_bounce' => $hardBounce,
            'suppressed' => $suppressed,
            'verification_status' => $verificationStatus,
            'legal_basis' => $legalBases === [] ? null : implode(', ', array_values(array_unique($legalBases))),
            'source_url_available' => $sourceUrlAvailable,
        ];
    }

    private function classifyLead(array $s): array
    {
        $categories = $this->categoryDefinitions();
        $category = null;
        $suppressionReason = $s['suppression_reason'] ?? null;
        $recent = is_int($s['recency_days']) && $s['recency_days'] >= 0 ? $s['recency_days'] : null;
        $quoteRecent = $s['latest_quote_at'] instanceof CarbonImmutable
            && $s['latest_quote_at']->greaterThanOrEqualTo($this->asOf->subDays(45));
        $taskNear = $s['open_task_due'] instanceof CarbonImmutable
            && $s['open_task_due']->lessThanOrEqualTo($this->asOf->addDays(14));
        $immediate = $s['has_reply'] || $s['has_demande'] || $taskNear || $quoteRecent || ($recent !== null && $recent <= 7);

        if ($s['suppressed']) {
            $category = 'hard_suppression';
        } elseif (! $s['email_valid'] || $s['duplicate_count'] > 1) {
            $category = 'data_enrichment';
        } elseif ($s['converted'] && $immediate) {
            $category = 'human_follow_up';
        } elseif ($s['converted'] && $recent !== null && $recent <= 180) {
            $category = 'converted_active';
        } elseif ($s['converted']) {
            $category = 'converted_reactivation';
        } elseif ($s['disqualified']) {
            $category = 'disqualified_review';
        } elseif ($s['status_conflict']) {
            $category = 'status_conflict_review';
        } elseif ($immediate) {
            $category = 'human_follow_up';
        } elseif ($s['campaign_engaged']) {
            $category = 'warm_engaged';
        } elseif (($recent !== null && $recent <= 180) || in_array($s['status'], ['PROSPECT', 'Contacted', 'Prospect Chaud', 'Actif', 'Pre-Qualified'], true)) {
            $category = 'warm_prospect';
        } elseif ($s['fit_count'] >= 4 && ! $s['consumer_email'] && ! $s['risky_verification']) {
            $category = 'cold_pilot_candidate';
        } elseif ($s['email_valid']) {
            $category = 'long_term_nurture';
        } else {
            $category = 'data_enrichment';
        }

        $date = null;
        $when = '';
        $channel = '';
        switch ($category) {
            case 'hard_suppression':
                $when = 'Do not contact';
                $channel = 'Suppression only; no email or automated sequence';
                break;
            case 'data_enrichment':
                $date = $this->businessDate(10);
                $when = 'Review data by '.$date;
                $channel = 'CRM owner/data steward; do not send yet';
                break;
            case 'disqualified_review':
                $when = 'No outreach unless the owner requalifies the record';
                $channel = 'Quarterly CRM hygiene review';
                break;
            case 'status_conflict_review':
                $date = $this->businessDate(5);
                $when = 'Resolve Zoho status mapping by '.$date;
                $channel = 'CRM owner review; no automated send';
                break;
            case 'human_follow_up':
                if ($s['open_task_due'] instanceof CarbonImmutable && $s['open_task_due']->isFuture() && $s['open_task_due']->lessThanOrEqualTo($this->asOf->addDays(14))) {
                    $date = $this->businessDateFrom($s['open_task_due']);
                    $when = 'On the open task date: '.$date;
                } else {
                    $date = $this->businessDate(1);
                    $when = 'Within 1 business day ('.$date.')';
                }
                $channel = 'Assigned human, one-to-one email or phone; stop automation';
                break;
            case 'converted_active':
                $date = $this->businessDate(3);
                $when = 'Within 3 business days ('.$date.')';
                $channel = 'Relationship email from account owner';
                break;
            case 'converted_reactivation':
                $date = $this->businessDate(7);
                $when = 'Start reactivation within 7 business days ('.$date.')';
                $channel = 'Warm client reactivation sequence';
                break;
            case 'warm_engaged':
                $date = $this->businessDate(2);
                $when = 'Within 2 business days ('.$date.')';
                $channel = 'Short reply-focused follow-up';
                break;
            case 'warm_prospect':
                $date = $this->businessDate(5);
                $when = 'Within 5 business days ('.$date.')';
                $channel = 'Owner-led qualification sequence';
                break;
            case 'cold_pilot_candidate':
                $when = 'Only after lawful-basis, source-lineage and deliverability review';
                $channel = '15–25 contact manual pilot; cold-send flag stays off until approved';
                break;
            case 'long_term_nurture':
                $date = $this->businessDate(15);
                $when = 'Next reviewed nurture window (from '.$date.')';
                $channel = 'Low-frequency nurture only with documented lawful basis';
                break;
        }

        return [
            'category' => $category,
            'category_label' => $categories[$category]['label'],
            'recommended_when' => $when,
            'recommended_date' => $date,
            'recommended_channel' => $channel,
            'suppression_reason' => $suppressionReason,
        ];
    }

    private function priorityScore(array $s): int
    {
        if (in_array($s['category'], ['hard_suppression', 'disqualified_review'], true)) {
            return 0;
        }

        $score = 0;
        $score += $s['converted'] ? 18 : 0;
        $score += $s['has_reply'] ? 35 : 0;
        $score += $s['has_demande'] ? 35 : 0;
        $score += $s['open_task_due'] instanceof CarbonImmutable ? 20 : 0;
        $score += $s['campaign_clicked'] ? 15 : ($s['campaign_engaged'] ? 7 : 0);
        $score += min(18, (int) $s['fit_count'] * 3);
        $score += $s['email_valid'] && ! $s['consumer_email'] ? 6 : 0;

        if (is_int($s['recency_days']) && $s['recency_days'] >= 0) {
            $score += match (true) {
                $s['recency_days'] <= 7 => 22,
                $s['recency_days'] <= 30 => 17,
                $s['recency_days'] <= 90 => 11,
                $s['recency_days'] <= 365 => 5,
                default => 0,
            };
        }
        $score += match ($s['status']) {
            'Prospect Chaud' => 15,
            'PROSPECT', 'Contacted', 'Actif', 'Pre-Qualified' => 8,
            default => 0,
        };

        if ($s['duplicate_count'] > 1 || in_array($s['category'], ['data_enrichment', 'status_conflict_review'], true)) {
            $score = min($score, 30);
        }
        if ($s['category'] === 'cold_pilot_candidate') {
            $score = min($score, 55);
        }

        return max(0, min(100, $score));
    }

    private function whyList(
        array $classification,
        ?string $status,
        bool $converted,
        ?int $recencyDays,
        array $signals,
        array $localSignals,
        array $fitSignals,
        int $duplicateCount,
        bool $consumerEmail,
    ): array {
        $why = [];
        if ($classification['suppression_reason'] ?? null) {
            $why[] = $classification['suppression_reason'];
        }
        if ($converted) {
            $why[] = 'Zoho marks the Lead converted; treat it as relationship/account work, not cold acquisition.';
        }
        if ($localSignals['has_reply']) {
            $why[] = 'A deterministic Fretiq inbox/campaign reply signal exists.';
        }
        if ($localSignals['has_demande']) {
            $why[] = 'A commercial demande is linked to the exact local contact.';
        }
        if ($signals['open_task_due'] instanceof CarbonImmutable) {
            $why[] = 'An open Zoho task is due '.$signals['open_task_due']->toDateString().'.';
        }
        if ($signals['latest_quote_at'] instanceof CarbonImmutable) {
            $why[] = 'Linked quote context exists; latest dated '.$signals['latest_quote_at']->toDateString().'.';
        }
        if ($recencyDays !== null && $recencyDays >= 0) {
            $why[] = 'Latest deterministic evidence is '.$recencyDays.' day'.($recencyDays === 1 ? '' : 's').' old.';
        }
        if ($status !== null) {
            $why[] = 'Current live Lead status: '.$status.'.';
        }
        if ($status === 'Qualifié') {
            $why[] = 'Zoho metadata maps this display label to actual value “Junk Lead”; do not automate until corrected.';
        }
        if ($duplicateCount > 1) {
            $why[] = 'The same email appears on '.$duplicateCount.' Zoho Leads; deduplicate before outreach.';
        }
        if ($consumerEmail) {
            $why[] = 'Consumer-domain address requires role/business-context review; it is not proof of a personal account.';
        }
        if ($localSignals['campaign_engaged']) {
            $why[] = 'Exact-email campaign/sequence engagement exists.';
        }
        if ($fitSignals !== []) {
            $why[] = 'Available fit evidence: '.implode(', ', array_keys($fitSignals)).'.';
        }
        if ($why === []) {
            $why[] = 'Insufficient deterministic engagement or fit evidence; keep in review/nurture rather than immediate outreach.';
        }

        return array_slice(array_values(array_unique($why)), 0, 8);
    }

    private function suppressionReason(bool $optedOut, array $localSignals, bool $invalidVerification): ?string
    {
        return match (true) {
            $optedOut => 'Zoho email opt-out/unsubscribe is set.',
            $localSignals['suppressed'] => 'Exact email/contact is on the Fretiq suppression list or GDPR tombstone.',
            $localSignals['hard_bounce'] => 'A hard bounce/failure exists in Fretiq history.',
            $invalidVerification => 'Local email verification marks the address invalid or undeliverable.',
            default => null,
        };
    }

    private function confidence(bool $live, int $directActivities, int $contextActivities, int $localContacts, int $duplicates): string
    {
        if ($duplicates > 1) {
            return 'Low';
        }
        if ($live && ($directActivities > 0 || $localContacts > 0)) {
            return 'High';
        }
        if ($live && $contextActivities > 0) {
            return 'Medium';
        }

        return $live ? 'Medium' : 'Low';
    }

    private function categoryDefinitions(): array
    {
        return [
            'human_follow_up' => ['label' => 'Human follow-up now', 'color' => '#0f766e', 'contactable' => true],
            'converted_active' => ['label' => 'Converted · active relationship', 'color' => '#2563eb', 'contactable' => true],
            'converted_reactivation' => ['label' => 'Converted · dormant reactivation', 'color' => '#7c3aed', 'contactable' => true],
            'warm_engaged' => ['label' => 'Warm · engaged, no reply', 'color' => '#0891b2', 'contactable' => true],
            'warm_prospect' => ['label' => 'Warm · recent CRM evidence', 'color' => '#d97706', 'contactable' => true],
            'cold_pilot_candidate' => ['label' => 'Cold pilot candidate · gated', 'color' => '#ca8a04', 'contactable' => false],
            'long_term_nurture' => ['label' => 'Long-term nurture · gated', 'color' => '#64748b', 'contactable' => false],
            'status_conflict_review' => ['label' => 'Status conflict · owner review', 'color' => '#db2777', 'contactable' => false],
            'disqualified_review' => ['label' => 'Disqualified · no outreach', 'color' => '#b91c1c', 'contactable' => false],
            'data_enrichment' => ['label' => 'Data/dedup review · no send', 'color' => '#475569', 'contactable' => false],
            'hard_suppression' => ['label' => 'Hard suppression · never contact', 'color' => '#111827', 'contactable' => false],
        ];
    }

    private function strategyDefinitions(array $categories, array $counts): array
    {
        $strategies = [
            'human_follow_up' => [
                'type' => 'One-to-one human follow-up', 'trigger' => 'Reply/demande, near-due open task, quote in last 45 days, or CRM evidence in last 7 days.',
                'goal' => 'Clarify the live need and secure a short call or shipment brief.', 'cadence' => 'One personal message now; one reminder after 2 business days only if no reply.',
                'angle' => 'Reference the relevant lane/mode/task without disclosing confidential quote values.', 'cta' => '“Reply with origin, destination and timing” or book a 10-minute call.',
                'exit' => 'Reply, demande, opt-out, bounce, or owner marks next action.', 'subject' => 'Votre prochain besoin [mode/lane] ?',
            ],
            'converted_active' => [
                'type' => 'Relationship follow-up', 'trigger' => 'Converted Lead whose newest deterministic activity, deal or quote evidence is within 180 days.',
                'goal' => 'Win the next RFQ from an existing relationship.', 'cadence' => '2 emails over 7 business days (day 0 and day 4).',
                'angle' => 'Service continuity, lane expertise and fast quote turnaround; use owner voice.', 'cta' => 'Ask for the next shipment date or supplier pickup point.',
                'exit' => 'Reply/request, active quote, opt-out, bounce, or manual owner takeover.', 'subject' => 'Un besoin transport à chiffrer cette semaine ?',
            ],
            'converted_reactivation' => [
                'type' => 'Dormant-client reactivation', 'trigger' => 'Converted Lead without recent deterministic evidence.',
                'goal' => 'Re-open the relationship and identify whether freight needs still exist.', 'cadence' => '3 emails over 14 business days (day 0, 5, 12), then stop.',
                'angle' => 'Useful lane/mode update, operational check-in, and easy reply—not a brochure.', 'cta' => '“Are imports/exports still handled by you?”',
                'exit' => 'Reply, role change, opt-out, bounce, or no engagement after email 3.', 'subject' => 'Toujours en charge de vos expéditions ?',
            ],
            'warm_engaged' => [
                'type' => 'Engagement nudge', 'trigger' => 'Exact-email open/click exists but no reply.',
                'goal' => 'Convert weak engagement into a direct answer.', 'cadence' => 'One short nudge within 2 business days; optional final close-the-loop after 4 days.',
                'angle' => 'One question, plain text, no extra links.', 'cta' => 'Yes/no or one-line shipment detail.',
                'exit' => 'Reply, opt-out, bounce, or second no-response.', 'subject' => 'Est-ce un sujet chez vous actuellement ?',
            ],
            'warm_prospect' => [
                'type' => 'Qualification sequence', 'trigger' => 'Recent CRM activity or an explicit prospect/contacted status with a valid address.',
                'goal' => 'Confirm role, lane, mode and timing.', 'cadence' => '2 emails 4 business days apart; manual call if an open task says so.',
                'angle' => 'Specific operational problem by role; avoid generic company presentation.', 'cta' => 'Ask for route/mode or redirect to the logistics decision-maker.',
                'exit' => 'Reply, requalification, opt-out, bounce, or sequence completion.', 'subject' => 'Qui gère vos flux import/export ?',
            ],
            'cold_pilot_candidate' => [
                'type' => 'Cold pilot (currently blocked)', 'trigger' => 'Fit evidence + business-domain email, but no relationship/engagement evidence.',
                'goal' => 'Test one narrow ICP hypothesis without damaging sender reputation.', 'cadence' => 'After approval only: 15–25 frozen contacts, 2 emails four business days apart, max 10/day.',
                'angle' => 'One lane/mode pain point and one question. Do not claim importer activity without evidence.', 'cta' => 'Ask whether this flow is relevant and who owns it.',
                'exit' => 'Any reply, opt-out, bounce, role mismatch, or pilot stop threshold.', 'subject' => 'Flux [pays]–Maroc : pertinent pour vous ?',
            ],
            'long_term_nurture' => [
                'type' => 'Low-frequency nurture (gated)', 'trigger' => 'Valid address but weak timing/fit evidence.',
                'goal' => 'Stay useful until a real trigger appears.', 'cadence' => 'At most monthly, only with documented lawful basis and role relevance.',
                'angle' => 'Lane update, checklist, customs/change notice, or practical logistics insight.', 'cta' => 'Optional reply or preference update; no hard sell.',
                'exit' => 'Engagement moves to warm flow; inactivity/opt-out moves to suppression.', 'subject' => 'Point marché transport : [lane/mode]',
            ],
            'status_conflict_review' => [
                'type' => 'No email—status repair', 'trigger' => 'Display “Qualifié” maps to actual Zoho value “Junk Lead”.',
                'goal' => 'Have the owner decide whether the Lead is valid, junk or mislabelled.', 'cadence' => 'CRM review within 5 business days.',
                'angle' => 'Resolve metadata before marketing.', 'cta' => 'Owner selects a corrected status and records rationale.',
                'exit' => 'Correct status + contactability decision.', 'subject' => null,
            ],
            'disqualified_review' => [
                'type' => 'No email—disqualified', 'trigger' => 'Non qualifié/FAIBLE/Lost status on an unconverted Lead.',
                'goal' => 'Protect sender reputation and respect sales intent.', 'cadence' => 'No outreach; quarterly hygiene only.',
                'angle' => 'Requalify only when a new external signal exists.', 'cta' => 'Owner review, not recipient contact.',
                'exit' => 'New verified signal and explicit requalification.', 'subject' => null,
            ],
            'data_enrichment' => [
                'type' => 'No email—data/dedup', 'trigger' => 'Missing/invalid email or duplicate Zoho identity.',
                'goal' => 'Create one trustworthy identity with source lineage and deliverability evidence.', 'cadence' => 'Data review within 10 business days.',
                'angle' => 'Merge/relate records; preserve original data and consent/opt-out state.', 'cta' => 'Internal data task.',
                'exit' => 'Unique verified address + documented source/lawful basis.', 'subject' => null,
            ],
            'hard_suppression' => [
                'type' => 'Never email', 'trigger' => 'Zoho opt-out/unsubscribe, suppression, GDPR tombstone, invalid address or hard bounce.',
                'goal' => 'Prevent unlawful or harmful contact.', 'cadence' => 'None.',
                'angle' => 'No marketing communication.', 'cta' => 'None.',
                'exit' => 'Only a documented, recipient-initiated permission change under approved policy.', 'subject' => null,
            ],
        ];

        foreach ($strategies as $key => &$strategy) {
            $strategy['key'] = $key;
            $strategy['label'] = $categories[$key]['label'];
            $strategy['count'] = $counts[$key] ?? 0;
            $strategy['color'] = $categories[$key]['color'];
        }
        unset($strategy);

        return array_values($strategies);
    }

    private function sourceSummary(array $leads, array $u, int $timelineTotal): array
    {
        $count = count($leads);
        $countWhere = static fn (callable $predicate): int => count(array_filter($leads, $predicate));

        return [
            'coverage' => [
                'all_leads' => $count,
                'live_leads' => $countWhere(fn (array $lead): bool => $lead['provenance']['live_lead_record']),
                'local_mirror_leads' => $countWhere(fn (array $lead): bool => $lead['provenance']['local_mirror_record']),
                'live_only_new_leads' => $countWhere(fn (array $lead): bool => $lead['provenance']['live_lead_record'] && ! $lead['provenance']['local_mirror_record']),
                'timeline_events' => $timelineTotal,
                'leads_with_any_timeline' => $countWhere(fn (array $lead): bool => $lead['timeline'] !== []),
                'leads_with_direct_activity' => $countWhere(fn (array $lead): bool => $lead['direct_activity_count'] > 0),
                'leads_with_quote_context' => $countWhere(fn (array $lead): bool => $lead['quote_count'] > 0),
                'leads_with_campaign_touch' => $countWhere(fn (array $lead): bool => $lead['campaign_touch_count'] > 0),
                'converted' => $countWhere(fn (array $lead): bool => $lead['converted']),
                'unconverted' => $countWhere(fn (array $lead): bool => ! $lead['converted']),
                'missing_or_invalid_email' => $countWhere(fn (array $lead): bool => ! $lead['email_valid']),
                'duplicate_email_records' => $countWhere(fn (array $lead): bool => $lead['duplicate_email_count'] > 1),
                'hard_suppressed' => $countWhere(fn (array $lead): bool => $lead['category'] === 'hard_suppression'),
                'consumer_domain' => $countWhere(fn (array $lead): bool => $lead['consumer_email_domain']),
            ],
            'tables' => [
                'zoho_accounts' => count($u['accounts']),
                'zoho_contacts' => count($u['zohoContacts']),
                'zoho_activities' => count($u['activities']),
                'zoho_actions_commercials' => count($u['actions']),
                'zoho_deals' => count($u['deals']),
                'zoho_quotes' => count($u['quotes']),
                'local_contacts' => count($u['localContacts']),
            ],
            'status_distribution' => collect($leads)->countBy(fn (array $lead): string => $lead['status'] ?? '[missing]')->sortDesc()->all(),
            'data_quality_findings' => [
                'The live CRM returned 4,856 unique Leads; the mirror contained 4,851, so five live Leads have no local history yet.',
                'Zoho returned all pages with HTTP 200 using 50 reviewed fields and converted=both; Created_Time and Modified_Time were still absent from these record payloads.',
                'The Lead status display “Qualifié” maps to the underlying actual value “Junk Lead”; this report treats unconverted records with that label as a data-integrity review, not qualified outreach.',
                'Only deterministic IDs and exact normalized-email matches are used. Account-level quotes or activities are labelled context and do not prove personal engagement.',
                'Fretiq campaign history is sparse for Zoho Leads because all fretiq_contact_id links are empty and only exact-email matches bridge the systems.',
                'Open pixels are diagnostic only. Historical tracking contains a known duplicate-token anomaly; recommendations do not use raw tracking-row volume.',
                'No reliable Lead status-change history is present locally. The current status is live; activity, quote and deal histories are from the mirror as of its sync timestamp.',
            ],
            'local_mirror_synced_at' => $u['localMirrorSyncedAt']?->toIso8601String(),
        ];
    }

    private function additionalZohoData(): array
    {
        $requested = array_flip(auditLiveLeadFields());
        $extraFields = collect($this->liveMetadata['fields'] ?? [])
            ->filter(fn (array $field): bool => ! isset($requested[$field['api_name'] ?? '']))
            ->values()
            ->all();
        $related = $this->liveMetadata['related_lists'] ?? [];

        return [
            'verified_http_status' => $this->liveMetadata['statuses'] ?? [],
            'field_count' => count($this->liveMetadata['fields'] ?? []),
            'requested_for_live_lead_snapshot' => count(auditLiveLeadFields()),
            'additional_fields_advertised' => $extraFields,
            'related_lists_advertised' => $related,
            'recommended_retrieval_order' => [
                ['priority' => 1, 'data' => 'Lead E-mails related list', 'why' => 'Provides direct sent/received context missing from the current mirror; retrieve metadata and timestamps first, bodies only when necessary.'],
                ['priority' => 2, 'data' => 'Chronological activity history + closed Tasks/Calls/Events', 'why' => 'Can fill lead-specific status/touchpoint gaps and validate the local activity mirror.'],
                ['priority' => 3, 'data' => 'Cadences and scoring rules', 'why' => 'Shows existing Zoho automation/score context and prevents overlapping Fretiq sequences.'],
                ['priority' => 4, 'data' => 'Attachments/checklists/products', 'why' => 'Potential qualification evidence, but higher privacy/volume cost; pull only for shortlisted leads.'],
                ['priority' => 5, 'data' => 'Zoho Desk/Survey/Voice of Customer', 'why' => 'Useful relationship signals after lawful-purpose review; not needed for the first mailing pilot.'],
            ],
            'caveat' => 'Related lists are live-advertised by Zoho metadata (HTTP 200) but were not bulk-downloaded in this audit. Availability does not prove every Lead has records or that every list is readable with the current OAuth scope.',
        ];
    }

    private function addTimeline(array &$timeline, array &$seen, array $event): void
    {
        $key = (string) ($event['key'] ?? hash('sha256', json_encode($event)));
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        unset($event['key']);
        $timeline[] = $event;
    }

    private function isCompletedStatus(mixed $status): bool
    {
        $value = mb_strtolower($this->text($status) ?? '');

        return in_array($value, ['completed', 'terminé', 'termine', 'closed', 'cancelled', 'canceled'], true);
    }

    private function later(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $b->greaterThan($a) ? $b : $a;
    }

    /** @param list<CarbonImmutable> $dates */
    private function latestDate(array $dates): ?CarbonImmutable
    {
        $latest = null;
        foreach ($dates as $date) {
            $latest = $this->later($latest, $date);
        }

        return $latest;
    }

    private function joinNonEmpty(array $parts): ?string
    {
        $parts = array_values(array_filter(array_map(fn ($part): ?string => $this->text($part), $parts)));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function businessDate(int $businessDays): string
    {
        $date = $this->asOf->startOfDay();
        for ($i = 0; $i < $businessDays; ) {
            $date = $date->addDay();
            if (! $date->isWeekend()) {
                $i++;
            }
        }

        return $date->toDateString();
    }

    private function businessDateFrom(CarbonImmutable $date): string
    {
        $date = $date->setTimezone('Africa/Casablanca')->startOfDay();
        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        return $date->toDateString();
    }

    private function renderDetailed(array $dataset): string
    {
        $json = json_encode(
            $dataset,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
        $date = $dataset['meta']['report_date'];
        $summaryFile = 'zoho-lead-marketing-strategy-report-'.$date.'.html';

        $template = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Zoho lead-by-lead audit — __DATE__</title>
<style>
:root{--ink:#13231f;--muted:#66756f;--paper:#f3f0e8;--card:#fffdf7;--line:#d9ded8;--accent:#0f766e;--accent2:#d97706;--shadow:0 14px 40px rgba(27,48,43,.09)}
*{box-sizing:border-box}body{margin:0;color:var(--ink);background:radial-gradient(circle at 8% -10%,#d7ebe4 0,transparent 35%),var(--paper);font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
a{color:var(--accent)}button,input,select{font:inherit}.shell{max-width:1580px;margin:auto;padding:26px}.hero{background:linear-gradient(120deg,#102d26,#164f45 72%,#8a5b16);color:#fff;border-radius:26px;padding:30px;box-shadow:var(--shadow)}
.eyebrow{text-transform:uppercase;letter-spacing:.13em;font-size:11px;font-weight:800;color:#9ee3d2}.hero h1{font:700 clamp(28px,4vw,52px)/1.04 Georgia,serif;margin:8px 0 12px;max-width:920px}.hero p{max-width:980px;color:#d7ede6;margin:0}.hero-links{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}.hero-links a{color:#fff;border:1px solid #7fb3a7;border-radius:999px;padding:8px 13px;text-decoration:none}
.notice{margin:16px 0;background:#fff4d6;border:1px solid #e7c66f;color:#5e4813;border-radius:14px;padding:12px 15px}.metrics{display:grid;grid-template-columns:repeat(6,minmax(145px,1fr));gap:12px;margin:18px 0}.metric,.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 6px 22px rgba(27,48,43,.05)}.metric{padding:16px}.metric b{display:block;font:700 27px/1 Georgia,serif}.metric span{display:block;color:var(--muted);margin-top:7px}
.category-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:18px 0}.cat-card{border:1px solid var(--line);background:var(--card);border-left:6px solid var(--cat);border-radius:14px;padding:12px;cursor:pointer}.cat-card strong{display:block}.cat-card b{font-size:23px}.cat-card span{color:var(--muted);font-size:12px}
.panel{padding:17px;margin:16px 0}.panel h2{font:700 25px/1.2 Georgia,serif;margin:0 0 12px}.filters{display:grid;grid-template-columns:minmax(260px,2fr) repeat(4,minmax(145px,1fr));gap:10px}.filters input,.filters select{width:100%;border:1px solid #cbd5cf;background:#fff;border-radius:10px;padding:10px}.actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px}.btn{border:0;border-radius:10px;padding:9px 13px;background:#e5ebe7;color:var(--ink);cursor:pointer}.btn.primary{background:var(--accent);color:#fff}.btn:disabled{opacity:.45}.result-count{color:var(--muted)}
.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:14px;background:#fff}table{border-collapse:collapse;width:100%;min-width:1250px}th,td{padding:10px 11px;border-bottom:1px solid #e7ebe7;text-align:left;vertical-align:top}th{position:sticky;top:0;z-index:2;background:#eef4f0;color:#39534b;font-size:11px;text-transform:uppercase;letter-spacing:.05em}.lead-row{cursor:pointer}.lead-row:hover{background:#f7fbf8}.score{font-weight:800;font-size:16px}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;color:#fff;font-size:11px;font-weight:800;white-space:nowrap}.muted{color:var(--muted)}.small{font-size:12px}.pagination{display:flex;justify-content:center;align-items:center;gap:8px;margin:14px 0}
dialog{width:min(1120px,96vw);max-height:92vh;border:0;border-radius:22px;padding:0;box-shadow:0 24px 80px rgba(0,0,0,.3)}dialog::backdrop{background:rgba(13,32,27,.72)}.dialog-head{position:sticky;top:0;z-index:4;background:#123b33;color:#fff;padding:18px 22px;display:flex;justify-content:space-between;gap:15px}.dialog-head h2{margin:0;font:700 26px/1.1 Georgia,serif}.close{border:1px solid #a9cec4;color:#fff;background:transparent;border-radius:10px;padding:6px 10px;cursor:pointer}.dialog-body{padding:20px;background:#faf8f1}.detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.detail{background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px;min-width:0}.detail label{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}.detail div{overflow-wrap:anywhere}.wide{grid-column:span 2}.full{grid-column:1/-1}.why{margin:8px 0 0;padding-left:18px}.timeline{margin-top:14px}.timeline-item{display:grid;grid-template-columns:165px 130px 1fr;gap:12px;border-left:3px solid #7aa99e;padding:8px 10px 12px;margin:0 0 8px;background:#fff;border-radius:0 10px 10px 0}.timeline-item .kind{font-weight:800}.timeline-item .date{color:var(--muted);font-size:12px}.timeline-item .scope{font-size:11px;color:#76570c}.timeline-item p{margin:3px 0}.empty{padding:22px;text-align:center;color:var(--muted)}
.method{display:grid;grid-template-columns:1fr 1fr;gap:14px}.method ul{margin:8px 0;padding-left:20px}.footer{color:var(--muted);font-size:12px;padding:18px 0 30px}
@media(max-width:1000px){.metrics{grid-template-columns:repeat(3,1fr)}.filters{grid-template-columns:1fr 1fr}.detail-grid{grid-template-columns:1fr 1fr}.method{grid-template-columns:1fr}}@media(max-width:650px){.shell{padding:12px}.hero{padding:22px;border-radius:18px}.metrics{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr}.wide{grid-column:auto}.timeline-item{grid-template-columns:1fr}.dialog-body{padding:12px}}
</style>
</head>
<body>
<main class="shell">
  <header class="hero">
    <div class="eyebrow">TCL · internal marketing intelligence · __DATE__</div>
    <h1>Every Zoho Lead, reconciled one by one</h1>
    <p>Live current Lead fields are combined with the local Zoho activity/deal/quote mirror and exact-email Fretiq campaign history. Every Lead appears exactly once, with an evidence-based category, timing, reason and confidence level.</p>
    <div class="hero-links"><a href="__SUMMARY_FILE__">Open executive strategy report</a><a href="#lead-table">Jump to all Leads</a><a href="#method">Read method and limits</a></div>
  </header>
  <div class="notice" id="piiNotice"></div>
  <section class="metrics" id="metrics"></section>
  <section class="category-strip" id="categoryStrip" aria-label="Lead categories"></section>
  <section class="panel" id="lead-table">
    <h2>Lead-by-lead decision register</h2>
    <div class="filters">
      <input id="search" type="search" placeholder="Search name, company, email, owner, sector, Zoho ID">
      <select id="category"><option value="">All categories</option></select>
      <select id="status"><option value="">All statuses</option></select>
      <select id="conversion"><option value="">Converted + unconverted</option><option value="yes">Converted</option><option value="no">Unconverted</option></select>
      <select id="confidence"><option value="">All confidence levels</option><option>High</option><option>Medium</option><option>Low</option></select>
    </div>
    <div class="actions"><span class="result-count" id="resultCount"></span><div><button class="btn" id="reset">Reset filters</button> <button class="btn primary" id="csv">Download filtered CSV</button></div></div>
  </section>
  <div class="table-wrap"><table><thead><tr><th>Score</th><th>Category</th><th>Lead / company</th><th>Status</th><th>Owner</th><th>Latest evidence</th><th>CRM evidence</th><th>Recommended contact</th><th>Confidence</th></tr></thead><tbody id="rows"></tbody></table></div>
  <div class="pagination"><button class="btn" id="prev">Previous</button><span id="page"></span><button class="btn" id="next">Next</button></div>
  <section class="panel method" id="method"><div><h2>Coverage and reconciliation</h2><ul id="coverage"></ul></div><div><h2>Data-quality limits</h2><ul id="quality"></ul></div></section>
  <footer class="footer">Generated locally from read-only sources. No Zoho record, local database row, campaign, sequence, provider setting or suppression was changed.</footer>
</main>
<dialog id="detailDialog"><div class="dialog-head"><div><div class="eyebrow">Lead decision record</div><h2 id="detailTitle"></h2></div><button class="close" id="closeDialog">Close</button></div><div class="dialog-body" id="detailBody"></div></dialog>
<script type="application/json" id="reportData">__REPORT_JSON__</script>
<script>
const report=JSON.parse(document.getElementById('reportData').textContent);let filtered=[...report.leads],page=1;const pageSize=50;
const $=id=>document.getElementById(id);const text=(tag,value,cls)=>{const e=document.createElement(tag);if(cls)e.className=cls;e.textContent=value??'—';return e};
const fmtDate=v=>{if(!v)return'—';const d=new Date(v);return Number.isNaN(d.valueOf())?String(v):d.toLocaleString('fr-FR',{year:'numeric',month:'short',day:'2-digit'});};
const metric=(value,label)=>{const d=text('div','', 'metric');d.append(text('b',Number(value).toLocaleString('fr-FR')));d.append(text('span',label));return d};
function init(){const c=report.source_summary.coverage;$('piiNotice').textContent=report.meta.pii_notice;$('metrics').append(metric(c.all_leads,'Leads analyzed'),metric(c.converted,'Converted'),metric(c.leads_with_direct_activity,'Direct CRM activity'),metric(c.leads_with_quote_context,'Quote context'),metric(c.leads_with_campaign_touch,'Fretiq campaign match'),metric(c.hard_suppressed,'Hard suppressed'));
 report.categories.forEach(cat=>{const card=text('button','', 'cat-card');card.type='button';card.style.setProperty('--cat',cat.color);card.append(text('b',cat.count));card.append(text('strong',cat.label));card.append(text('span',cat.contactable?'Potential contact motion':'Review / no-send motion'));card.onclick=()=>{$('category').value=cat.key;apply()};$('categoryStrip').append(card);const o=text('option',`${cat.label} (${cat.count})`);o.value=cat.key;$('category').append(o)});
 [...new Set(report.leads.map(l=>l.status||'[missing]'))].sort().forEach(s=>{const o=text('option',s);o.value=s;$('status').append(o)});
 const labels={all_leads:'All live Leads',live_leads:'Live Leads',local_mirror_leads:'Local mirror Leads',live_only_new_leads:'Live-only new Leads',timeline_events:'Timeline events',leads_with_any_timeline:'Leads with timeline',leads_with_direct_activity:'Direct activity',leads_with_quote_context:'Quote context',leads_with_campaign_touch:'Campaign match',converted:'Converted',unconverted:'Unconverted',missing_or_invalid_email:'Missing/invalid email',duplicate_email_records:'Duplicate-email records',hard_suppressed:'Hard suppressed',consumer_domain:'Consumer-domain address'};
 Object.entries(c).forEach(([k,v])=>{const li=text('li',`${labels[k]||k}: ${Number(v).toLocaleString('fr-FR')}`);$('coverage').append(li)});report.source_summary.data_quality_findings.forEach(q=>$('quality').append(text('li',q)));
 ['search','category','status','conversion','confidence'].forEach(id=>$(id).addEventListener(id==='search'?'input':'change',apply));$('reset').onclick=()=>{['search','category','status','conversion','confidence'].forEach(id=>$(id).value='');apply()};$('prev').onclick=()=>{if(page>1){page--;render()}};$('next').onclick=()=>{if(page<Math.ceil(filtered.length/pageSize)){page++;render()}};$('csv').onclick=downloadCsv;$('closeDialog').onclick=()=>$('detailDialog').close();render();}
function apply(){const q=$('search').value.trim().toLowerCase(),cat=$('category').value,status=$('status').value,conv=$('conversion').value,conf=$('confidence').value;filtered=report.leads.filter(l=>{const hay=[l.name,l.company,l.email,l.owner,l.industry,l.country,l.zoho_id,l.status,l.category_label].filter(Boolean).join(' ').toLowerCase();return(!q||hay.includes(q))&&(!cat||l.category===cat)&&(!status||(l.status||'[missing]')===status)&&(!conv||(conv==='yes')===!!l.converted)&&(!conf||l.confidence===conf)});page=1;render();}
function render(){const body=$('rows');body.replaceChildren();const start=(page-1)*pageSize,items=filtered.slice(start,start+pageSize);items.forEach(l=>{const tr=document.createElement('tr');tr.className='lead-row';tr.tabIndex=0;tr.onclick=()=>showLead(l);tr.onkeydown=e=>{if(e.key==='Enter')showLead(l)};tr.append(cell(l.priority_score,'score'));const badge=text('span',l.category_label,'badge');badge.style.background=l.category_color;tr.append(cellNode(badge));const who=document.createElement('div');who.append(text('strong',l.name));who.append(text('div',l.company,'muted small'));who.append(text('div',l.email||'No valid email','small'));tr.append(cellNode(who));tr.append(cell(`${l.status||'[missing]'}${l.converted?' · converted':''}`));tr.append(cell(l.owner));tr.append(cell(fmtDate(l.latest_evidence_at)));tr.append(cell(`${l.activity_count} activities · ${l.quote_count} quotes · ${l.campaign_touch_count} campaign touches`));tr.append(cell(l.recommended_when));tr.append(cell(l.confidence));body.append(tr)});$('resultCount').textContent=`${filtered.length.toLocaleString('fr-FR')} Leads match · rows ${filtered.length?start+1:0}–${Math.min(start+pageSize,filtered.length)}`;$('page').textContent=`Page ${page} / ${Math.max(1,Math.ceil(filtered.length/pageSize))}`;$('prev').disabled=page<=1;$('next').disabled=page>=Math.ceil(filtered.length/pageSize);}
function cell(v,cls){return cellNode(text('div',v,cls))}function cellNode(n){const td=document.createElement('td');td.append(n);return td}
function detail(label,value,cls=''){const d=text('div','',`detail ${cls}`.trim());d.append(text('label',label));d.append(text('div',value??'—'));return d}
function showLead(l){$('detailTitle').textContent=`${l.name} · ${l.company}`;const body=$('detailBody');body.replaceChildren();const grid=text('div','', 'detail-grid');const badge=text('span',l.category_label,'badge');badge.style.background=l.category_color;const badgeWrap=text('div','', 'detail');badgeWrap.append(text('label','Category'));badgeWrap.append(badge);grid.append(badgeWrap,detail('Priority score',`${l.priority_score}/100`),detail('Confidence',l.confidence),detail('Recommended timing',l.recommended_when),detail('Channel',l.recommended_channel,'wide'),detail('Owner',l.owner),detail('Zoho ID',l.zoho_id),detail('Status',l.status||'[missing]'),detail('Converted',l.converted?'Yes':'No'),detail('Email',l.email||'Missing/invalid'),detail('Phones',[l.phone,l.mobile,l.secondary_phone].filter(Boolean).join(' · ')||'—'),detail('Role',l.title),detail('Sector',l.industry),detail('Country / city',[l.country,l.city].filter(Boolean).join(' · ')),detail('Transport / Incoterm',[l.transport_type,l.incoterm].filter(Boolean).join(' · ')),detail('Route context',[l.origin_destination,l.destination].filter(Boolean).join(' · '),'wide'),detail('Latest evidence',fmtDate(l.latest_evidence_at)),detail('Last CRM activity',fmtDate(l.last_activity_at)),detail('Identity checks',`${l.duplicate_email_count||0} records with this email · verification ${l.email_verification_status||'unknown'} · ${l.consumer_email_domain?'consumer domain':'business/other domain'}`,'wide'),detail('Data sources',`${l.provenance.live_lead_record?'live Lead':''}${l.provenance.local_mirror_record?' + local mirror/history':''}`,'wide'));if(l.status_integrity_note)grid.append(detail('Status warning',l.status_integrity_note,'full'));if(l.observation)grid.append(detail('Observation',l.observation,'full'));const why=text('div','', 'detail full');why.append(text('label','Why this recommendation'));const ul=text('ul','', 'why');l.why.forEach(x=>ul.append(text('li',x)));why.append(ul);grid.append(why);body.append(grid);
 const heading=text('h3',`Complete linked timeline (${l.timeline.length})`);heading.className='timeline';body.append(heading);if(!l.timeline.length){body.append(text('div','No deterministic history linked beyond the current Lead fields.','empty'))}else{const holder=text('div','', 'timeline');let limit=Math.min(150,l.timeline.length);const draw=()=>{holder.replaceChildren();l.timeline.slice(0,limit).forEach(e=>{const item=text('div','', 'timeline-item');item.append(text('div',fmtDate(e.date),'date'));item.append(text('div',e.kind||'event','kind'));const main=text('div','');main.append(text('strong',e.title));if(e.status)main.append(text('p',`Status: ${e.status}`));if(e.detail)main.append(text('p',e.detail));main.append(text('div',`${e.scope||'context'} · ${e.source||'source'}`,'scope'));item.append(main);holder.append(item)});if(limit<l.timeline.length){const more=text('button',`Show all ${l.timeline.length} events`,'btn');more.onclick=()=>{limit=l.timeline.length;draw()};holder.append(more)}};draw();body.append(holder)}$('detailDialog').showModal();}
function csvEscape(v){const s=Array.isArray(v)?v.join(' | '):String(v??'');return `"${s.replaceAll('"','""')}"`;}function downloadCsv(){const cols=['priority_score','category_label','name','company','email','status','converted','owner','industry','country','latest_evidence_at','recommended_when','confidence','why'];const lines=[cols.join(','),...filtered.map(l=>cols.map(c=>csvEscape(l[c])).join(','))];const blob=new Blob(['\ufeff'+lines.join('\n')],{type:'text/csv;charset=utf-8'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=`zoho-leads-filtered-__DATE__.csv`;a.click();URL.revokeObjectURL(a.href)}
init();
</script>
</body></html>
HTML;

        return str_replace(
            ['__DATE__', '__SUMMARY_FILE__', '__REPORT_JSON__'],
            [$date, $summaryFile, $json],
            $template,
        );
    }

    private function renderSummary(array $dataset): string
    {
        $summaryData = array_intersect_key($dataset, array_flip([
            'meta', 'categories', 'strategies', 'source_summary', 'additional_zoho_data', 'status_picklist', 'top_targets',
        ]));
        $json = json_encode(
            $summaryData,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
        $date = $dataset['meta']['report_date'];
        $detailFile = 'zoho-lead-by-lead-audit-'.$date.'.html';

        $template = <<<'HTML'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Zoho Lead marketing strategy — __DATE__</title>
<style>
:root{--ink:#17251f;--muted:#68756f;--paper:#f2eee3;--card:#fffdf7;--line:#ddd9cc;--green:#0f766e;--gold:#b7791f;--red:#b42318}*{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#edf4ef 0,#f4eee1 50%,#efe6d3 100%);color:var(--ink);font:15px/1.55 Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{max-width:1320px;margin:auto;padding:28px}.hero{position:relative;overflow:hidden;background:#173f35;color:#fff;border-radius:28px;padding:38px;box-shadow:0 22px 55px rgba(20,52,44,.18)}.hero:after{content:"";position:absolute;width:330px;height:330px;border-radius:50%;right:-90px;top:-130px;background:rgba(218,166,63,.2)}.eyebrow{text-transform:uppercase;letter-spacing:.14em;color:#a9e4d5;font-size:11px;font-weight:800}.hero h1{font:700 clamp(34px,5vw,66px)/.98 Georgia,serif;margin:9px 0 16px;max-width:900px}.hero p{max-width:910px;color:#dcece6;font-size:17px}.links{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.links a{border:1px solid #89b4aa;color:#fff;border-radius:999px;padding:9px 14px;text-decoration:none}.warning{background:#fff1c9;border:1px solid #e3bd58;border-radius:14px;padding:13px 16px;margin:18px 0;color:#614a10}.metrics{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}.metric,.panel,.strategy,.category{background:var(--card);border:1px solid var(--line);border-radius:17px;box-shadow:0 8px 28px rgba(38,48,42,.05)}.metric{padding:18px}.metric b{font:700 32px/1 Georgia,serif;display:block}.metric span{display:block;color:var(--muted);margin-top:6px}.panel{padding:22px;margin:18px 0}.panel h2{font:700 29px/1.15 Georgia,serif;margin:0 0 12px}.panel h3{font:700 20px/1.2 Georgia,serif}.lead{font-size:17px;color:#334c44;max-width:980px}.categories{display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:11px}.category{border-left:6px solid var(--cat);padding:13px}.category b{font:700 26px Georgia,serif}.category strong{display:block}.category small{color:var(--muted)}.strategy-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.strategy{padding:18px;border-top:5px solid var(--cat)}.strategy h3{margin:0 0 5px}.strategy .count{color:var(--muted)}.strategy dl{display:grid;grid-template-columns:110px 1fr;gap:7px 10px}.strategy dt{text-transform:uppercase;font-size:10px;letter-spacing:.06em;color:var(--muted);font-weight:800}.strategy dd{margin:0}.subject{background:#edf5f1;border-radius:9px;padding:9px 11px}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:14px}table{border-collapse:collapse;width:100%;min-width:940px;background:#fff}th,td{padding:10px 12px;border-bottom:1px solid #e6e4da;text-align:left;vertical-align:top}th{background:#eaf2ed;text-transform:uppercase;letter-spacing:.05em;font-size:11px}.badge{display:inline-block;border-radius:999px;color:#fff;font-size:11px;font-weight:800;padding:4px 8px}.split{display:grid;grid-template-columns:1fr 1fr;gap:14px}.callout{border-left:5px solid var(--red);background:#fff0ee;padding:14px;border-radius:10px}.good{border-left-color:var(--green);background:#edf8f4}.ordered{counter-reset:item;list-style:none;padding:0}.ordered li{counter-increment:item;position:relative;padding:12px 12px 12px 48px;margin:8px 0;background:#fff;border:1px solid var(--line);border-radius:11px}.ordered li:before{content:counter(item);position:absolute;left:12px;top:10px;width:25px;height:25px;border-radius:50%;display:grid;place-items:center;background:var(--green);color:#fff;font-weight:800}.small{font-size:12px;color:var(--muted)}footer{padding:18px 0 35px;color:var(--muted);font-size:12px}@media(max-width:900px){.metrics{grid-template-columns:repeat(2,1fr)}.strategy-grid,.split{grid-template-columns:1fr}}@media(max-width:560px){.wrap{padding:12px}.hero{padding:25px;border-radius:19px}.metrics{grid-template-columns:1fr}}
</style></head><body><main class="wrap"><header class="hero"><div class="eyebrow">TCL · evidence-led email strategy · __DATE__</div><h1>Who to contact, when—and who not to email</h1><p>This report converts every live Zoho Lead into one exclusive action category, using current Lead fields plus deterministic CRM and Fretiq history. The strategy protects sender reputation by separating human follow-up, relationship reactivation, gated cold outreach, data repair and hard suppression.</p><div class="links"><a href="__DETAIL_FILE__">Open all Leads one by one</a><a href="#strategy">Category email strategies</a><a href="#zoho-more">Additional Zoho data</a></div></header>
<div class="warning" id="notice"></div><section class="metrics" id="metrics"></section><section class="panel"><h2>Executive decision</h2><p class="lead">Do not launch one broad automated campaign. Start with human/relationship categories, repair the contradictory Lead status mapping, and keep cold outreach blocked until legal-basis, source-lineage and deliverability checks are present. Opens are diagnostic only; replies, demandes, open tasks and recent quote/activity evidence drive timing.</p><div class="categories" id="categories"></div></section>
<section class="panel"><h2>Top contact queue</h2><p class="lead">Highest-scoring contactable Leads. These still require the named owner to review account-context evidence before sending.</p><div class="table-wrap"><table><thead><tr><th>Score</th><th>Who</th><th>Category</th><th>Status</th><th>Owner</th><th>When</th><th>Why</th></tr></thead><tbody id="topTargets"></tbody></table></div></section>
<section class="panel" id="strategy"><h2>Email strategy by category</h2><div class="strategy-grid" id="strategies"></div></section>
<section class="panel split"><div><h2>Critical data findings</h2><div class="callout"><strong>Status integrity blocker</strong><p>Zoho returned HTTP 200 metadata showing display label “Qualifié” maps to actual value “Junk Lead”. Unconverted Leads with that label are not treated as qualified marketing targets.</p></div><ul id="quality"></ul></div><div><h2>Coverage facts</h2><ul id="coverage"></ul><div class="callout good"><strong>Complete current Lead inventory</strong><p id="reconcile"></p></div></div></section>
<section class="panel" id="zoho-more"><h2>Additional Zoho data that can be retrieved</h2><p class="lead">Zoho’s live field/related-list metadata returned HTTP 200. The items below are advertised by this organization but are not all mirrored locally.</p><ol class="ordered" id="retrievalOrder"></ol><details><summary>Show all advertised related lists</summary><div class="table-wrap"><table><thead><tr><th>API name</th><th>Display label</th><th>Module</th></tr></thead><tbody id="relatedLists"></tbody></table></div></details><details><summary>Show metadata fields not included in the 50-field live snapshot</summary><div class="table-wrap"><table><thead><tr><th>API name</th><th>Label</th><th>Type</th></tr></thead><tbody id="extraFields"></tbody></table></div></details><p class="small" id="zohoCaveat"></p></section>
<section class="panel"><h2>Recommended operating sequence</h2><ol class="ordered"><li>Freeze all hard-suppressed, disqualified, duplicate and status-conflict records from automation.</li><li>Assign the human-follow-up and converted-active queue to owners; work it one-to-one using task/quote context.</li><li>Run converted-dormant reactivation as a separate warm sequence with stop-on-reply.</li><li>Repair the Zoho status picklist and identity links, then re-score the review buckets.</li><li>Only after legal approval and deliverability/source-lineage checks, run one 15–25-contact cold pilot at max 10/day.</li><li>Measure replies, demandes, bounces and opt-outs. Treat opens as diagnostic, never as the conversion goal.</li></ol></section><footer>Internal PII-bearing analysis. No email was sent; no CRM/database record, provider setting, migration, seeder, campaign or suppression was changed.</footer></main>
<script type="application/json" id="summaryData">__SUMMARY_JSON__</script><script>
const r=JSON.parse(document.getElementById('summaryData').textContent),$=id=>document.getElementById(id),t=(tag,val,cls)=>{const e=document.createElement(tag);if(cls)e.className=cls;e.textContent=val??'—';return e};const c=r.source_summary.coverage;$('notice').textContent=r.meta.pii_notice;[['all_leads','Leads analyzed'],['converted','Converted'],['leads_with_direct_activity','Direct activity'],['leads_with_quote_context','Quote context'],['hard_suppressed','Hard suppressed']].forEach(([k,l])=>{const d=t('div','', 'metric');d.append(t('b',Number(c[k]).toLocaleString('fr-FR')));d.append(t('span',l));$('metrics').append(d)});r.categories.forEach(cat=>{const d=t('div','', 'category');d.style.setProperty('--cat',cat.color);d.append(t('b',cat.count));d.append(t('strong',cat.label));d.append(t('small',cat.contactable?'Contact motion':'No-send/review motion'));$('categories').append(d)});
r.top_targets.forEach(l=>{const tr=document.createElement('tr');tr.append(td(l.priority_score));const who=document.createElement('td');who.append(t('strong',l.name));who.append(t('div',l.company,'small'));who.append(t('div',l.email||'No valid email','small'));tr.append(who);const cat=document.createElement('td'),badge=t('span',l.category_label,'badge');const def=r.categories.find(c=>c.key===l.category);badge.style.background=def?.color||'#475569';cat.append(badge);tr.append(cat,td(`${l.status||'[missing]'}${l.converted?' · converted':''}`),td(l.owner),td(l.recommended_when),td((l.why||[]).slice(0,2).join(' ')));$('topTargets').append(tr)});function td(v){const e=document.createElement('td');e.textContent=v??'—';return e}
r.strategies.forEach(s=>{const d=t('article','', 'strategy');d.style.setProperty('--cat',s.color);d.append(t('h3',s.label));d.append(t('div',`${s.count.toLocaleString('fr-FR')} Leads · ${s.type}`,'count'));const dl=document.createElement('dl');[['Trigger',s.trigger],['Goal',s.goal],['Cadence',s.cadence],['Angle',s.angle],['CTA',s.cta],['Exit',s.exit]].forEach(([a,b])=>{dl.append(t('dt',a),t('dd',b))});d.append(dl);if(s.subject)d.append(t('div',`Subject idea: ${s.subject}`,'subject'));$('strategies').append(d)});
r.source_summary.data_quality_findings.forEach(x=>$('quality').append(t('li',x)));const labels={all_leads:'All current Leads',live_leads:'Live Leads',local_mirror_leads:'Local mirror Leads',live_only_new_leads:'New live-only Leads',timeline_events:'Linked timeline events',leads_with_any_timeline:'Leads with history',leads_with_direct_activity:'Leads with direct activity',leads_with_quote_context:'Leads with quote context',leads_with_campaign_touch:'Exact-email campaign matches',converted:'Converted',unconverted:'Unconverted',missing_or_invalid_email:'Missing/invalid email',duplicate_email_records:'Duplicate-email records',hard_suppressed:'Hard suppressed',consumer_domain:'Consumer-domain addresses'};Object.entries(c).forEach(([k,v])=>$('coverage').append(t('li',`${labels[k]||k}: ${Number(v).toLocaleString('fr-FR')}`)));$('reconcile').textContent=`${r.meta.live_count.toLocaleString('fr-FR')} unique live Leads vs ${r.meta.local_mirror_count.toLocaleString('fr-FR')} mirrored Leads; every mirrored ID was present live and ${c.live_only_new_leads} new live Leads were added to this analysis.`;
r.additional_zoho_data.recommended_retrieval_order.forEach(x=>$('retrievalOrder').append(t('li',`${x.data}: ${x.why}`)));r.additional_zoho_data.related_lists_advertised.forEach(x=>{const tr=document.createElement('tr');tr.append(td(x.api_name),td(x.display_label),td(x.module));$('relatedLists').append(tr)});r.additional_zoho_data.additional_fields_advertised.forEach(x=>{const tr=document.createElement('tr');tr.append(td(x.api_name),td(x.field_label),td(x.data_type));$('extraFields').append(tr)});$('zohoCaveat').textContent=r.additional_zoho_data.caveat;
</script></body></html>
HTML;

        return str_replace(
            ['__DATE__', '__DETAIL_FILE__', '__SUMMARY_JSON__'],
            [$date, $detailFile, $json],
            $template,
        );
    }
}
