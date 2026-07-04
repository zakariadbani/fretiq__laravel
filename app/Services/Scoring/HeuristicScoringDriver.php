<?php

namespace App\Services\Scoring;

use App\Models\ProspectCriteria;

/**
 * HeuristicScoringDriver — deterministic, offline freight-prospect scoring.
 *
 * Scores a discovery candidate (domain / title / snippet / url) against
 * ProspectCriteria. Never returns null — always produces a valid result.
 *
 * Weight table (tune via class constants only):
 *   BASE                          30
 *   Freight keyword match         +10 each, cap +30
 *   Criteria sector token match   +10 each, cap +20
 *   Country match                 +10 (once)
 *   Clean-domain bonus            +10 (≤ 2 DNS labels)
 *   Directory/marketplace penalty −40 (blacklist domain)
 *   Soft directory penalty        −10 (annuaire|directory|liste in domain)
 *   Final clamp                   0–100
 *
 * INVARIANT: every entry in database/fixtures/discovery/serpapi.json scores ≥ 50
 * for criteria sectors=['transport'], countries=['France'].
 */
class HeuristicScoringDriver implements ScoringDriverInterface
{
    // ── Weights ───────────────────────────────────────────────────────────────

    private const BASE              = 30;
    private const FREIGHT_PER_MATCH = 10;
    private const FREIGHT_CAP       = 30;
    private const SECTOR_PER_MATCH  = 10;
    private const SECTOR_CAP        = 20;
    private const COUNTRY_BONUS     = 10;
    private const CLEAN_DOMAIN      = 10;
    private const BLACKLIST_PENALTY = 55;
    private const SOFT_DIR_PENALTY  = 10;

    // ── Keyword lists ─────────────────────────────────────────────────────────

    private const FREIGHT_KEYWORDS = [
        'transitaire',
        'freight forward',
        'commissionnaire de transport',
        'logistique',
        'transport maritime',
        'transport aérien',
        'transport routier',
        'fret',
        'supply chain',
        'affrètement',
        'douane',
    ];

    /**
     * Blacklisted domains (exact host or any subdomain of these).
     * A penalty of BLACKLIST_PENALTY is applied once when the candidate's domain
     * matches any of these (case-insensitive, with or without subdomains).
     */
    private const BLACKLISTED_DOMAINS = [
        'pagesjaunes.fr',
        'societe.com',
        'verif.com',
        'kompass.com',
        'europages.com',
        'europages.fr',
        'linkedin.com',
        'facebook.com',
        'instagram.com',
        'x.com',
        'twitter.com',
        'youtube.com',
        'wikipedia.org',
        'indeed.com',
        'indeed.fr',
        'glassdoor.fr',
        'leboncoin.fr',
        'amazon.com',
        'amazon.fr',
        'alibaba.com',
        'annuaire-entreprises.data.gouv.fr',
    ];

    /**
     * ISO-2 → TLD / path segment mappings for country matching without a label.
     * Used as a fallback when the French label is not found in the haystack.
     */
    private const ISO_TLD_MAP = [
        'FR' => 'fr',
        'MA' => 'ma',
        'ES' => 'es',
        'BE' => 'be',
        'DE' => 'de',
        'IT' => 'it',
        'PT' => 'pt',
        'NL' => 'nl',
    ];

    // ── Interface ─────────────────────────────────────────────────────────────

    public function score(array $candidate, ProspectCriteria $criteria): ?array
    {
        $domain  = $this->extractDomain($candidate);
        $url     = mb_strtolower($candidate['url'] ?? $candidate['link'] ?? '');
        $title   = mb_strtolower($candidate['title'] ?? '');
        $snippet = mb_strtolower($candidate['snippet'] ?? '');

        $haystack = $title . ' ' . $snippet . ' ' . $url;

        $score   = self::BASE;
        $reasons = [];

        // ── Freight-core keyword bonus ────────────────────────────────────────
        $freightHits = 0;
        foreach (self::FREIGHT_KEYWORDS as $kw) {
            if (mb_strpos($haystack, mb_strtolower($kw)) !== false) {
                $freightHits++;
            }
        }
        if ($freightHits > 0) {
            $freightBonus = min($freightHits * self::FREIGHT_PER_MATCH, self::FREIGHT_CAP);
            $score += $freightBonus;
            $reasons[] = "Mots-clés transport (+{$freightBonus})";
        }

        // ── Criteria sector match ─────────────────────────────────────────────
        $sectors     = $criteria->sectors ?? [];
        $sectorHits  = 0;
        foreach ($sectors as $sector) {
            $sectorLower = mb_strtolower((string) $sector);
            if ($sectorLower !== '' && mb_strpos($haystack, $sectorLower) !== false) {
                $sectorHits++;
            }
        }
        if ($sectorHits > 0) {
            $sectorBonus = min($sectorHits * self::SECTOR_PER_MATCH, self::SECTOR_CAP);
            $score += $sectorBonus;
            $reasons[] = "Secteur correspondant (+{$sectorBonus})";
        }

        // ── Country match ─────────────────────────────────────────────────────
        $countryMatched = false;
        $countries = $criteria->countries ?? [];
        $countryLabels = config('global.data.company_countries', []);

        foreach ($countries as $countryRaw) {
            if ($countryMatched) {
                break;
            }

            // Resolve label: if ISO-2 key → French label, otherwise pass through
            $label = $countryLabels[$countryRaw] ?? $countryRaw;
            $labelLower = mb_strtolower((string) $label);

            if ($labelLower !== '' && mb_strpos($haystack, $labelLower) !== false) {
                $countryMatched = true;
                break;
            }

            // Fallback: check domain TLD / url path for ISO code
            $iso = mb_strtoupper((string) $countryRaw);
            $tld = self::ISO_TLD_MAP[$iso] ?? null;
            if ($tld !== null) {
                $domainLower = mb_strtolower($domain);
                // Matches .fr TLD or /fr/ path segment
                if (
                    str_ends_with($domainLower, '.' . $tld) ||
                    str_contains($url, '/' . $tld . '/') ||
                    str_contains($url, '/' . $tld . '-')
                ) {
                    $countryMatched = true;
                }
            }
        }

        if ($countryMatched) {
            $score += self::COUNTRY_BONUS;
            $reasons[] = 'Pays cible (+' . self::COUNTRY_BONUS . ')';
        }

        // ── Clean-domain bonus ────────────────────────────────────────────────
        if ($domain !== '' && $this->isCleanDomain($domain)) {
            $score += self::CLEAN_DOMAIN;
            $reasons[] = 'Domaine propre (+' . self::CLEAN_DOMAIN . ')';
        }

        // ── Directory / marketplace penalty ───────────────────────────────────
        if ($domain !== '' && $this->isBlacklisted($domain)) {
            $score -= self::BLACKLIST_PENALTY;
            $reasons[] = 'Annuaire/réseau social (−' . self::BLACKLIST_PENALTY . ')';
        } elseif ($domain !== '' && $this->hasSoftDirectoryPattern($domain)) {
            $score -= self::SOFT_DIR_PENALTY;
            $reasons[] = 'Répertoire potentiel (−' . self::SOFT_DIR_PENALTY . ')';
        }

        // ── Clamp and build explanation ───────────────────────────────────────
        $score = max(0, min(100, $score));

        $explanation = implode(' · ', $reasons);
        if ($explanation !== '') {
            $explanation .= ' — Heuristique';
        } else {
            $explanation = 'Score de base — Heuristique';
        }

        // Truncate to ~500 chars
        if (mb_strlen($explanation) > 500) {
            $explanation = mb_substr($explanation, 0, 497) . '...';
        }

        return [
            'score'       => $score,
            'explanation' => $explanation,
            'exclude'     => false, // ponytail: heuristic can't read NL, never excludes
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Extract a bare hostname from the candidate's url/link or domain field.
     */
    private function extractDomain(array $candidate): string
    {
        // Prefer explicit domain field if present
        if (! empty($candidate['domain'])) {
            return mb_strtolower(trim($candidate['domain']));
        }

        $url = $candidate['url'] ?? $candidate['link'] ?? '';
        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);
        return $host !== false && $host !== null ? mb_strtolower($host) : '';
    }

    /**
     * A "clean" domain has at most 2 DNS labels (e.g. geodis.com, clasquin.com).
     * Subdomains like www.geodis.com have 3 labels — not clean.
     * Exception: we strip the ubiquitous "www." prefix before counting.
     */
    private function isCleanDomain(string $domain): bool
    {
        $d = preg_replace('/^www\./', '', $domain);
        return substr_count($d, '.') <= 1;
    }

    /**
     * Returns true when the domain (including subdomains) matches a blacklisted root.
     */
    private function isBlacklisted(string $domain): bool
    {
        foreach (self::BLACKLISTED_DOMAINS as $bad) {
            $bad = mb_strtolower($bad);
            if ($domain === $bad || str_ends_with($domain, '.' . $bad)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Soft penalty: domain name contains "annuaire", "directory", or "liste".
     */
    private function hasSoftDirectoryPattern(string $domain): bool
    {
        return (bool) preg_match('/annuaire|directory|liste/i', $domain);
    }
}
