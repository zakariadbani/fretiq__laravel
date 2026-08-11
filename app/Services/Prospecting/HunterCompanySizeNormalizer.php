<?php

namespace App\Services\Prospecting;

final class HunterCompanySizeNormalizer
{
    public function normalize(mixed $employees): ?string
    {
        if (is_int($employees) || is_float($employees)) {
            if ((is_float($employees) && (! is_finite($employees) || floor($employees) !== $employees))
                || $employees < 1) {
                return null;
            }

            $count = (int) $employees;

            return match (true) {
                $count <= 10 => '1-10',
                $count <= 50 => '11-50',
                $count <= 200 => '51-200',
                $count <= 500 => '201-500',
                default => '500+',
            };
        }

        if (! is_string($employees)) {
            return null;
        }

        $value = preg_replace('/[\p{Z}\s]+/u', '', trim($employees));

        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(strtr($value, [
            "\u{2010}" => '-',
            "\u{2011}" => '-',
            "\u{2012}" => '-',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2015}" => '-',
            "\u{2212}" => '-',
        ]));

        return match ($value) {
            '1-10' => '1-10',
            '11-50' => '11-50',
            '51-250' => '51-200',
            '251-1K' => '201-500',
            '1K-5K', '5K-10K', '10K-50K', '50K-100K', '100K+' => '500+',
            default => null,
        };
    }
}
