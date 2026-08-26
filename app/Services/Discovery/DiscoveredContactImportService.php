<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Services\Prospecting\HunterVerificationStatusNormalizer;
use App\Support\EmailKind;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;

/**
 * Idempotently imports provider contacts. The caller owns the surrounding
 * transaction so provider settlement, Contact writes and provenance are atomic.
 */
final class DiscoveredContactImportService
{
    /** @var list<string> */
    private const VERIFICATION_STATUSES = [
        'valid',
        'accept_all',
        'pending',
        'missing',
        'unknown',
        'invalid',
        'disposable',
        'webmail',
        'manual',
    ];

    public function __construct(
        private readonly HunterVerificationStatusNormalizer $verification,
    ) {}

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function import(
        Company $company,
        iterable $rows,
        ?ProspectBatchItem $item = null,
        string $source = 'discovered',
    ): ContactImportResult {
        $this->assertContext($company, $item);

        $created = 0;
        $updated = 0;
        $linked = 0;
        $skipped = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $this->increment($skipped, 'invalid_row');

                continue;
            }

            $email = $this->email($row['email'] ?? $row['value'] ?? null);
            if ($email === null) {
                $this->increment($skipped, 'invalid_email');

                continue;
            }

            $attributes = $this->attributes($row, $email);
            $contact = Contact::withNormalizedEmail($email)->lockForUpdate()->first();

            if ($contact !== null && $contact->trashed()) {
                $this->increment($skipped, 'tombstoned');

                continue;
            }

            if ($contact !== null && (int) $contact->company_id !== (int) $company->getKey()) {
                $this->increment($skipped, 'owned_by_another_company');

                continue;
            }

            if ($contact === null) {
                try {
                    $contact = Contact::query()->create([
                        'company_id' => $company->getKey(),
                        'email' => $email,
                        'source' => $source,
                        ...$attributes,
                    ]);
                    $created++;
                } catch (QueryException $exception) {
                    if (! $this->isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }

                    $contact = Contact::withNormalizedEmail($email)->lockForUpdate()->first();
                    if ($contact === null) {
                        throw $exception;
                    }
                    if ($contact->trashed()) {
                        $this->increment($skipped, 'tombstoned');

                        continue;
                    }
                    if ((int) $contact->company_id !== (int) $company->getKey()) {
                        $this->increment($skipped, 'owned_by_another_company');

                        continue;
                    }
                }
            }

            if (! $contact->wasRecentlyCreated && $this->updateSafeFields($contact, $attributes)) {
                $updated++;
            }

            if ($item !== null) {
                $provenance = ProspectBatchContact::query()->firstOrCreate(
                    [
                        'prospect_batch_id' => $item->prospect_batch_id,
                        'contact_id' => $contact->getKey(),
                    ],
                    [
                        'prospect_batch_item_id' => $item->getKey(),
                        'provider_source' => $this->source($row['source'] ?? null),
                        'imported_at' => now(),
                    ],
                );

                if ($provenance->wasRecentlyCreated) {
                    $linked++;
                }
            }
        }

        return new ContactImportResult($created, $updated, $linked, $skipped);
    }

    private function assertContext(Company $company, ?ProspectBatchItem $item): void
    {
        if (! $company->exists) {
            throw new InvalidArgumentException('prospect_import_company_not_persisted');
        }
        if ($item === null) {
            return;
        }

        $valid = $item->exists
            && $item->prospect_batch_id !== null
            && (int) $item->company_id === (int) $company->getKey()
            && ProspectBatchItem::query()
                ->whereKey($item->getKey())
                ->where('prospect_batch_id', $item->prospect_batch_id)
                ->where('company_id', $company->getKey())
                ->exists();

        if (! $valid) {
            throw new InvalidArgumentException('prospect_import_item_context_mismatch');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function updateSafeFields(Contact $contact, array $attributes): bool
    {
        $safe = [];

        foreach (['name', 'position', 'phone', 'source_url', 'source_captured_at'] as $field) {
            if (($attributes[$field] ?? null) !== null && blank($contact->getAttribute($field))) {
                $safe[$field] = $attributes[$field];
            }
        }

        if ($this->shouldReplaceEvidence($contact, $attributes)) {
            foreach (['email_verification_status', 'email_verification_source', 'email_verification_checked_at'] as $field) {
                $safe[$field] = $attributes[$field];
            }
        }

        if ($safe === []) {
            return false;
        }

        $contact->forceFill($safe)->save();

        return true;
    }

    /** @param array<string, mixed> $attributes */
    private function shouldReplaceEvidence(Contact $contact, array $attributes): bool
    {
        if (! isset($attributes['email_verification_status'])) {
            return false;
        }

        $incomingSource = strtolower(trim((string) ($attributes['email_verification_source'] ?? '')));
        $existingSource = strtolower(trim((string) $contact->email_verification_source));
        $hasExistingEvidence = filled($contact->email_verification_status)
            || filled($contact->email_verification_source)
            || $contact->email_verification_checked_at !== null;

        if (! $hasExistingEvidence) {
            return true;
        }
        if ($existingSource === 'manual' && $incomingSource !== 'manual') {
            return false;
        }
        if ($incomingSource === 'manual' && $existingSource !== 'manual') {
            return true;
        }

        $incomingAt = $this->date($attributes['email_verification_checked_at'] ?? null);
        $existingAt = $this->date($contact->email_verification_checked_at);

        return $incomingAt !== null && ($existingAt === null || $incomingAt->gt($existingAt));
    }

    private function email(mixed $value): ?string
    {
        $email = strtolower(trim((string) $value));

        if ($email === '' || strlen($email) > 191 || preg_match('/[^\x21-\x7E]/', $email) === 1) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function attributes(array $row, string $email): array
    {
        $name = trim((string) (($row['name'] ?? null)
            ?: trim(((string) ($row['first_name'] ?? '')).' '.((string) ($row['last_name'] ?? '')))));
        $kind = strtolower(trim((string) ($row['email_kind'] ?? '')));

        return [
            'name' => $name !== '' ? mb_substr($name, 0, 255) : (strstr($email, '@', true) ?: $email),
            'position' => $this->bounded($row['position'] ?? null, 120),
            'phone' => $this->bounded($row['phone'] ?? null, 50),
            'email_kind' => in_array($kind, ['role', 'personal'], true) ? $kind : EmailKind::classify($email),
            'source_url' => $this->bounded($row['source_url'] ?? null, 500),
            'source_captured_at' => $this->date($row['source_captured_at'] ?? null) ?? now(),
            ...$this->evidence($row),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function evidence(array $row): array
    {
        $status = $this->verification->normalize($row);
        if ($status === null) {
            $raw = data_get($row, 'verification.status')
                ?? data_get($row, 'verification.result')
                ?? ($row['verification_status'] ?? null);
            $status = $this->verificationStatus($raw);
        }
        if ($status === null) {
            return [];
        }

        $source = strtolower(trim((string) ($row['verification_source'] ?? 'hunter')));
        if ($source === '' || preg_match('/^[a-z0-9_]+$/', $source) !== 1) {
            $source = 'hunter';
        }

        return [
            'email_verification_status' => $status,
            'email_verification_source' => mb_substr($source, 0, 32),
            'email_verification_checked_at' => $this->date($row['verification_checked_at'] ?? null)
                ?? $this->verification->checkedAt($row)
                ?? now(),
        ];
    }

    private function verificationStatus(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $status = match (strtolower(trim($value))) {
            'deliverable' => 'valid',
            'risky' => 'accept_all',
            'undeliverable' => 'invalid',
            default => strtolower(trim($value)),
        };

        return in_array($status, self::VERIFICATION_STATUSES, true) ? $status : null;
    }

    private function source(mixed $value): string
    {
        $source = strtolower(trim((string) $value));

        if ($source === '' || preg_match('/^[a-z0-9_]+$/', $source) !== 1) {
            return 'discovery';
        }

        return mb_substr($source, 0, 32);
    }

    private function bounded(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, int> $counts */
    private function increment(array &$counts, string $reason): void
    {
        $counts[$reason] = ($counts[$reason] ?? 0) + 1;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23505'
            || ($sqlState === '23000' && in_array($driverCode, [19, 1062, 1555, 2067], true));
    }
}
