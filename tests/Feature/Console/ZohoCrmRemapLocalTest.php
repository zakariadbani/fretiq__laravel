<?php

namespace Tests\Feature\Console;

use App\Models\Zoho\ZohoActivity;
use App\Models\Zoho\ZohoAccount;
use App\Models\Zoho\ZohoDealStageHistory;
use App\Models\Zoho\ZohoLead;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\Sync\ZohoLocalMirrorRemapper;
use App\Services\Zoho\V2\Sync\ZohoMirrorMutationGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ZohoCrmRemapLocalTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_persists_explicit_false_and_zero_when_promoted_fields_are_null(): void
    {
        $lead = ZohoLead::query()->create([
            'zoho_id' => 'lead-explicit-false',
            'email_opt_out' => null,
            'raw_payload' => ['id' => 'lead-explicit-false', 'Email_Opt_Out' => false],
            'payload_hash' => hash('sha256', 'lead-explicit-false'),
        ]);
        $history = ZohoDealStageHistory::query()->create([
            'zoho_id' => 'history-explicit-zero',
            'deal_zoho_id' => 'deal-explicit-zero',
            'stage_duration_days' => null,
            'raw_payload' => ['id' => 'history-explicit-zero', 'Stage_Duration_Calendar_Days' => 0],
            'payload_hash' => hash('sha256', 'history-explicit-zero'),
        ]);

        $this->artisan('zoho:crm:remap-local', [
            '--module' => ['leads', 'deal_history'],
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply complete: scanned 2, changed 2, unchanged 0, failed 0.')
            ->assertSuccessful();

        $this->assertFalse($lead->fresh()->email_opt_out);
        $this->assertSame(0, $history->fresh()->stage_duration_days);
    }

    public function test_apply_promotes_verified_boundary_length_text_fields_without_failures(): void
    {
        $leadIndustry = str_repeat('L', 255);
        $accountIndustry = str_repeat('A', 255);
        $dimensions = str_repeat('D', 255);
        $freeTime = str_repeat('F', 198);

        $lead = ZohoLead::query()->create([
            'zoho_id' => 'lead-boundary-fields',
            'raw_payload' => ['id' => 'lead-boundary-fields', 'Secteur_Activit' => $leadIndustry],
            'payload_hash' => hash('sha256', 'lead-boundary-fields'),
        ]);
        $account = ZohoAccount::query()->create([
            'zoho_id' => 'account-boundary-fields',
            'raw_payload' => ['id' => 'account-boundary-fields', 'Secteur_Activit' => $accountIndustry],
            'payload_hash' => hash('sha256', 'account-boundary-fields'),
        ]);
        $quote = ZohoQuote::query()->create([
            'zoho_id' => 'quote-boundary-fields',
            'raw_payload' => [
                'id' => 'quote-boundary-fields',
                'Dimensions_CM' => $dimensions,
                'Franchise' => $freeTime,
            ],
            'payload_hash' => hash('sha256', 'quote-boundary-fields'),
        ]);

        $this->artisan('zoho:crm:remap-local', [
            '--module' => ['leads', 'accounts', 'quotes'],
            '--apply' => true,
        ])
            ->expectsOutputToContain('Apply complete: scanned 3, changed 3, unchanged 0, failed 0.')
            ->assertSuccessful();

        $this->assertSame($leadIndustry, $lead->fresh()->industry);
        $this->assertSame($accountIndustry, $account->fresh()->industry);
        $this->assertSame($dimensions, $quote->fresh()->dimensions);
        $this->assertSame($freeTime, $quote->fresh()->free_time);
    }

    public function test_activity_remap_scopes_the_shared_table_to_the_requested_submodule(): void
    {
        $this->activity('task', 'task-1', [
            'id' => 'task-1',
            'Subject' => 'Correct task subject',
            'Status' => 'Completed',
            'Due_Date' => '2026-08-12T09:00:00+00:00',
        ], 'Stale task subject');
        $this->activity('call', 'call-1', [
            'id' => 'call-1',
            'Subject' => 'Call subject',
            'Outgoing_Call_Status' => 'Completed',
            'Call_Start_Time' => '2026-08-13T09:00:00+00:00',
        ], 'Call subject');

        $result = app(ZohoLocalMirrorRemapper::class)->remap('tasks', 10, false);

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['changed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('Call subject', ZohoActivity::where('activity_type', 'call')->sole()->subject);
    }

    public function test_apply_repairs_only_promoted_fields_from_local_raw_payload_and_is_idempotent(): void
    {
        $raw = [
            'id' => 'lead-local-1',
            'Phone' => '+212500000001',
            'Mobile' => '+212600000001',
            'Secteur_Activit' => 'Logistique',
            'Email_Opt_Out' => 'false',
            'Tag' => [['name' => 'Priority']],
            'Last_Activity_Time' => '2026-08-07T14:30:00+00:00',
        ];
        $lead = ZohoLead::query()->create([
            'zoho_id' => 'lead-local-1',
            'phone' => '+212-old',
            'mobile' => '+212-old-mobile',
            'industry' => 'Old sector',
            'email_opt_out' => true,
            'tags' => ['Old'],
            'last_activity_at' => '2026-08-01 09:00:00',
            'raw_payload' => $raw,
            'payload_hash' => hash('sha256', 'original-payload-hash'),
            'field_schema_hash' => hash('sha256', 'original-schema-hash'),
            'last_seen_at' => '2026-08-08 10:00:00',
            'last_synced_at' => '2026-08-08 10:05:00',
            'zoho_deleted_at' => '2026-08-08 11:00:00',
            'zoho_deletion_type' => 'deleted',
            'sync_batch_id' => 987,
            'created_at' => '2026-08-08 09:00:00',
            'updated_at' => '2026-08-08 09:30:00',
        ]);
        $preserved = $lead->only([
            'raw_payload', 'payload_hash', 'field_schema_hash', 'last_seen_at', 'last_synced_at',
            'zoho_deleted_at', 'zoho_deletion_type', 'sync_batch_id', 'created_at', 'updated_at',
        ]);

        $this->app->bind(ZohoTransport::class, fn () => throw new RuntimeException('Zoho transport was resolved.'));

        $this->artisan('zoho:crm:remap-local', ['--module' => ['Leads'], '--chunk' => 1, '--apply' => true])
            ->expectsOutputToContain('leads: scanned 1, changed 1, unchanged 0, failed 0')
            ->expectsOutputToContain('field phone: 1')
            ->assertSuccessful();

        $lead->refresh();
        $this->assertSame('+212500000001', $lead->phone);
        $this->assertSame('+212600000001', $lead->mobile);
        $this->assertSame('Logistique', $lead->industry);
        $this->assertFalse($lead->email_opt_out);
        $this->assertSame(['Priority'], $lead->tags);
        $this->assertSame('2026-08-07 14:30:00', $lead->last_activity_at?->toDateTimeString());
        foreach ($preserved as $field => $value) {
            $this->assertEquals($value, $lead->getAttribute($field), $field);
        }

        $this->artisan('zoho:crm:remap-local', ['--module' => ['leads']])
            ->expectsOutputToContain('leads: scanned 1, changed 0, unchanged 1, failed 0')
            ->assertSuccessful();
    }

    public function test_apply_refuses_a_batch_with_active_post_reconciliation(): void
    {
        ZohoSyncBatch::query()->create([
            'correlation_id' => 'post-reconciliation-active',
            'mode' => 'delta',
            'status' => 'completed',
            'completed_at' => now(),
            'post_reconciliation_status' => 'running',
        ]);

        $this->artisan('zoho:crm:remap-local', ['--module' => ['leads'], '--apply' => true])
            ->expectsOutputToContain('A Zoho sync or post-reconciliation batch is still active.')
            ->assertFailed();
    }

    public function test_mapping_failures_are_reported_without_raw_payload_content(): void
    {
        $lead = ZohoLead::query()->create([
            'zoho_id' => 'lead-malformed',
            'raw_payload' => ['private_note' => 'DO-NOT-PRINT'],
            'payload_hash' => hash('sha256', 'malformed'),
            'last_seen_at' => '2026-08-09 10:00:00',
        ]);

        $exit = Artisan::call('zoho:crm:remap-local', ['--module' => ['leads']]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("leads: local row {$lead->id} could not be mapped.", $output);
        $this->assertStringNotContainsString('DO-NOT-PRINT', $output);
        $this->assertStringNotContainsString('private_note', $output);
    }

    public function test_apply_stops_before_the_next_write_when_its_shared_lease_is_reclaimed_mid_remap(): void
    {
        $first = ZohoLead::query()->create([
            'zoho_id' => 'lead-lease-first',
            'phone' => '+212-old-first',
            'raw_payload' => ['id' => 'lead-lease-first', 'Phone' => '+212-new-first'],
            'payload_hash' => hash('sha256', 'lead-lease-first'),
            'last_seen_at' => '2026-08-09 10:00:00',
        ]);
        $second = ZohoLead::query()->create([
            'zoho_id' => 'lead-lease-second',
            'phone' => '+212-old-second',
            'raw_payload' => ['id' => 'lead-lease-second', 'Phone' => '+212-new-second'],
            'payload_hash' => hash('sha256', 'lead-lease-second'),
            'last_seen_at' => '2026-08-09 10:00:00',
        ]);
        $reclaimed = false;

        DB::listen(function (QueryExecuted $query) use (&$reclaimed): void {
            if ($reclaimed || ! str_contains(strtolower($query->sql), 'update') || ! str_contains($query->sql, 'zoho_leads')) {
                return;
            }

            $reclaimed = true;
            ZohoSyncCheckpoint::query()
                ->where('module', 'v2:leads')
                ->update([
                    'generation' => DB::raw('generation + 1'),
                    'lease_owner' => 'reclaimed-by-sync',
                    'lease_expires_at' => now()->addMinute(),
                ]);
        });

        $this->artisan('zoho:crm:remap-local', ['--module' => ['leads'], '--chunk' => 2, '--apply' => true])
            ->expectsOutputToContain('The shared mirror mutation lease was reclaimed.')
            ->assertFailed();

        $this->assertTrue($reclaimed);
        $this->assertSame('+212-new-first', $first->fresh()->phone);
        $this->assertSame('+212-old-second', $second->fresh()->phone);
    }

    public function test_mutation_guard_locks_an_existing_owner_batch_before_its_checkpoint(): void
    {
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'completed-owner-lock-order',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'success',
            'modules' => ['leads'],
            'requested_at' => now()->subHour(),
            'completed_at' => now()->subMinute(),
        ]);
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:leads',
            'submodule' => '',
            'sync_mode' => 'delta',
            'status' => 'completed',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'generation' => 2,
            'completed_at' => now()->subMinute(),
        ]);
        $lockOrder = [];
        DB::listen(function (QueryExecuted $query) use (&$lockOrder): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($sql, 'zoho_sync_batches')) {
                $lockOrder[] = 'batch';
            } elseif (str_contains($sql, 'zoho_sync_checkpoints')) {
                $lockOrder[] = 'checkpoint';
            }
        });

        $guard = app(ZohoMirrorMutationGuard::class);
        $lease = $guard->claim('leads', 'lock-order-test');

        $this->assertNotNull($lease);
        $this->assertSame(['batch', 'checkpoint'], $lockOrder);
        $guard->release($lease);
    }

    private function activity(string $type, string $zohoId, array $raw, string $subject): ZohoActivity
    {
        return ZohoActivity::query()->create([
            'activity_type' => $type,
            'zoho_id' => $zohoId,
            'subject' => $subject,
            'status' => $type === 'call' ? 'Completed' : 'Open',
            'raw_payload' => $raw,
            'payload_hash' => hash('sha256', $zohoId),
            'last_seen_at' => '2026-08-09 10:00:00',
            'last_synced_at' => '2026-08-09 10:05:00',
        ]);
    }
}
