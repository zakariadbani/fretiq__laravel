<?php

namespace App\Services\Translation;

/**
 * LanguageResolver — maps a company country code to a campaign language.
 *
 * Rules:
 *   - null / empty / whitespace-only country  → default_language (fr)
 *   - Country in francophone_countries list    → base_language (fr)
 *   - Any other non-empty code                → first target_language (en)
 *
 * Country codes are uppercased before comparison so 'de', 'DE', 'De'
 * all produce the same result.
 */
class LanguageResolver
{
    /**
     * Resolve the email language for a contact's company country.
     *
     * @param  string|null $country ISO-3166-1 alpha-2 code (or null/empty when unknown)
     * @return string               BCP-47 / ISO-639-1 language code
     */
    public function forCountry(?string $country): string
    {
        $c = strtoupper(trim((string) $country));

        if ($c === '') {
            return config('translation.default_language', 'fr');
        }

        if (in_array($c, config('translation.francophone_countries', ['FR']), true)) {
            return config('translation.base_language', 'fr');
        }

        // Any other non-empty country code → first configured target language
        $targets = config('translation.target_languages', ['en']);

        return $targets[0] ?? config('translation.default_language', 'fr');
    }
}
