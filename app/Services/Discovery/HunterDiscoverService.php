<?php

namespace App\Services\Discovery;

use App\Models\ProspectCriteria;
use Illuminate\Support\Facades\Http;

class HunterDiscoverService
{
    public function __construct(private readonly CompanyDiscoveryService $domains = new CompanyDiscoveryService) {}

    public function preview(ProspectCriteria $criteria, ?string $target, ?string $exclude): array
    {
        $prompt = $this->buildPrompt($criteria, $target, $exclude);

        if (config('services.hunter.driver', 'local') === 'local') {
            return ['ok' => true, 'prompt' => $prompt, 'companies' => $this->localCompanies()];
        }

        $key = (string) config('services.hunter.api_key');
        if ($key === '') {
            return ['ok' => false, 'prompt' => $prompt, 'companies' => [], 'error' => 'Discover IA n’est pas configuré.', 'status' => 503];
        }

        try {
            $response = Http::withToken($key)->acceptJson()->asJson()
                ->timeout((int) config('services.hunter.discover_timeout', 20))
                ->post('https://api.hunter.io/v2/discover', ['query' => $prompt]);
        } catch (\Throwable) {
            return ['ok' => false, 'prompt' => $prompt, 'companies' => [], 'error' => 'Discover IA est temporairement indisponible.', 'status' => 503];
        }

        if (! $response->successful()) {
            [$message, $status] = match ($response->status()) {
                401, 403 => ['Discover IA n’est pas configuré ou votre offre n’y donne pas accès.', 503],
                400, 422 => ['Discover IA n’a pas compris cette recherche. Reformulez la cible.', 422],
                429 => ['L’allocation Discover IA est épuisée ou temporairement limitée.', 429],
                default => $response->serverError()
                    ? ['Discover IA est temporairement indisponible.', 503]
                    : ['Discover IA n’a pas pu traiter cette recherche.', 502],
            };

            return ['ok' => false, 'prompt' => $prompt, 'companies' => [], 'error' => $message, 'status' => $status];
        }

        return ['ok' => true, 'prompt' => $prompt, 'companies' => $this->normalize($response->json('data', []))];
    }

    public function buildPrompt(ProspectCriteria $criteria, ?string $target, ?string $exclude): string
    {
        $parts = [];
        if ($target) $parts[] = 'Cible: '.$target.'.';
        if ($exclude) $parts[] = 'Exclure: '.$exclude.'.';

        foreach (['Secteurs' => $criteria->sectors, 'Pays' => $criteria->countries, 'Tailles' => $criteria->company_sizes] as $label => $values) {
            $values = array_values(array_filter((array) $values, fn ($value) => is_scalar($value) && trim((string) $value) !== ''));
            if ($values) $parts[] = $label.': '.implode(', ', $values).'.';
        }

        return implode(' ', $parts);
    }

    public function normalize(mixed $rows): array
    {
        if (! is_array($rows)) return [];
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            $rawDomain = strtolower(trim((string) ($row['domain'] ?? '')));
            $domain = $rawDomain === '' ? null : $this->domains->extractDomain('https://'.$rawDomain);
            if (! $domain || isset($normalized[$domain])) continue;

            $counts = is_array($row['emails_count'] ?? null) ? $row['emails_count'] : [];
            $personal = max(0, (int) ($counts['personal'] ?? 0));
            $generic = max(0, (int) ($counts['generic'] ?? 0));
            $name = trim((string) ($row['organization'] ?? ''));
            $normalized[$domain] = [
                'domain' => $domain,
                'organization' => $name !== '' ? $name : null,
                'emails_count' => ['personal' => $personal, 'generic' => $generic, 'total' => max($personal + $generic, (int) ($counts['total'] ?? 0))],
            ];
        }

        return array_values($normalized);
    }

    private function localCompanies(): array
    {
        $path = database_path('fixtures/discovery/hunter.json');
        $fixtures = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $rows = [];

        foreach (is_array($fixtures) ? $fixtures : [] as $domain => $fixture) {
            if ($domain === '__default__' || ! is_array($fixture)) continue;
            $personal = $generic = 0;
            foreach ((array) ($fixture['emails'] ?? []) as $email) {
                if (($email['type'] ?? null) === 'personal') $personal++;
                elseif (($email['type'] ?? null) === 'generic') $generic++;
            }
            $rows[] = ['domain' => $domain, 'organization' => $fixture['organization'] ?? null, 'emails_count' => ['personal' => $personal, 'generic' => $generic, 'total' => $personal + $generic]];
        }

        return $this->normalize($rows);
    }
}
