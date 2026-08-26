<?php

namespace App\Support;

use Illuminate\Support\Str;
use Locale;

/**
 * Resolves free-text country input to the canonical uppercase ISO-3166-1
 * alpha-2 code stored on companies.country, trying in order:
 *   1. an ISO2 code already present in config('global.data.company_countries')
 *   2. the French label from that same config (its stored display value)
 *   3. the English display name (ICU data via ext-intl — Hunter exports
 *      country names in English, e.g. "Morocco")
 *
 * Single shared implementation — CompanyListParser and HunterCsvImportService
 * both delegate here instead of keeping their own copy of this loop.
 */
final class CountryResolver
{
    /** @var array<string,string>|null ISO2 => normalized English name token, built once per process */
    private static ?array $englishTokens = null;

    public static function resolve(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $countries = config('global.data.company_countries', []);
        $code = strtoupper($value);

        if (strlen($code) === 2 && array_key_exists($code, $countries)) {
            return $code;
        }

        $needle = self::token($value);

        foreach ($countries as $countryCode => $label) {
            if ($needle === self::token((string) $label)) {
                return strtoupper((string) $countryCode);
            }
        }

        foreach (self::englishTokens($countries) as $countryCode => $englishToken) {
            if ($needle === $englishToken) {
                return $countryCode;
            }
        }

        return null;
    }

    /**
     * @param  array<string,string>  $countries
     * @return array<string,string> ISO2 => normalized English name token
     */
    private static function englishTokens(array $countries): array
    {
        if (self::$englishTokens !== null) {
            return self::$englishTokens;
        }

        $map = [];
        foreach (array_keys($countries) as $countryCode) {
            $countryCode = strtoupper((string) $countryCode);
            $english = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$countryCode, 'en') : $countryCode;
            $map[$countryCode] = self::token((string) $english);
        }

        return self::$englishTokens = $map;
    }

    private static function token(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($value)));
    }
}
