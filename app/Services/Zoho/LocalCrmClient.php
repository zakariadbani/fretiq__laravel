<?php

namespace App\Services\Zoho;

/**
 * LocalCrmClient — fixture-backed stub for local development and unit tests.
 *
 * Returns static JSON fixture data from database/fixtures/zoho/{accounts,contacts}.json.
 * No HTTP calls are made. Active when config('services.zoho.crm_driver') === 'local'.
 *
 * Fixture shapes match the empirically-verified Zoho CRM v2 response format:
 *   - Account_Name is a plain string on Accounts.
 *   - Account_Name is a lookup object {name, id} on Contacts.
 */
class LocalCrmClient implements CrmClient
{
    /** @var array<string, array> In-memory fixture cache */
    private array $cache = [];

    /**
     * {@inheritdoc}
     */
    public function get(string $module, array $query = []): array
    {
        $normalized = strtolower($module);

        $fixtureMap = [
            'accounts' => 'accounts',
            'contacts' => 'contacts',
        ];

        if (! isset($fixtureMap[$normalized])) {
            return ['data' => [], 'info' => ['count' => 0, 'more_records' => false, 'page' => 1, 'per_page' => 200]];
        }

        $key = $fixtureMap[$normalized];

        if (! isset($this->cache[$key])) {
            $path = base_path("database/fixtures/zoho/{$key}.json");

            if (! file_exists($path)) {
                throw new \RuntimeException("[LocalCrmClient] Fixture file not found: {$path}");
            }

            $raw = file_get_contents($path);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $this->cache[$key] = $decoded;
        }

        return $this->cache[$key];
    }
}
