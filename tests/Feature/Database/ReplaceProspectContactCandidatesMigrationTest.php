<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ReplaceProspectContactCandidatesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createLegacySchema();
    }

    public function test_preflight_blocks_conflicts_then_converts_links_reuses_contacts_and_audits_pii_discards(): void
    {
        $now = now();
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Owner', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Other', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('prospect_batches')->insert([
            'id' => 1,
            'name' => 'Legacy batch',
            'candidate_contacts' => 4,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('prospect_batch_items')->insert([
            'id' => 1,
            'prospect_batch_id' => 1,
            'company_id' => 1,
            'source_metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('contacts')->insert([
            'id' => 1,
            'company_id' => 2,
            'email' => 'conflict@example.test',
            'name' => 'Conflict',
            'source' => 'manual',
            'status' => 'new',
            'legal_basis' => 'unknown',
            'email_kind' => 'role',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertCandidate(1, [
            'company_id' => 1,
            'email' => 'conflict@example.test',
            'normalized_email' => 'conflict@example.test',
        ]);

        $migration = require database_path('migrations/2026_08_13_090000_replace_prospect_contact_candidates_with_import_provenance.php');

        try {
            $migration->up();
            $this->fail('Cross-company ownership must abort before cleanup DDL.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('owned by another company', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('prospect_contact_candidates'));
        $this->assertTrue(Schema::hasColumn('prospect_batches', 'candidate_contacts'));
        $this->assertFalse(Schema::hasColumn('prospect_batches', 'imported_contacts'));
        $this->assertFalse(Schema::hasTable('prospect_batch_contacts'));

        DB::table('prospect_contact_candidates')->delete();
        DB::table('contacts')->delete();
        DB::table('contacts')->insert([
            'id' => 10,
            'company_id' => 1,
            'email' => 'existing@example.test',
            'name' => 'Manual Existing',
            'position' => null,
            'source' => 'manual',
            'status' => 'contacted',
            'legal_basis' => 'legitimate_interest',
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
            'email_verification_checked_at' => '2026-08-13 12:00:00',
            'email_verification_source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('contacts')->insert([
            'id' => 11,
            'company_id' => 2,
            'email' => 'erased@example.test',
            'name' => 'Erased',
            'source' => 'manual',
            'status' => 'new',
            'legal_basis' => 'unknown',
            'email_kind' => 'role',
            'deleted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertCandidate(2, [
            'company_id' => 1,
            'email' => 'new@example.test',
            'normalized_email' => 'new@example.test',
            'name' => 'New Contact',
            'verification_status' => 'invalid',
        ]);
        $this->insertCandidate(3, [
            'company_id' => 1,
            'contact_id' => 10,
            'email' => 'existing@example.test',
            'normalized_email' => 'existing@example.test',
            'name' => 'Provider Name',
            'position' => 'Direction logistique',
            'verification_status' => 'invalid',
            'verification_checked_at' => '2026-08-13 13:00:00',
        ]);
        $this->insertCandidate(4, [
            'company_id' => null,
            'email' => 'orphan-pii@example.test',
            'normalized_email' => 'orphan-pii@example.test',
        ]);
        $this->insertCandidate(5, [
            'company_id' => 1,
            'email' => 'erased@example.test',
            'normalized_email' => 'erased@example.test',
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasTable('prospect_contact_candidates'));
        $this->assertFalse(Schema::hasColumn('prospect_batches', 'candidate_contacts'));
        $this->assertTrue(Schema::hasColumn('prospect_batches', 'imported_contacts'));
        $this->assertTrue(Schema::hasTable('prospect_batch_contacts'));
        $this->assertSame(2, DB::table('prospect_batch_contacts')->count());
        $this->assertSame(2, (int) DB::table('prospect_batches')->where('id', 1)->value('imported_contacts'));

        $existing = DB::table('contacts')->where('id', 10)->first();
        $this->assertSame('contacted', $existing->status);
        $this->assertSame('Manual Existing', $existing->name);
        $this->assertSame('Direction logistique', $existing->position);
        $this->assertSame('valid', $existing->email_verification_status);
        $this->assertSame('manual', $existing->email_verification_source);
        $this->assertTrue(DB::table('contacts')->where('email', 'new@example.test')->exists());
        $this->assertFalse(DB::table('contacts')->where('email', 'orphan-pii@example.test')->exists());

        $metadata = (string) DB::table('prospect_batch_items')->where('id', 1)->value('source_metadata');
        $this->assertSame(1, (int) data_get(json_decode($metadata, true), 'legacy_contact_import_audit.missing_company'));
        $this->assertSame(1, (int) data_get(json_decode($metadata, true), 'legacy_contact_import_audit.privacy_tombstone'));
        $this->assertStringNotContainsString('orphan-pii@example.test', $metadata);
        $this->assertStringNotContainsString('erased@example.test', $metadata);
    }

    public function test_same_count_source_change_after_preflight_is_detected_without_dropping_legacy_rows(): void
    {
        $now = now();
        DB::table('companies')->insert(['id' => 1, 'name' => 'Owner', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('prospect_batches')->insert([
            'id' => 1,
            'name' => 'Legacy batch',
            'candidate_contacts' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('prospect_batch_items')->insert([
            'id' => 1,
            'prospect_batch_id' => 1,
            'company_id' => 1,
            'source_metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertCandidate(1, [
            'company_id' => 1,
            'email' => 'stable@example.test',
            'normalized_email' => 'stable@example.test',
        ]);

        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed): void {
            $statement = strtolower($query->sql.' '.json_encode($query->bindings));
            if ($changed || ! str_contains($statement, 'prospect_batch_contacts')) {
                return;
            }

            $changed = true;
            DB::table('prospect_contact_candidates')->where('id', 1)->update([
                'position' => 'Changed after preflight',
            ]);
        });

        $migration = require database_path('migrations/2026_08_13_090000_replace_prospect_contact_candidates_with_import_provenance.php');

        try {
            $migration->up();
            $this->fail('A same-count source mutation must abort cleanup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('source rows changed after preflight', $exception->getMessage());
        }

        $this->assertTrue($changed);
        $this->assertTrue(Schema::hasTable('prospect_contact_candidates'));
        $this->assertFalse(Schema::hasTable('prospect_contact_candidates_cleanup'));
        $this->assertSame('Changed after preflight', DB::table('prospect_contact_candidates')->value('position'));
        $this->assertDatabaseMissing('contacts', ['email' => 'stable@example.test']);
    }

    private function createLegacySchema(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191)->unique();
            $table->string('name');
            $table->string('position', 120)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('source', 16)->default('manual');
            $table->string('status', 16)->default('new');
            $table->string('legal_basis', 24)->default('unknown');
            $table->string('email_kind', 12)->default('role');
            $table->timestamp('source_captured_at')->nullable();
            $table->string('email_verification_status', 16)->nullable();
            $table->timestamp('email_verification_checked_at')->nullable();
            $table->string('email_verification_source', 32)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('prospect_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('promoted_companies')->default(0);
            $table->unsignedInteger('candidate_contacts')->default(0);
            $table->timestamps();
        });
        Schema::create('prospect_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->json('source_metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('prospect_contact_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_batch_id');
            $table->foreignId('prospect_batch_item_id');
            $table->foreignId('company_id')->nullable();
            $table->foreignId('contact_id')->nullable();
            $table->string('email', 191);
            $table->string('normalized_email', 191);
            $table->string('name')->nullable();
            $table->string('position', 120)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('source', 32)->nullable();
            $table->string('email_kind', 12)->nullable();
            $table->string('verification_status', 16)->nullable();
            $table->timestamp('verification_checked_at')->nullable();
            $table->string('verification_source', 32)->nullable();
            $table->timestamps();
        });
    }

    /** @param array<string, mixed> $overrides */
    private function insertCandidate(int $id, array $overrides): void
    {
        DB::table('prospect_contact_candidates')->insert(array_replace([
            'id' => $id,
            'prospect_batch_id' => 1,
            'prospect_batch_item_id' => 1,
            'company_id' => 1,
            'contact_id' => null,
            'email' => 'candidate'.$id.'@example.test',
            'normalized_email' => 'candidate'.$id.'@example.test',
            'name' => null,
            'position' => null,
            'phone' => null,
            'source' => 'hunter_domain_search',
            'email_kind' => 'role',
            'verification_status' => 'valid',
            'verification_checked_at' => '2026-08-13 10:00:00',
            'verification_source' => 'hunter',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
