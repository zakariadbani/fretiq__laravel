<?php

namespace App\Services\Zoho\V2\Marketing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/** Immutable reporting window. Dates are always interpreted in the business timezone. */
final readonly class MarketingPeriod
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public CarbonImmutable $previousStartsAt,
        public CarbonImmutable $previousEndsAt,
        public string $timezone = 'Europe/Paris',
    ) {}

    /** @param array{preset?: string, from?: string|null, to?: string|null} $input */
    public static function fromInput(array $input = [], ?CarbonInterface $now = null): self
    {
        $timezone = 'Europe/Paris';
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($timezone);
        $preset = $input['preset'] ?? '30d';

        if ($preset === 'custom') {
            if (blank($input['from'] ?? null) || blank($input['to'] ?? null)) {
                throw new InvalidArgumentException('Une période personnalisée exige une date de début et de fin.');
            }

            $start = self::exactDate((string) $input['from'], $timezone)->startOfDay();
            $end = self::exactDate((string) $input['to'], $timezone)->endOfDay();
            if ($end->lessThan($start)) {
                throw new InvalidArgumentException('La date de fin doit être postérieure à la date de début.');
            }
        } else {
            [$start, $end] = match ($preset) {
                '7d' => [$now->startOfDay()->subDays(6), $now->endOfDay()],
                '90d' => [$now->startOfDay()->subDays(89), $now->endOfDay()],
                '365d' => [$now->startOfDay()->subDays(364), $now->endOfDay()],
                'qtd' => [$now->firstOfQuarter()->startOfDay(), $now->endOfDay()],
                'ytd' => [$now->startOfYear()->startOfDay(), $now->endOfDay()],
                '30d' => [$now->startOfDay()->subDays(29), $now->endOfDay()],
                default => throw new InvalidArgumentException('Période inconnue.'),
            };
        }

        $days = $start->diffInDays($end->startOfDay()) + 1;
        $previousEnd = $start->subMicrosecond();

        return new self($start, $end, $previousEnd->subDays($days - 1)->startOfDay(), $previousEnd);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'from' => $this->startsAt->toDateString(), 'to' => $this->endsAt->toDateString(),
            'previous_from' => $this->previousStartsAt->toDateString(), 'previous_to' => $this->previousEndsAt->toDateString(),
            'timezone' => $this->timezone,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC instants for a UTC database. */
    public function databaseBounds(bool $previous = false): array
    {
        return [
            ($previous ? $this->previousStartsAt : $this->startsAt)->utc(),
            ($previous ? $this->previousEndsAt : $this->endsAt)->utc(),
        ];
    }

    private static function exactDate(string $value, string $timezone): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Les dates doivent respecter le format AAAA-MM-JJ.');
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('La période contient une date calendrier invalide.');
        }

        return $date;
    }
}
