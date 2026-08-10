<?php

namespace App\Services\Zoho\V2\Mappers;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

abstract class AbstractZohoMapper implements ZohoRecordMapper
{
    /** @param array<string, mixed> $payload @param array<string, mixed> $context */
    protected function base(array $payload, array $context): array
    {
        $id = $payload['id'] ?? null;

        if ($id === null || $id === '') {
            throw new InvalidArgumentException('A Zoho payload must contain a non-empty id.');
        }

        $seenAt = $this->timestamp($context['seen_at'] ?? null);
        $syncedAt = $this->timestamp($context['synced_at'] ?? $context['seen_at'] ?? null);

        if ($seenAt === null || $syncedAt === null) {
            throw new InvalidArgumentException('Mapper context must include seen_at or synced_at.');
        }

        return [
            'zoho_id' => (string) $id,
            'owner_zoho_id' => $this->lookupId($payload['Owner'] ?? null),
            'parent_zoho_id' => $this->lookupId($payload['Parent_Id'] ?? $payload['What_Id'] ?? null),
            'zoho_created_at' => $this->timestamp($payload['Created_Time'] ?? null),
            'zoho_modified_at' => $this->timestamp($payload['Modified_Time'] ?? null),
            'last_seen_at' => $seenAt,
            'last_synced_at' => $syncedAt,
            'zoho_deleted_at' => null,
            'zoho_deletion_type' => null,
            'payload_hash' => $this->payloadHash($payload),
            'field_schema_hash' => $context['schema_hash'] ?? null,
            'raw_payload' => $payload,
            'sync_batch_id' => $context['sync_batch_id'] ?? null,
        ];
    }

    protected function lookupId(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    protected function value(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    protected function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return match ($value) {
                0 => false, 1 => true, default => null
            };
        }
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '0', 'false' => false, '1', 'true' => true, default => null
        };
    }

    /** @return list<string>|null */
    protected function stringList(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $items = is_array($value) && ! array_is_list($value) ? [$value] : (is_array($value) ? $value : [$value]);
        $result = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? $item['display_value'] ?? $item['actual_value'] ?? null;
            }
            if (! is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $result[$item] = $item;
            }
        }

        return $result === [] ? null : array_values($result);
    }

    protected function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    protected function normalizedEmail(mixed $value): ?string
    {
        $value = $this->value($value);

        return is_string($value) ? strtolower(trim($value)) : null;
    }

    protected function weightedAmount(mixed $amount, mixed $probability): ?string
    {
        $amount = $this->decimal($amount, true);
        $probability = $this->decimal($probability, true);

        if ($amount === null || $probability === null) {
            return null;
        }

        return number_format(((float) $amount * (float) $probability) / 100, 2, '.', '');
    }

    /**
     * Normalizes only unambiguous decimal representations returned by Zoho.
     */
    public function decimal(mixed $value, bool $machineNumeric = false): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $this->normalizeNumeric(sprintf('%.14F', $value)) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $value));
        if ($value === '') {
            return null;
        }

        $currency = '(?:EUR|USD|MAD|€|\\$)';
        if (preg_match('/^'.$currency.'\\s*(.+)$/iu', $value, $matches) === 1) {
            $value = $matches[1];
        } elseif (preg_match('/^(.+?)\\s*'.$currency.'$/iu', $value, $matches) === 1) {
            $value = $matches[1];
        }

        $value = trim($value);
        if (preg_match('/^[+-]?\\d+(?:[.,]\\d+)?$/', $value) === 1) {
            return $this->normalizeSingleSeparator($value, $machineNumeric);
        }

        foreach ([
            '/^([+-]?\\d{1,3}(?: \\d{3})+)([,.]\\d{1,6})$/',
            '/^([+-]?\\d{1,3}(?:\\.\\d{3})+)(,\\d{1,6})$/',
            '/^([+-]?\\d{1,3}(?:,\\d{3})+)(\\.\\d{1,6})$/',
        ] as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                $integer = str_replace([' ', ',', '.'], '', $matches[1]);

                return $this->normalizeNumeric($integer.'.'.substr($matches[2], 1));
            }
        }

        if (preg_match('/^[+-]?\\d{1,3}(?:[ ,. ]\\d{3})+$/', $value) === 1) {
            return $this->normalizeNumeric(str_replace([' ', ',', '.'], '', $value));
        }

        return null;
    }

    protected function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $payload */
    public function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function normalizeSingleSeparator(string $value, bool $machineNumeric): ?string
    {
        $separatorCount = substr_count($value, ',') + substr_count($value, '.');
        if ($separatorCount === 0) {
            return $this->normalizeNumeric($value);
        }

        $separator = str_contains($value, ',') ? ',' : '.';
        [, $fraction] = explode($separator, $value, 2);
        $integer = ltrim(explode($separator, $value, 2)[0], '+-');

        if (! $machineNumeric && strlen($fraction) === 3 && strlen($integer) <= 3) {
            return null;
        }

        return $this->normalizeNumeric(str_replace($separator, '.', $value));
    }

    private function normalizeNumeric(string $value): ?string
    {
        if (! preg_match('/^[+-]?\\d+(?:\\.\\d+)?$/', $value)) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $number = $integer.($fraction === '' ? '' : '.'.$fraction);

        return $negative && $number !== '0' ? '-'.$number : $number;
    }
}
