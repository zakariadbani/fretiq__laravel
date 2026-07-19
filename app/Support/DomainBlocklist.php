<?php

namespace App\Support;

use App\Models\Setting;

/**
 * DomainBlocklist — the list of domains and file extensions excluded from discovery.
 *
 * Source of truth is the admin Settings UI (Découverte tab):
 *   - decouverte.blocked_domains        (textarea, one domain per line)
 *   - decouverte.blocked_url_extensions (comma-separated list)
 *
 * The DEFAULT_* constants below are only a fallback/seed: they are used when the
 * setting row is missing or blank, and they seed the textarea on the settings page.
 * Editing the list is an admin operation — do NOT move it to .env or config/.
 *
 * Matching semantics (identical to the former CompanyDiscoveryService::isBlockedDomain):
 * exact match OR subdomain suffix, so `gouv.fr` blocks `www.douane.gouv.fr` but
 * never `gouv.fr.example.com` or `notlinkedin.com`.
 */
class DomainBlocklist
{
    /**
     * Built-in default blocklist. Used when the setting is empty.
     *
     * @var list<string>
     */
    public const DEFAULT_DOMAINS = [
        // ── Réseaux sociaux ──────────────────────────────────────────────────
        'linkedin.com',
        'facebook.com',
        'instagram.com',
        'x.com',
        'twitter.com',
        'youtube.com',
        'tiktok.com',
        'pinterest.com',

        // ── Médias / presse ──────────────────────────────────────────────────
        'leparisien.fr',
        'lemonde.fr',
        'lefigaro.fr',
        'lesechos.fr',
        'journaldunet.com',
        'usinenouvelle.com',
        'telquel.ma',
        'laquotidienne.ma',
        'lematin.ma',
        'leconomiste.com',
        'medias24.com',
        'hespress.com',
        'challenge.ma',
        'lavieeco.com',

        // ── Annuaires ────────────────────────────────────────────────────────
        'pagesjaunes.fr',
        'societe.com',
        'verif.com',
        'infogreffe.fr',
        'kompass.com',
        'europages.fr',
        'espaceagro.com',
        'charika.ma',
        'telecontact.ma',
        'yellowpages.ma',
        'alibaba.com',
        'made-in-china.com',
        'accio.com',

        // ── Institutionnel / gouvernement ────────────────────────────────────
        'gouv.fr',
        'bodacc.fr',
        'insee.fr',
        'ammc.ma',
        'publications.gc.ca',
        'europa.eu',
        'oecd.org',
        'worldbank.org',
        'un.org',
        'cci.fr',
        'businessfrance.fr',
        'wikipedia.org',

        // ── Documents / contenu ──────────────────────────────────────────────
        'scribd.com',
        'slideshare.net',
        'calameo.com',
        'academia.edu',
        'researchgate.net',
        'issuu.com',

        // ── Emploi / profils ─────────────────────────────────────────────────
        'indeed.com',
        'indeed.fr',
        'viadeo.com',
        'glassdoor.fr',
        'monster.fr',
        'rekrute.com',
        'emploi.ma',
        'welcometothejungle.com',

        // ── Académique ───────────────────────────────────────────────────────
        'supagro.fr',
        'cnrs.fr',
    ];

    /**
     * Built-in default file extensions. A SERP result whose URL path ends with one
     * of these is a document, not a company site.
     *
     * @var list<string>
     */
    public const DEFAULT_EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx'];

    /** Memo key = the raw setting string, so a changed setting invalidates naturally. */
    private ?string $domainsMemoKey = null;

    /** @var list<string>|null */
    private ?array $domainsMemo = null;

    private ?string $extensionsMemoKey = null;

    /** @var list<string>|null */
    private ?array $extensionsMemo = null;

    // ── Seed helpers (settings UI) ───────────────────────────────────────────────

    /**
     * DEFAULT_DOMAINS as newline-separated text — seeds the settings textarea.
     */
    public static function defaultDomainsText(): string
    {
        return implode("\n", self::DEFAULT_DOMAINS);
    }

    /**
     * DEFAULT_EXTENSIONS as a comma-separated string — seeds the settings text input.
     */
    public static function defaultExtensionsText(): string
    {
        return implode(',', self::DEFAULT_EXTENSIONS);
    }

    // ── Effective lists ──────────────────────────────────────────────────────────

    /**
     * Effective blocked domains: the admin setting when set, else DEFAULT_DOMAINS.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        $raw = $this->rawSetting('decouverte.blocked_domains');

        if ($this->domainsMemo !== null && $this->domainsMemoKey === $raw) {
            return $this->domainsMemo;
        }

        $parsed = $raw === null ? [] : $this->parseDomains($raw);

        $this->domainsMemoKey = $raw;
        $this->domainsMemo    = $parsed === [] ? self::DEFAULT_DOMAINS : $parsed;

        return $this->domainsMemo;
    }

    /**
     * Effective blocked file extensions: the admin setting when set, else DEFAULT_EXTENSIONS.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        $raw = $this->rawSetting('decouverte.blocked_url_extensions');

        if ($this->extensionsMemo !== null && $this->extensionsMemoKey === $raw) {
            return $this->extensionsMemo;
        }

        $parsed = $raw === null ? [] : $this->parseExtensions($raw);

        $this->extensionsMemoKey = $raw;
        $this->extensionsMemo    = $parsed === [] ? self::DEFAULT_EXTENSIONS : $parsed;

        return $this->extensionsMemo;
    }

    // ── Matching ─────────────────────────────────────────────────────────────────

    /**
     * True when the host is blocked — exact match or subdomain of a blocked domain.
     */
    public function isBlocked(string $domain): bool
    {
        $domain = $this->normaliseHost($domain);

        if ($domain === '') {
            return false;
        }

        foreach ($this->domains() as $blocked) {
            if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the URL path points at a blocked document type (query string ignored).
     */
    public function isBlockedUrl(string $url): bool
    {
        $path = parse_url(trim($url), PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return false;
        }

        $path = strtolower($path);

        foreach ($this->extensions() as $extension) {
            if (str_ends_with($path, '.' . $extension)) {
                return true;
            }
        }

        return false;
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /**
     * Read a stored setting, returning null unless it is a non-empty string.
     * Never throws when the settings table is missing — SettingService guards that.
     */
    private function rawSetting(string $key): ?string
    {
        $value = Setting::get($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Split on newlines AND commas; trim, lowercase, drop `www.`, blanks and `#` comments.
     *
     * @return list<string>
     */
    private function parseDomains(string $raw): array
    {
        $lines = preg_split('/[\r\n,]+/', $raw) ?: [];
        $domains = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $domain = $this->normaliseHost($line);

            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }

    /**
     * Split on commas, newlines and spaces; strip leading dots, lowercase.
     *
     * @return list<string>
     */
    private function parseExtensions(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $extensions = [];

        foreach ($parts as $part) {
            $part = strtolower(ltrim(trim($part), '.'));

            if ($part === '' || str_starts_with($part, '#')) {
                continue;
            }

            $extensions[$part] = true;
        }

        return array_keys($extensions);
    }

    private function normaliseHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));

        return (string) preg_replace('/^www\./', '', $host);
    }
}
