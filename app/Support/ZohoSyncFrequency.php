<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;

class ZohoSyncFrequency
{
    public static function values(): array
    {
        return ['every_15_minutes', 'every_30_minutes', 'hourly', 'every_2_hours', 'every_6_hours', 'daily'];
    }

    public static function options(): array
    {
        return [
            'every_15_minutes' => 'Toutes les 15 minutes',
            'every_30_minutes' => 'Toutes les 30 minutes',
            'hourly' => 'Toutes les heures',
            'every_2_hours' => 'Toutes les 2 heures',
            'every_6_hours' => 'Toutes les 6 heures',
            'daily' => 'Tous les jours',
        ];
    }

    public static function normalize(mixed $value): string
    {
        return is_string($value) && in_array($value, self::values(), true) ? $value : 'hourly';
    }

    public static function apply(Event $event, mixed $frequency): Event
    {
        return match (self::normalize($frequency)) {
            'every_15_minutes' => $event->everyFifteenMinutes(),
            'every_30_minutes' => $event->everyThirtyMinutes(),
            'hourly' => $event->hourlyAt(10),
            'every_2_hours' => $event->everyTwoHours(),
            'every_6_hours' => $event->everySixHours(),
            'daily' => $event->dailyAt('02:10'),
        };
    }
}
