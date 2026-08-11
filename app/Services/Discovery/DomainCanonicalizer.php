<?php

namespace App\Services\Discovery;

use Pdp\CannotProcessHost;
use Pdp\Domain;
use Pdp\Rules;

final class DomainCanonicalizer
{
    /** @var list<string> */
    private array $platformDomains;

    /**
     * @param  list<string>|null  $platformDomains
     */
    public function __construct(
        private readonly Rules $rules,
        ?array $platformDomains = null,
    ) {
        $this->platformDomains = array_values(array_unique(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(rtrim(trim((string) $domain), '.')),
            $platformDomains ?? config('prospecting.platform_domains', []),
        ))));
    }

    public function canonicalize(string $value): ?CanonicalDomain
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $isBareHost = false;

        if (str_starts_with($value, '//')) {
            $url = 'https:'.$value;
        } elseif (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1) {
            $url = $value;
        } else {
            $isBareHost = true;
            $url = 'https://'.$value;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ! isset($parts['scheme'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || ($isBareHost && (isset($parts['port']) || strpbrk($value, '/?#@') !== false))) {
            return null;
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        try {
            $ascii = Domain::fromIDNA2008(rtrim(mb_strtolower($host), '.'))->toAscii();
            $resolved = $this->rules->getCookieDomain($ascii);
            $canonicalHost = (string) preg_replace('/^www\./', '', $resolved->domain()->toString());
            $registrableDomain = $resolved->registrableDomain()->toString();
        } catch (CannotProcessHost) {
            return null;
        }

        if (! $resolved->suffix()->isKnown() || $canonicalHost === '' || $registrableDomain === '') {
            return null;
        }

        return new CanonicalDomain($canonicalHost, $registrableDomain, $this->isPlatform($canonicalHost));
    }

    public function isPlatform(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $host = (string) preg_replace('/^www\./', '', $host);

        if ($host === '') {
            return false;
        }

        foreach ($this->platformDomains as $platformDomain) {
            if ($host === $platformDomain || str_ends_with($host, '.'.$platformDomain)) {
                return true;
            }
        }

        return false;
    }
}
