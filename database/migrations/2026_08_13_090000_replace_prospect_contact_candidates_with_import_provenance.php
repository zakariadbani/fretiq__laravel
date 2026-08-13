<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FROZEN_TABLE = 'prospect_contact_candidates_cleanup';

    private const LEGACY_TABLE = 'prospect_contact_candidates';

    public function up(): void
    {
        // This read-only classification deliberately runs before any DDL. An
        // unexpected ownership or linkage conflict leaves the legacy schema intact.
        $sourceTable = $this->sourceTable();
        $preflight = $this->preflight($sourceTable);

        if (! Schema::hasColumn('prospect_batches', 'imported_contacts')) {
            Schema::table('prospect_batches', function (Blueprint $table): void {
                $table->unsignedInteger('imported_contacts')->default(0)->after('promoted_companies');
            });
        }

        if (! Schema::hasTable('prospect_batch_contacts')) {
            Schema::create('prospect_batch_contacts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('prospect_batch_id')->constrained()->cascadeOnDelete();
                $table->foreignId('prospect_batch_item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
                $table->string('provider_source', 32);
                $table->timestamp('imported_at');
                $table->unique(['prospect_batch_id', 'contact_id'], 'pbc_batch_contact_unique');
                $table->index(['prospect_batch_item_id', 'contact_id'], 'pbc_item_contact_index');
            });
        }

        if ($sourceTable !== null) {
            if ($sourceTable === self::LEGACY_TABLE) {
                Schema::rename(self::LEGACY_TABLE, self::FROZEN_TABLE);

                try {
                    $frozenPreflight = $this->preflight(self::FROZEN_TABLE);
                    if (! hash_equals($preflight['source_fingerprint'], $frozenPreflight['source_fingerprint'])) {
                        throw new RuntimeException('Candidate conversion aborted: source rows changed after preflight.');
                    }
                } catch (Throwable $exception) {
                    Schema::rename(self::FROZEN_TABLE, self::LEGACY_TABLE);

                    throw $exception;
                }

                $sourceTable = self::FROZEN_TABLE;
                $preflight = $frozenPreflight;
            }

            DB::transaction(function () use ($preflight, $sourceTable): void {
                $lockedFingerprint = $this->snapshotFingerprint($sourceTable, true);
                if (! hash_equals($preflight['source_fingerprint'], $lockedFingerprint)) {
                    throw new RuntimeException('Candidate conversion aborted: frozen source rows changed before conversion.');
                }

                $resolved = 0;
                $auditCounts = [];

                foreach ($preflight['entries'] as $entry) {
                    $row = $entry['row'];

                    if ($entry['reason'] !== null) {
                        $itemId = (int) $row->prospect_batch_item_id;
                        $reason = (string) $entry['reason'];
                        $auditCounts[$itemId][$reason] = (int) ($auditCounts[$itemId][$reason] ?? 0) + 1;
                        $resolved++;

                        continue;
                    }

                    $contact = $entry['contact_id'] === null
                        ? DB::table('contacts')->whereRaw('LOWER(email) = ?', [$entry['email']])->lockForUpdate()->first()
                        : DB::table('contacts')->where('id', $entry['contact_id'])->lockForUpdate()->first();

                    if ($contact !== null) {
                        if ((int) $contact->company_id !== (int) $row->company_id || $contact->deleted_at !== null) {
                            throw new RuntimeException('Candidate conversion aborted: contact ownership changed after preflight.');
                        }

                        $this->mergeSafeFields((int) $contact->id, $contact, $row);
                        $contactId = (int) $contact->id;
                    } else {
                        $contactId = DB::table('contacts')->insertGetId([
                            'company_id' => $row->company_id,
                            'email' => $entry['email'],
                            'name' => $this->contactName($row, $entry['email']),
                            'position' => $row->position,
                            'phone' => $row->phone,
                            'source' => 'discovered',
                            'status' => 'new',
                            'legal_basis' => 'legitimate_interest',
                            'email_kind' => in_array($row->email_kind, ['role', 'personal'], true) ? $row->email_kind : 'role',
                            'source_captured_at' => $row->created_at ?: now(),
                            'email_verification_status' => $row->verification_status,
                            'email_verification_checked_at' => $row->verification_checked_at,
                            'email_verification_source' => $row->verification_source,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('prospect_batch_contacts')->insertOrIgnore([
                        'prospect_batch_id' => $row->prospect_batch_id,
                        'prospect_batch_item_id' => $row->prospect_batch_item_id,
                        'contact_id' => $contactId,
                        'provider_source' => $this->providerSource($row->source),
                        'imported_at' => now(),
                    ]);

                    $linked = DB::table('prospect_batch_contacts')
                        ->where('prospect_batch_id', $row->prospect_batch_id)
                        ->where('contact_id', $contactId)
                        ->exists();
                    if (! $linked) {
                        throw new RuntimeException('Candidate conversion aborted: provenance link was not stored.');
                    }

                    $resolved++;
                }

                foreach ($auditCounts as $itemId => $counts) {
                    $this->audit((int) $itemId, $counts);
                }

                if ($resolved !== $preflight['source_count']) {
                    throw new RuntimeException('Candidate conversion aborted: not every source row was resolved or audited.');
                }

                $this->recomputeImportedContacts();
            });

            if (! hash_equals($preflight['source_fingerprint'], $this->snapshotFingerprint($sourceTable))) {
                throw new RuntimeException('Candidate conversion aborted: frozen source rows changed before cleanup.');
            }

            Schema::drop($sourceTable);
        } else {
            $this->recomputeImportedContacts();
        }

        if (Schema::hasColumn('prospect_batches', 'candidate_contacts')) {
            Schema::table('prospect_batches', fn (Blueprint $table) => $table->dropColumn('candidate_contacts'));
        }
    }

    private function sourceTable(): ?string
    {
        $legacy = Schema::hasTable(self::LEGACY_TABLE);
        $frozen = Schema::hasTable(self::FROZEN_TABLE);

        if ($legacy && $frozen) {
            throw new RuntimeException('Candidate conversion aborted: both live and frozen source tables exist.');
        }

        return $legacy ? self::LEGACY_TABLE : ($frozen ? self::FROZEN_TABLE : null);
    }

    /** @return array{source_count:int,source_fingerprint:string,entries:list<array{row:object,email:?string,contact_id:?int,reason:?string}>} */
    private function preflight(?string $sourceTable): array
    {
        if ($sourceTable === null) {
            return ['source_count' => 0, 'source_fingerprint' => hash('sha256', ''), 'entries' => []];
        }

        $sourceCount = DB::table($sourceTable)->count();
        $sourceHash = hash_init('sha256');
        $entries = [];

        foreach (DB::table($sourceTable)->orderBy('id')->get() as $row) {
            $this->hashSourceRow($sourceHash, $row);
            $item = DB::table('prospect_batch_items')->where('id', $row->prospect_batch_item_id)->first();
            if ($item === null || (int) $item->prospect_batch_id !== (int) $row->prospect_batch_id) {
                throw new RuntimeException('Candidate conversion aborted: batch item link is missing or mismatched.');
            }

            if ($row->company_id === null) {
                $entries[] = ['row' => $row, 'email' => null, 'contact_id' => null, 'reason' => 'missing_company'];

                continue;
            }

            $companyExists = DB::table('companies')->where('id', $row->company_id)->exists();
            if (! $companyExists || $item->company_id === null || (int) $item->company_id !== (int) $row->company_id) {
                throw new RuntimeException('Candidate conversion aborted: company link is missing or mismatched.');
            }

            $email = strtolower(trim((string) $row->normalized_email));
            if ($email === '' || strlen($email) > 191 || preg_match('/[^\x21-\x7E]/', $email) === 1 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Candidate conversion aborted: malformed company-linkable email.');
            }

            $contact = $row->contact_id === null
                ? DB::table('contacts')->whereRaw('LOWER(email) = ?', [$email])->first()
                : DB::table('contacts')->where('id', $row->contact_id)->first();
            if ($row->contact_id !== null && ($contact === null || strtolower(trim((string) $contact->email)) !== $email)) {
                throw new RuntimeException('Candidate conversion aborted: linked Contact is missing or has a different email.');
            }
            if ($contact !== null && $contact->deleted_at !== null) {
                $entries[] = ['row' => $row, 'email' => null, 'contact_id' => null, 'reason' => 'privacy_tombstone'];

                continue;
            }
            if ($contact !== null && (int) $contact->company_id !== (int) $row->company_id) {
                throw new RuntimeException('Candidate conversion aborted: email is owned by another company.');
            }

            $entries[] = [
                'row' => $row,
                'email' => $email,
                'contact_id' => $contact?->id,
                'reason' => null,
            ];
        }

        if (count($entries) !== $sourceCount) {
            throw new RuntimeException('Candidate conversion aborted: preflight did not classify every source row.');
        }

        return [
            'source_count' => $sourceCount,
            'source_fingerprint' => hash_final($sourceHash),
            'entries' => $entries,
        ];
    }

    private function mergeSafeFields(int $contactId, object $contact, object $candidate): void
    {
        $updates = [];

        foreach (['name', 'position', 'phone'] as $field) {
            if (blank($contact->{$field}) && filled($candidate->{$field})) {
                $updates[$field] = $candidate->{$field};
            }
        }

        if ($contact->source_captured_at === null && $candidate->created_at !== null) {
            $updates['source_captured_at'] = $candidate->created_at;
        }

        if ($this->shouldReplaceEvidence($contact, $candidate)) {
            $updates['email_verification_status'] = $candidate->verification_status;
            $updates['email_verification_checked_at'] = $candidate->verification_checked_at;
            $updates['email_verification_source'] = $candidate->verification_source;
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            DB::table('contacts')->where('id', $contactId)->update($updates);
        }
    }

    private function shouldReplaceEvidence(object $contact, object $candidate): bool
    {
        if (blank($candidate->verification_status)) {
            return false;
        }

        $existingSource = strtolower(trim((string) $contact->email_verification_source));
        $incomingSource = strtolower(trim((string) $candidate->verification_source));
        $hasExisting = filled($contact->email_verification_status)
            || filled($contact->email_verification_source)
            || $contact->email_verification_checked_at !== null;

        if (! $hasExisting) {
            return true;
        }
        if ($existingSource === 'manual' && $incomingSource !== 'manual') {
            return false;
        }
        if ($incomingSource === 'manual' && $existingSource !== 'manual') {
            return true;
        }
        if ($candidate->verification_checked_at === null) {
            return false;
        }

        return $contact->email_verification_checked_at === null
            || strtotime((string) $candidate->verification_checked_at) > strtotime((string) $contact->email_verification_checked_at);
    }

    /** @param array<string, int> $reasonCounts */
    private function audit(int $itemId, array $reasonCounts): void
    {
        $item = DB::table('prospect_batch_items')->where('id', $itemId)->lockForUpdate()->first();
        if ($item === null) {
            throw new RuntimeException('Candidate conversion aborted: audit item is missing.');
        }

        $metadata = json_decode($item->source_metadata ?: '{}', true) ?: [];
        $audit = is_array($metadata['legacy_contact_import_audit'] ?? null)
            ? $metadata['legacy_contact_import_audit']
            : [];
        foreach ($reasonCounts as $reason => $count) {
            $audit[$reason] = $count;
        }
        $metadata['legacy_contact_import_audit'] = $audit;

        DB::table('prospect_batch_items')->where('id', $itemId)->update([
            'source_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function recomputeImportedContacts(): void
    {
        DB::table('prospect_batches')->update([
            'imported_contacts' => DB::raw('(SELECT COUNT(*) FROM prospect_batch_contacts WHERE prospect_batch_contacts.prospect_batch_id = prospect_batches.id)'),
        ]);
    }

    private function snapshotFingerprint(string $sourceTable, bool $lock = false): string
    {
        $query = DB::table($sourceTable)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $sourceHash = hash_init('sha256');
        foreach ($query->get() as $row) {
            $this->hashSourceRow($sourceHash, $row);
        }

        return hash_final($sourceHash);
    }

    /** @param resource|\HashContext $sourceHash */
    private function hashSourceRow(mixed $sourceHash, object $row): void
    {
        $json = json_encode((array) $row, JSON_THROW_ON_ERROR);
        hash_update($sourceHash, strlen($json).':'.$json.';');
    }

    private function contactName(object $row, string $email): string
    {
        $name = trim((string) $row->name);

        return $name !== '' ? mb_substr($name, 0, 255) : (strstr($email, '@', true) ?: $email);
    }

    private function providerSource(mixed $value): string
    {
        $source = strtolower(trim((string) $value));

        return $source !== '' && preg_match('/^[a-z0-9_]+$/', $source) === 1
            ? mb_substr($source, 0, 32)
            : 'legacy_candidate';
    }

    public function down(): void
    {
        throw new RuntimeException('This forward privacy cleanup is not losslessly reversible; restore a database backup to recover candidate staging data.');
    }
};
