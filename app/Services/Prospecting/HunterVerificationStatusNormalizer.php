<?php

namespace App\Services\Prospecting;

use Carbon\CarbonImmutable;
use Throwable;

final class HunterVerificationStatusNormalizer
{
    /**
     * Normalize Hunter's current status field or, only when it is blank,
     * its legacy result field into Fretiq's allowlisted vocabulary.
     *
     * @param  array<string, mixed>  $emailData
     */
    public function normalize(array $emailData): ?string
    {
        $status = data_get($emailData, 'verification.status');
        $raw = $this->isBlank($status)
            ? data_get($emailData, 'verification.result')
            : $status;

        if (! is_string($raw)) {
            return null;
        }

        return match (strtolower(trim($raw))) {
            'valid', 'deliverable' => 'valid',
            'accept_all', 'risky' => 'accept_all',
            'invalid', 'undeliverable' => 'invalid',
            'unknown' => 'unknown',
            'disposable' => 'disposable',
            'webmail' => 'webmail',
            default => null,
        };
    }

    /**
     * Parse Hunter's evidence timestamp without allowing malformed provider
     * payloads to interrupt contact enrichment.
     *
     * @param  array<string, mixed>  $emailData
     */
    public function checkedAt(array $emailData): ?CarbonImmutable
    {
        $value = data_get($emailData, 'verification.date');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
