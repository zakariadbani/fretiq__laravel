<?php

namespace App\Services\Discovery;

use App\Models\Setting;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HomepageSnapshotService — fetches and cleans a candidate's homepage so the
 * scorer sees what the site actually IS, not just the SERP title + snippet.
 *
 * Why: a press article ABOUT France–Morocco textile flows reads exactly like a
 * shipper in a SERP snippet, so junk (scribd.com, pagesjaunes.fr, espaceagro.com)
 * scored 85–95. The homepage text is what disambiguates "we ship goods" from
 * "we write about people who ship goods".
 *
 * Settings (Découverte tab):
 *   - decouverte.fetch_homepage        (boolean, default true)  — master switch
 *   - decouverte.homepage_excerpt_chars(number,  default 2000)  — char budget
 *   - decouverte.homepage_cache_days   (number,  default 7)     — cache TTL
 *   - decouverte.homepage_timeout      (number,  default 3)     — HTTP timeout (s)
 *   - decouverte.homepage_http_fallback(boolean, default false) — retry over http://
 *
 * Cost model: one HTTP request per NEW domain. Results (including failures) are
 * cached, so a dead domain is not refetched on every run.
 *
 * Latency model: a dead domain costs the full timeout. The http:// retry doubles
 * that worst case, which is why it is opt-in and off by default. For a batch of
 * candidates call prefetch() first — it warms the cache with a pooled, concurrent
 * fetch so the subsequent excerpt() calls are pure cache hits, turning N × timeout
 * of serial latency into ceil(N / 10) × timeout.
 *
 * Never throws — a homepage fetch is best-effort enrichment, never a run-killer.
 * Response bodies are NEVER logged (they can contain arbitrary third-party data).
 */
class HomepageSnapshotService
{
    /** Below this many cleaned characters there is nothing worth scoring on. */
    private const MIN_USEFUL_CHARS = 50;

    /** Empty string is the "we tried and got nothing" sentinel — cached, read back as null. */
    private const NEGATIVE_SENTINEL = '';

    private const USER_AGENT = 'Mozilla/5.0 (compatible; FretiqBot/1.0; +https://tcl-france.com)';

    /** Concurrent requests per pooled prefetch wave. */
    private const POOL_CHUNK = 10;

    // Defaults — mirrored in SettingController's Découverte tab.
    private const DEFAULT_EXCERPT_CHARS = 2000;
    private const DEFAULT_CACHE_DAYS    = 7;
    private const DEFAULT_TIMEOUT       = 3;

    /**
     * Cleaned homepage excerpt for the domain, or null when unavailable/disabled.
     */
    public function excerpt(string $domain): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return null;
        }

        $cached = Cache::remember(
            $this->cacheKey($domain),
            now()->addDays($this->cacheDays()),
            fn (): string => $this->fetchAndClean($domain) ?? self::NEGATIVE_SENTINEL
        );

        // Negative results are cached as '' so a dead domain is not refetched.
        return is_string($cached) && $cached !== self::NEGATIVE_SENTINEL ? $cached : null;
    }

    /**
     * Read a previously prefetched excerpt without ever opening a network request.
     * The discovery pipeline uses this after prefetch() so an unexpected cache
     * eviction cannot start an unbounded fallback call near the attempt deadline.
     */
    public function cachedExcerpt(string $domain): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $domain = strtolower(trim($domain));

        if ($domain === '' || ! Cache::has($this->cacheKey($domain))) {
            return null;
        }

        $cached = Cache::get($this->cacheKey($domain));

        return is_string($cached) && $cached !== self::NEGATIVE_SENTINEL ? $cached : null;
    }

    /**
     * Warm the cache for many domains concurrently.
     *
     * Writes exactly the keys/TTL/sentinel that excerpt() reads, so every domain
     * passed here becomes a pure cache hit afterwards — zero further HTTP.
     * Domains already cached, blank, or duplicated are skipped.
     *
     * Never throws: one bad domain becomes a cached negative for itself alone.
     *
     * @param  list<string>|array<mixed>  $domains
     */
    public function prefetch(array $domains, ?float $deadlineAt = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $pending = [];

        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            $domain = strtolower(trim($domain));

            // isset() on the key doubles as the de-duplication guard.
            if ($domain === '' || isset($pending[$domain])) {
                continue;
            }

            if (Cache::has($this->cacheKey($domain))) {
                continue;
            }

            $pending[$domain] = true;
        }

        $pending = array_keys($pending);

        if ($pending === []) {
            return true;
        }

        $fallback = $this->httpFallbackEnabled();

        foreach (array_chunk($pending, self::POOL_CHUNK) as $chunk) {
            // Transport failures are only deferred (left uncached) when there is an
            // http:// pass still to come; otherwise they are cached negative here.
            $pass = $this->runPoolPass($chunk, 'https', $fallback, $deadlineAt);

            if (! $pass['completed']) {
                return false;
            }

            $deferred = $pass['deferred'];

            if ($deferred !== []) {
                $fallbackPass = $this->runPoolPass($deferred, 'http', false, $deadlineAt);

                if (! $fallbackPass['completed']) {
                    return false;
                }
            }
        }

        return true;
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /**
     * Fire one wave of concurrent GETs and cache each outcome independently.
     *
     * @param  list<string>  $domains
     * @return array{deferred:list<string>,completed:bool}
     */
    private function runPoolPass(
        array $domains,
        string $scheme,
        bool $deferFailures,
        ?float $deadlineAt = null,
    ): array
    {
        $timeout = $this->boundedTimeout($deadlineAt);

        if ($timeout === null) {
            return ['deferred' => $domains, 'completed' => false];
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $domain) => $pool->as($domain)
                    ->timeout($timeout)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->get("{$scheme}://{$domain}"),
                $domains
            ));
        } catch (\Throwable $e) {
            // Pool-level blowup (an exception type Laravel does not fold into the
            // results array). Degrade to the serial path so one bad promise cannot
            // cost the whole wave, then stop — everything is cached below.
            Log::warning('[HomepageSnapshotService] Échec du pool de récupération — repli séquentiel.', [
                'scheme' => $scheme,
                'count'  => count($domains),
                'error'  => $e->getMessage(),
            ]);

            // With an absolute deadline, serial fallback could multiply the remaining
            // timeout by the number of domains. Leave the whole wave uncached so the
            // next job attempt can retry it safely.
            if ($deadlineAt !== null) {
                return ['deferred' => $domains, 'completed' => false];
            }

            foreach ($domains as $domain) {
                try {
                    $this->cacheExcerpt($domain, $this->fetchAndClean($domain));
                } catch (\Throwable $inner) {
                    $this->cacheExcerpt($domain, null);
                }
            }

            return ['deferred' => [], 'completed' => true];
        }

        $deferred = [];

        foreach ($domains as $domain) {
            $result = $responses[$domain] ?? null;

            // Http::pool hands back a ConnectionException OBJECT instead of throwing.
            if ($result instanceof \Throwable) {
                Log::warning('[HomepageSnapshotService] Échec de récupération de la page d\'accueil.', [
                    'domain' => $domain,
                    'scheme' => $scheme,
                    'error'  => $result->getMessage(),
                ]);

                if ($deferFailures) {
                    $deferred[] = $domain;
                } else {
                    $this->cacheExcerpt($domain, null);
                }

                continue;
            }

            try {
                if (! $result instanceof Response) {
                    $this->cacheExcerpt($domain, null);

                    continue;
                }

                if ($result->failed()) {
                    Log::warning('[HomepageSnapshotService] Réponse HTTP échouée — page d\'accueil ignorée.', [
                        'domain' => $domain,
                        'scheme' => $scheme,
                        'status' => $result->status(),
                    ]);

                    // A 4xx/5xx is a real answer, not a transport problem — no http retry.
                    $this->cacheExcerpt($domain, null);

                    continue;
                }

                $this->cacheExcerpt($domain, $this->cleanToExcerpt($result->body()));
            } catch (\Throwable $e) {
                Log::warning('[HomepageSnapshotService] Échec de traitement de la page d\'accueil.', [
                    'domain' => $domain,
                    'scheme' => $scheme,
                    'error'  => $e->getMessage(),
                ]);

                $this->cacheExcerpt($domain, null);
            }
        }

        return ['deferred' => $deferred, 'completed' => true];
    }

    /**
     * Bound every outbound homepage request to the absolute work deadline.
     * A null return means the request must not start.
     */
    private function boundedTimeout(?float $deadlineAt): ?int
    {
        $configured = $this->timeout();

        if ($deadlineAt === null) {
            return $configured;
        }

        $remaining = $deadlineAt - microtime(true);

        if ($remaining < 1.0) {
            return null;
        }

        return max(1, min($configured, (int) floor($remaining)));
    }

    private function cacheKey(string $domain): string
    {
        return "discovery.homepage.{$domain}";
    }

    /** Store under the exact key/TTL/sentinel contract excerpt() reads. */
    private function cacheExcerpt(string $domain, ?string $text): void
    {
        Cache::put(
            $this->cacheKey($domain),
            $text ?? self::NEGATIVE_SENTINEL,
            now()->addDays($this->cacheDays())
        );
    }

    /**
     * Fetch over https, optionally falling back to http once on connection failure.
     * Returns the cleaned excerpt, or null on any failure.
     */
    private function fetchAndClean(string $domain): ?string
    {
        $body = $this->fetchBody($domain);

        return $body === null ? null : $this->cleanToExcerpt($body);
    }

    private function fetchBody(string $domain): ?string
    {
        $schemes = $this->httpFallbackEnabled() ? ['https', 'http'] : ['https'];

        foreach ($schemes as $scheme) {
            try {
                $response = Http::timeout($this->timeout())
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->get("{$scheme}://{$domain}");

                if ($response->failed()) {
                    Log::warning('[HomepageSnapshotService] Réponse HTTP échouée — page d\'accueil ignorée.', [
                        'domain' => $domain,
                        'scheme' => $scheme,
                        'status' => $response->status(),
                    ]);

                    // A 4xx/5xx is a real answer, not a transport problem — do not retry http.
                    return null;
                }

                return $response->body();
            } catch (\Throwable $e) {
                Log::warning('[HomepageSnapshotService] Échec de récupération de la page d\'accueil.', [
                    'domain' => $domain,
                    'scheme' => $scheme,
                    'error'  => $e->getMessage(),
                ]);
                // Connection-level failure — fall through and retry over http once.
            }
        }

        return null;
    }

    /**
     * The single clean → truncate → min-length pipeline shared by excerpt() and
     * prefetch(). Returns null when there is nothing worth scoring on.
     */
    private function cleanToExcerpt(string $html): ?string
    {
        $text = $this->clean($html);

        return mb_strlen($text) >= self::MIN_USEFUL_CHARS ? $text : null;
    }

    /**
     * Strip script/style/noscript blocks INCLUDING their contents, then all tags,
     * decode entities, and collapse whitespace. Truncated to the char budget.
     */
    private function clean(string $html): string
    {
        $stripped = preg_replace(
            '#<(script|style|noscript)\b[^>]*>.*?</\1\s*>#is',
            ' ',
            $html
        );

        // preg_replace returns null on backtrack-limit blowups (huge minified pages):
        // fall back to the raw body rather than losing the candidate entirely.
        $stripped = $stripped ?? $html;

        $text = html_entity_decode(strip_tags($stripped), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $this->excerptChars());
    }

    // ── Settings accessors ───────────────────────────────────────────────────────

    private function enabled(): bool
    {
        return (bool) Setting::get('decouverte.fetch_homepage', true);
    }

    /**
     * Off by default: retrying a dead domain over http:// doubles the worst-case
     * wait (two full timeouts) for the small minority of sites that are http-only.
     */
    private function httpFallbackEnabled(): bool
    {
        return (bool) Setting::get('decouverte.homepage_http_fallback', false);
    }

    private function excerptChars(): int
    {
        return $this->positiveSetting('decouverte.homepage_excerpt_chars', self::DEFAULT_EXCERPT_CHARS);
    }

    private function cacheDays(): int
    {
        return $this->positiveSetting('decouverte.homepage_cache_days', self::DEFAULT_CACHE_DAYS);
    }

    private function timeout(): int
    {
        return $this->positiveSetting('decouverte.homepage_timeout', self::DEFAULT_TIMEOUT);
    }

    /**
     * Read a numeric setting, falling back to the default when it is missing,
     * non-numeric or non-positive (a 0 timeout / 0-char budget would be a footgun).
     */
    private function positiveSetting(string $key, int $default): int
    {
        $value = Setting::get($key, $default);

        if (! is_numeric($value) || (int) $value <= 0) {
            return $default;
        }

        return (int) $value;
    }
}
