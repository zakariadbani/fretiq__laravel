<?php

namespace Tests\Feature\Services\Prospecting;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DiscoveryRun;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use App\Services\Prospecting\ExistingProspectingDataRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExistingProspectingDataRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    public function test_preview_scans_payloads_and_unconsumed_failed_snapshots_without_writes(): void
    {
        $company = Company::factory()->create([
            'name' => 'Payload Transport',
            'domain' => 'payload.fr',
            'phone' => null,
            'estimated_size' => null,
            'enrichment_attempted_at' => '2026-08-02 12:00:00',
            'enrichment_data' => [
                'company' => [
                    'phone' => '+33102030405',
                    'metrics' => ['employees' => 175],
                    'site' => [
                        'emailAddresses' => [
                            ' Sales@Payload.fr ',
                            'ops@payload.fr',
                            ['value' => ' Billing@Payload.fr '],
                            ['email' => 'OPS@PAYLOAD.FR'],
                            'not-an-email',
                        ],
                    ],
                ],
                'domain_search' => [
                    'emails' => [[
                        'value' => 'known@payload.fr',
                        'verification' => [
                            'status' => 'valid',
                            'date' => '2026-08-01T10:30:00Z',
                        ],
                    ]],
                ],
            ],
        ]);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'known@payload.fr',
            'email_verification_status' => null,
            'email_verification_checked_at' => null,
            'email_verification_source' => null,
        ]);
        $run = $this->createRun([
            'status' => 'failed',
            'consumed' => 1,
            'candidates_snapshot' => [
                ['domain' => 'consumed.fr', 'title' => 'Consumed'],
                [
                    'domain' => 'review-domain.fr',
                    'title' => 'Review Domain',
                    'snippet' => 'Local evidence that must not leak into metadata.',
                    'phone' => '+33111111111',
                    'sector_hint' => 'Transport',
                ],
            ],
        ]);
        $before = $this->databaseCounts();
        $payload = (string) DB::table('companies')->where('id', $company->id)->value('enrichment_data');
        $snapshot = (string) DB::table('discovery_runs')->where('id', $run->id)->value('candidates_snapshot');

        $first = app(ExistingProspectingDataRecoveryService::class)->preview();
        $second = app(ExistingProspectingDataRecoveryService::class)->preview();

        $this->assertSame(1, $first->counts['verification_statuses']);
        $this->assertSame(3, $first->counts['company_enrichment_emails']);
        $this->assertSame(1, $first->counts['failed_snapshot_domains']);
        $this->assertSame(1, $first->counts['hunter_phones']);
        $this->assertSame(1, $first->counts['hunter_sizes']);
        $this->assertSame(
            ['billing@payload.fr', 'ops@payload.fr', 'sales@payload.fr'],
            array_column($first->discoveredContacts, 'email'),
        );
        $sizeChange = collect($first->companyChanges)->firstWhere('field', 'estimated_size');
        $this->assertSame($company->id, $sizeChange['company_id']);
        $this->assertSame('51-200', $sizeChange['new']);
        $this->assertSame($first->fingerprint, $second->fingerprint);
        $this->assertSame($first->verificationChanges, $second->verificationChanges);
        $this->assertSame($first->companyChanges, $second->companyChanges);
        $this->assertSame($first->discoveredContacts, $second->discoveredContacts);
        $this->assertSame($first->snapshotItems, $second->snapshotItems);
        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame($payload, (string) DB::table('companies')->where('id', $company->id)->value('enrichment_data'));
        $this->assertSame($snapshot, (string) DB::table('discovery_runs')->where('id', $run->id)->value('candidates_snapshot'));
        $this->assertNull($contact->fresh()->email_verification_status);
    }

    public function test_apply_stages_review_rows_safe_fills_and_encrypted_audit(): void
    {
        $company = Company::factory()->create([
            'name' => 'payload.fr',
            'domain' => 'payload.fr',
            'sector' => null,
            'phone' => null,
            'estimated_size' => null,
            'enrichment_data' => [
                'company' => [
                    'phone' => '+33102030405',
                    'metrics' => ['employees' => '51-250'],
                    'site' => ['emailAddresses' => [' Sales@Payload.fr ']],
                ],
                'domain_search' => ['emails' => [[
                    'value' => 'known@payload.fr',
                    'verification' => [
                        'status' => 'valid',
                        'date' => '2026-08-01T10:30:00Z',
                    ],
                ]]],
            ],
        ]);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'known@payload.fr',
            'email_verification_status' => null,
            'email_verification_checked_at' => null,
            'email_verification_source' => null,
        ]);
        $run = $this->createRun([
            'status' => 'failed',
            'consumed' => 0,
            'candidates_snapshot' => [
                [
                    'domain' => 'payload.fr',
                    'title' => 'Payload Logistics',
                    'sector_hint' => 'Transport',
                ],
                [
                    'domain' => 'review-domain.fr',
                    'title' => 'Review Domain',
                    'snippet' => 'This raw evidence must remain immutable.',
                ],
            ],
        ]);
        $payloadHash = hash('sha256', (string) DB::table('companies')->where('id', $company->id)->value('enrichment_data'));
        $snapshotHash = hash('sha256', (string) DB::table('discovery_runs')->where('id', $run->id)->value('candidates_snapshot'));
        $companyCount = Company::withRejected()->count();
        $contactCount = Contact::withTrashed()->count();

        $service = app(ExistingProspectingDataRecoveryService::class);
        $result = $service->apply($service->preview());

        $this->assertFalse($result->reused);
        $this->assertSame('recovery', $result->batch->source_type);
        $this->assertSame(['provider_calls' => 0, 'provider_units' => 0], $result->batch->estimate);
        $this->assertDatabaseHas('prospect_batch_items', [
            'prospect_batch_id' => $result->batch->id,
            'selected_domain' => 'review-domain.fr',
            'domain_reason' => 'recovered_failed_snapshot',
            'status' => 'review',
            'company_id' => null,
        ]);
        $this->assertDatabaseHas('contacts', [
            'company_id' => $company->id,
            'email' => 'sales@payload.fr',
            'source' => 'discovered',
            'status' => 'new',
        ]);
        $importedContactId = Contact::query()->where('email', 'sales@payload.fr')->value('id');
        $this->assertDatabaseHas('prospect_batch_contacts', [
            'prospect_batch_id' => $result->batch->id,
            'contact_id' => $importedContactId,
            'provider_source' => 'hunter_company_enrichment',
        ]);

        $freshCompany = $company->fresh();
        $freshContact = $contact->fresh();
        $this->assertSame('+33102030405', $freshCompany->phone);
        $this->assertSame('51-200', $freshCompany->estimated_size);
        $this->assertSame('Transport', $freshCompany->sector);
        $this->assertSame('Payload Logistics', $freshCompany->name);
        $this->assertSame('valid', $freshContact->email_verification_status);
        $this->assertSame('2026-08-01 10:30:00', $freshContact->email_verification_checked_at?->format('Y-m-d H:i:s'));
        $this->assertSame('hunter', $freshContact->email_verification_source);
        $this->assertSame($payloadHash, hash('sha256', (string) DB::table('companies')->where('id', $company->id)->value('enrichment_data')));
        $this->assertSame($snapshotHash, hash('sha256', (string) DB::table('discovery_runs')->where('id', $run->id)->value('candidates_snapshot')));
        $this->assertSame($companyCount, Company::withRejected()->count());
        $this->assertSame($contactCount + 1, Contact::withTrashed()->count());
        $this->assertDatabaseMissing('companies', ['domain' => 'review-domain.fr']);
        $this->assertSame(0, ProviderCall::query()->count());

        $rawAudit = (string) DB::table('prospect_batches')
            ->where('id', $result->batch->id)
            ->value('recovery_audit');
        $this->assertStringNotContainsString('+33102030405', $rawAudit);
        $this->assertStringNotContainsString('Payload Logistics', $rawAudit);
        $auditChanges = $result->batch->fresh()->recovery_audit['changes'];
        $phoneAudit = collect($auditChanges)->firstWhere('field', 'phone');
        $this->assertSame('+33102030405', $phoneAudit['new'] ?? null);
        $this->assertSame('company', $phoneAudit['entity_type'] ?? null);
        $this->assertSame(2, $result->batch->fresh()->total_items);
        $this->assertSame(1, $result->batch->fresh()->review_items);
        $this->assertSame(1, $result->batch->fresh()->imported_contacts);
    }

    public function test_replay_keeps_one_fingerprint_and_rechecks_blank_targets_before_updates(): void
    {
        $firstCompany = Company::factory()->create([
            'name' => 'First Company',
            'domain' => 'first-company.fr',
            'qualification_status' => 'rejected',
            'phone' => null,
            'estimated_size' => '11-50',
            'sector' => 'Existing sector',
            'enrichment_attempted_at' => '2026-07-31 09:15:00',
            'enrichment_data' => [
                'company' => [
                    'phone' => '+33111111111',
                    'metrics' => ['employees' => '51-250'],
                    'site' => ['emailAddresses' => [
                        'duplicate@shared.fr',
                        'deleted@shared.fr',
                    ]],
                ],
                'domain_search' => ['emails' => [
                    [
                        'value' => 'fallback@first-company.fr',
                        'verification' => ['result' => 'risky'],
                    ],
                    [
                        'value' => 'stale@first-company.fr',
                        'verification' => ['status' => 'valid'],
                    ],
                ]],
            ],
        ]);
        $secondCompany = Company::factory()->create([
            'name' => 'Second Company',
            'domain' => 'second-company.fr',
            'enrichment_data' => [
                'company' => [
                    'site' => ['emailAddresses' => ['DUPLICATE@SHARED.FR']],
                ],
            ],
        ]);
        $fallbackContact = Contact::factory()->create([
            'company_id' => $firstCompany->id,
            'email' => 'fallback@first-company.fr',
            'email_verification_status' => null,
            'email_verification_checked_at' => null,
            'email_verification_source' => null,
        ]);
        $staleContact = Contact::factory()->create([
            'company_id' => $firstCompany->id,
            'email' => 'stale@first-company.fr',
            'email_verification_status' => null,
        ]);
        $deletedContact = Contact::factory()->create([
            'company_id' => $secondCompany->id,
            'email' => 'deleted@shared.fr',
        ]);
        $deletedContact->delete();
        $this->createRun([
            'status' => 'completed',
            'candidates_snapshot' => [[
                'domain' => 'first-company.fr',
                'title' => 'Must Not Replace',
                'phone' => '+33999999999',
                'sector_hint' => 'Must Not Replace',
            ]],
        ]);

        $service = app(ExistingProspectingDataRecoveryService::class);
        $plan = $service->preview();
        $this->assertSame(3, $plan->counts['company_enrichment_emails']);
        $this->assertSame($firstCompany->id, $plan->discoveredContacts[0]['company_id']);
        $this->assertSame('accept_all', $plan->verificationChanges[0]['new']);

        $firstCompany->forceFill(['phone' => '+33000000000'])->save();
        $staleContact->forceFill(['email_verification_status' => 'unknown'])->save();
        $first = $service->apply($plan);
        $afterApplyPlan = $service->preview();
        $itemCount = ProspectBatchItem::query()->count();
        $provenanceCount = ProspectBatchContact::query()->count();
        $second = $service->apply($afterApplyPlan);

        $this->assertSame($plan->fingerprint, $afterApplyPlan->fingerprint);
        $this->assertSame($first->batch->id, $second->batch->id);
        $this->assertTrue($second->reused);
        $this->assertSame(1, ProspectBatch::query()->where('source_type', 'recovery')->count());
        $this->assertSame($itemCount, ProspectBatchItem::query()->count());
        $this->assertSame($provenanceCount, ProspectBatchContact::query()->count());
        $this->assertSame('+33000000000', $firstCompany->fresh()->phone);
        $this->assertSame('11-50', $firstCompany->fresh()->estimated_size);
        $this->assertSame('Existing sector', $firstCompany->fresh()->sector);
        $this->assertSame('First Company', $firstCompany->fresh()->name);
        $this->assertSame('unknown', $staleContact->fresh()->email_verification_status);
        $this->assertSame('accept_all', $fallbackContact->fresh()->email_verification_status);
        $this->assertSame(
            '2026-07-31 09:15:00',
            $fallbackContact->fresh()->email_verification_checked_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame('hunter', $fallbackContact->fresh()->email_verification_source);
        $duplicateContactId = Contact::query()->where('email', 'duplicate@shared.fr')->value('id');
        $this->assertDatabaseHas('prospect_batch_contacts', [
            'prospect_batch_id' => $first->batch->id,
            'contact_id' => $duplicateContactId,
        ]);
        $deletedContactId = Contact::withTrashed()->where('email', 'deleted@shared.fr')->value('id');
        $this->assertDatabaseMissing('prospect_batch_contacts', [
            'prospect_batch_id' => $first->batch->id,
            'contact_id' => $deletedContactId,
        ]);
        $this->assertSame(0, ProviderCall::query()->count());
    }

    public function test_failed_snapshot_domains_exclude_unsafe_rows_and_keep_collisions_and_all_sources_in_review(): void
    {
        $exact = Company::factory()->create([
            'name' => 'Exact',
            'domain' => 'exact-domain.fr',
            'qualification_status' => 'rejected',
        ]);
        $parent = Company::factory()->create([
            'name' => 'Existing tenant',
            'domain' => 'tenant.example.fr',
        ]);
        $firstRun = $this->createRun([
            'status' => 'failed',
            'consumed' => 1,
            'candidates_snapshot' => [
                ['domain' => 'already-consumed.fr', 'title' => 'Consumed'],
                ['domain' => 'not a domain', 'title' => 'Invalid'],
                ['domain' => 'facebook.com', 'title' => 'Platform'],
                ['domain' => 'exact-domain.fr', 'title' => 'Exact'],
                ['domain' => 'api.example.fr', 'title' => 'Collision', 'snippet' => 'private raw'],
                ['domain' => 'fresh-domain.fr', 'title' => 'Fresh', 'phone' => '+33123456789'],
            ],
        ]);
        $secondRun = $this->createRun([
            'status' => 'failed',
            'consumed' => 0,
            'candidates_snapshot' => [
                ['domain' => 'https://api.example.fr', 'title' => 'Duplicate collision'],
                ['domain' => 'fresh-domain.fr', 'title' => 'Duplicate fresh'],
            ],
        ]);
        $this->createRun([
            'status' => 'completed',
            'consumed' => 0,
            'candidates_snapshot' => [[
                'domain' => 'completed-only.fr',
                'title' => 'Completed only',
            ]],
        ]);

        $service = app(ExistingProspectingDataRecoveryService::class);
        $plan = $service->preview();

        $this->assertSame(2, $plan->counts['failed_snapshot_domains']);
        $this->assertSame(['api.example.fr', 'fresh-domain.fr'], array_column($plan->snapshotItems, 'host'));
        $collision = collect($plan->snapshotItems)->firstWhere('host', 'api.example.fr');
        $this->assertSame('recovered_registrable_collision', $collision['domain_reason']);
        $this->assertSame([$parent->id], $collision['registrable_collision']['company_ids']);
        $this->assertSame(['tenant.example.fr'], $collision['registrable_collision']['hosts']);
        $this->assertSame([
            ['run_id' => $firstRun->id, 'snapshot_index' => 4],
            ['run_id' => $secondRun->id, 'snapshot_index' => 0],
        ], $collision['sources']);

        $result = $service->apply($plan);
        $collisionItem = ProspectBatchItem::query()
            ->where('prospect_batch_id', $result->batch->id)
            ->where('selected_domain', 'api.example.fr')
            ->firstOrFail();
        $this->assertSame('review', $collisionItem->status);
        $this->assertSame('recovered_registrable_collision', $collisionItem->domain_reason);
        $this->assertSame([$parent->id], data_get(
            $collisionItem->source_metadata,
            'registrable_collision.company_ids',
        ));
        $this->assertSame(2, count($collisionItem->source_metadata['sources']));
        $this->assertArrayNotHasKey('snippet', $collisionItem->source_metadata);
        $this->assertDatabaseMissing('prospect_batch_items', [
            'prospect_batch_id' => $result->batch->id,
            'selected_domain' => 'already-consumed.fr',
        ]);
        $this->assertDatabaseMissing('prospect_batch_items', [
            'prospect_batch_id' => $result->batch->id,
            'selected_domain' => 'facebook.com',
        ]);
        $this->assertDatabaseMissing('prospect_batch_items', [
            'prospect_batch_id' => $result->batch->id,
            'selected_domain' => $exact->domain,
        ]);
        $this->assertDatabaseMissing('prospect_batch_items', [
            'prospect_batch_id' => $result->batch->id,
            'selected_domain' => 'completed-only.fr',
        ]);
        $this->assertDatabaseMissing('companies', ['domain' => 'api.example.fr']);
        $this->assertDatabaseMissing('companies', ['domain' => 'fresh-domain.fr']);
        $this->assertSame(0, ProviderCall::query()->count());
    }

    public function test_synthetic_manifest_stages_1253_emails_and_40_domains(): void
    {
        $emailNumber = 0;

        for ($companyNumber = 1; $companyNumber <= 48; $companyNumber++) {
            $companyEmails = [];
            $emailCount = $companyNumber <= 5 ? 27 : 26;

            for ($index = 0; $index < $emailCount; $index++) {
                $emailNumber++;
                $companyEmails[] = sprintf(
                    'role-%04d@company-%02d.fr',
                    $emailNumber,
                    $companyNumber,
                );
            }

            Company::factory()->create([
                'name' => sprintf('Synthetic Company %02d', $companyNumber),
                'domain' => sprintf('company-%02d.fr', $companyNumber),
                'enrichment_data' => [
                    'company' => [
                        'site' => ['emailAddresses' => $companyEmails],
                    ],
                ],
            ]);
        }

        $domainNumber = 0;

        for ($runNumber = 1; $runNumber <= 7; $runNumber++) {
            $snapshot = [];
            $domainCount = $runNumber <= 5 ? 6 : 5;

            for ($index = 0; $index < $domainCount; $index++) {
                $domainNumber++;
                $snapshot[] = [
                    'domain' => sprintf('recovered-domain-%02d.fr', $domainNumber),
                    'title' => sprintf('Recovered Domain %02d', $domainNumber),
                ];
            }

            $this->createRun([
                'status' => 'failed',
                'consumed' => 0,
                'candidates_snapshot' => $snapshot,
            ]);
        }

        $service = app(ExistingProspectingDataRecoveryService::class);
        $plan = $service->preview();

        $this->assertSame(1253, $emailNumber);
        $this->assertSame(40, $domainNumber);
        $this->assertSame(7, DiscoveryRun::query()->count());
        $this->assertSame(1253, $plan->counts['company_enrichment_emails']);
        $this->assertSame(40, $plan->counts['failed_snapshot_domains']);

        $result = $service->apply($plan);

        $this->assertSame(1253, $result->batch->importedContacts()->count());
        $this->assertSame(40, $result->batch->items()
            ->where('domain_reason', 'recovered_failed_snapshot')
            ->count());
        $this->assertSame(0, Company::withRejected()
            ->where('domain', 'like', 'recovered-domain-%')
            ->count());
        $this->assertSame(0, ProviderCall::query()->count());
    }

    public function test_verification_requires_a_blank_same_company_contact_and_uses_updated_at_as_last_evidence_fallback(): void
    {
        $company = Company::factory()->create([
            'name' => 'Evidence Company',
            'domain' => 'evidence-company.fr',
            'enrichment_attempted_at' => null,
            'enrichment_data' => [
                'domain_search' => ['emails' => [
                    [
                        'value' => 'blank@evidence-company.fr',
                        'verification' => ['status' => 'valid'],
                    ],
                    [
                        'value' => 'already@evidence-company.fr',
                        'verification' => ['status' => 'invalid'],
                    ],
                    [
                        'value' => 'belongs-elsewhere@evidence-company.fr',
                        'verification' => ['status' => 'valid'],
                    ],
                ]],
            ],
        ]);
        DB::table('companies')->where('id', $company->id)->update([
            'updated_at' => '2026-07-30 08:00:00',
        ]);
        $blank = Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'blank@evidence-company.fr',
            'email_verification_status' => null,
        ]);
        $already = Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'already@evidence-company.fr',
            'email_verification_status' => 'accept_all',
        ]);
        $otherCompany = Company::factory()->create([
            'name' => 'Other Company',
            'domain' => 'other-company.fr',
        ]);
        $elsewhere = Contact::factory()->create([
            'company_id' => $otherCompany->id,
            'email' => 'belongs-elsewhere@evidence-company.fr',
            'email_verification_status' => null,
        ]);

        $plan = app(ExistingProspectingDataRecoveryService::class)->preview();

        $this->assertSame(1, $plan->counts['verification_statuses']);
        $this->assertSame($blank->id, $plan->verificationChanges[0]['contact_id']);
        $this->assertSame('valid', $plan->verificationChanges[0]['new']);
        $this->assertSame('2026-07-30T08:00:00.000000Z', $plan->verificationChanges[0]['checked_at']);
        $this->assertSame('accept_all', $already->fresh()->email_verification_status);
        $this->assertNull($elsewhere->fresh()->email_verification_status);
    }

    public function test_all_snapshot_bearing_runs_can_fill_safe_existing_fields_but_only_failed_runs_stage_domains(): void
    {
        $company = Company::factory()->create([
            'name' => 'snapshot-fill.fr',
            'domain' => 'snapshot-fill.fr',
            'phone' => null,
            'sector' => null,
            'enrichment_data' => null,
        ]);
        $this->createRun([
            'status' => 'completed',
            'consumed' => 0,
            'candidates_snapshot' => [
                [
                    'domain' => 'snapshot-fill.fr',
                    'title' => 'Snapshot Fill',
                    'phone' => '+33101010101',
                    'sector_hint' => 'Logistique',
                ],
                [
                    'domain' => 'completed-new-domain.fr',
                    'title' => 'Completed New Domain',
                ],
            ],
        ]);

        $service = app(ExistingProspectingDataRecoveryService::class);
        $plan = $service->preview();

        $this->assertSame(1, $plan->counts['snapshot_phones']);
        $this->assertSame(1, $plan->counts['snapshot_sectors']);
        $this->assertSame(1, $plan->counts['snapshot_names']);
        $this->assertSame(0, $plan->counts['failed_snapshot_domains']);

        $service->apply($plan);

        $this->assertSame('+33101010101', $company->fresh()->phone);
        $this->assertSame('Logistique', $company->fresh()->sector);
        $this->assertSame('Snapshot Fill', $company->fresh()->name);
        $this->assertDatabaseMissing('prospect_batch_items', [
            'selected_domain' => 'completed-new-domain.fr',
        ]);
    }

    public function test_insert_or_ignore_race_winner_is_completed_once_and_then_reused(): void
    {
        Company::factory()->create([
            'name' => 'Race Company',
            'domain' => 'race-company.fr',
            'enrichment_data' => [
                'company' => [
                    'site' => ['emailAddresses' => ['sales@race-company.fr']],
                ],
            ],
        ]);
        $service = app(ExistingProspectingDataRecoveryService::class);
        $plan = $service->preview();
        $winner = ProspectBatch::factory()->create([
            'source_type' => 'recovery',
            'source_fingerprint' => $plan->fingerprint,
            'created_by' => null,
            'status' => 'running',
            'recovery_audit' => null,
        ]);

        $first = $service->apply($plan);
        $second = $service->apply($plan);

        $this->assertSame($winner->id, $first->batch->id);
        $this->assertFalse($first->reused);
        $this->assertSame($winner->id, $second->batch->id);
        $this->assertTrue($second->reused);
        $this->assertSame(1, ProspectBatch::query()->where('source_fingerprint', $plan->fingerprint)->count());
        $this->assertSame(1, ProspectBatchContact::query()->count());
        $this->assertSame(0, ProviderCall::query()->count());
    }

    /** @param array<string, mixed> $overrides */
    private function createRun(array $overrides = []): DiscoveryRun
    {
        return DiscoveryRun::query()->create(array_replace([
            'prospect_criteria_id' => null,
            'type' => 'discovery',
            'company_id' => null,
            'status' => 'failed',
            'consumed' => 0,
            'candidates_snapshot' => [],
        ], $overrides));
    }

    /** @return array<string, int> */
    private function databaseCounts(): array
    {
        return [
            'companies' => Company::withRejected()->count(),
            'contacts' => Contact::withTrashed()->count(),
            'discovery_runs' => DiscoveryRun::query()->count(),
            'prospect_batches' => ProspectBatch::query()->count(),
            'prospect_batch_items' => ProspectBatchItem::query()->count(),
            'prospect_batch_contacts' => ProspectBatchContact::query()->count(),
            'provider_calls' => ProviderCall::query()->count(),
        ];
    }
}
