<?php

namespace App\Services\Discovery;

use App\Exceptions\DiscoveryConfigurationException;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderExecution;
use App\Services\Providers\ProviderRequestException;
use App\Services\Providers\SerpApi\SerpApiClient;
use App\Support\DomainBlocklist;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * CompanyDiscoveryService — SerpAPI-backed company domain discovery.
 *
 * Driver selection:  config('services.serpapi.driver', 'local')
 *   'local'  → loads database/fixtures/discovery/serpapi.json — NO HTTP
 *   anything else  → calls live SerpAPI
 *
 * Returns a deduped list of ['domain', 'title', 'snippet', 'url', 'discovery_query'] capped at $max.
 * The 'discovery_query' key is only set by discoverFromSerpApi() (the query that
 * surfaced the candidate) — discoverFromFixtures() (local dev driver) omits it.
 *
 * Query source (discoverFromSerpApi only): uses criteria.ai_queries (cached AI-generated
 * queries, enabled ones only) when present, else falls back to buildQueries(). buildQueries()
 * itself stays a pure criteria→string[] function (no HTTP) — the live IntentQueryService call
 * only happens from the controller's generateQueries() action, never from here or from preview.
 *
 * Engines: each ai_queries entry may carry an optional 'engine' key — 'google' (organic,
 * the default when absent) or 'google_maps' (local business listings). Google organic
 * surfaces articles/directories/PDFs; Google Maps surfaces actual businesses, which is
 * the higher-quality prospection source (Morocco-first business focus).
 */
class CompanyDiscoveryService
{
    public const PAGE_SIZE = 10;

    /** Google Maps returns 20 local_results per page (empirically verified — see discoverMapsPage()). */
    public const MAPS_PAGE_SIZE = 20;

    /** Largest page any engine can return — used to size engine-agnostic candidate requests. */
    public const MAX_PAGE_SIZE = self::MAPS_PAGE_SIZE;

    public const ENGINE_GOOGLE = 'google';

    public const ENGINE_GOOGLE_MAPS = 'google_maps';

    public const ENGINE_GOOGLE_LOCAL = 'google_local';

    public const ENGINE_BING = 'bing';

    /** @var list<string> */
    public const ENGINES = [self::ENGINE_GOOGLE, self::ENGINE_GOOGLE_MAPS, self::ENGINE_GOOGLE_LOCAL, self::ENGINE_BING];

    private const MAX_SEARCHES_PER_RUN = 15;

    private readonly SerpApiClient $serpApi;

    private readonly ProviderCallLedger $providerCalls;

    public function __construct(
        private readonly IntentQueryService $intentQuery = new IntentQueryService,
        private readonly DomainBlocklist $blocklist = new DomainBlocklist,
        private readonly DiscoveryEngineRegistry $engines = new DiscoveryEngineRegistry,
        ?SerpApiClient $serpApi = null,
        ?ProviderCallLedger $providerCalls = null,
    ) {
        $this->providerCalls = $providerCalls ?? new ProviderCallLedger;
        $this->serpApi = $serpApi ?? new SerpApiClient($this->providerCalls);
    }

    /**
     * Discover companies matching the given criteria.
     *
     * @return list<array{domain: string, title: ?string, snippet: ?string, url: string}>
     */
    public function discover(ProspectCriteria $criteria, int|DiscoveryRun $maxOrRun = 20, ?int $need = null): array
    {
        $run = $maxOrRun instanceof DiscoveryRun ? $maxOrRun : null;
        $max = $run ? (int) $need : (int) $maxOrRun;

        if ($max <= 0) {
            return [];
        }

        if ($this->isLocal()) {
            return $this->discoverFromFixtures($max);
        }

        return $this->discoverFromSerpApi($criteria, $run, $max);
    }

    /**
     * Discover candidates for a queued discovery run using a SerpAPI search-call
     * budget. Unlike discover(..., $run, $need), $searchBudget is the number of
     * provider calls allowed, not the number of companies desired.
     */
    public function discoverForRun(
        ProspectCriteria $criteria,
        DiscoveryRun $run,
        int $searchBudget,
        ?float $workDeadlineAt = null,
    ): DiscoveryCollectionResult {
        $searchBudget = max(0, $searchBudget);
        $snapshot = $this->snapshot($run);
        $reserved = (int) ($run->searches_reserved ?? $run->credits_reserved ?? 0);
        $consumed = (int) ($run->searches_consumed ?? 0);

        // A run with no durable search reservation is contact-only and its
        // collection is already complete. Check this before provider configuration:
        // a retry after its final durable debit must still be able to finalize.
        if ($consumed >= $reserved) {
            $this->markCollectionComplete($criteria, $run);

            return new DiscoveryCollectionResult($snapshot, true);
        }

        if ($this->isLocal()) {
            // A temporary invocation budget of zero must not load fixtures or
            // terminalize an outstanding reservation. The same job will resume.
            if ($searchBudget === 0) {
                return new DiscoveryCollectionResult($snapshot, false);
            }

            return $this->collectLocalRun($run);
        }

        $queries = $this->enabledQueries($criteria);
        $cursors = $this->normaliseCursors($criteria, $queries);

        if ($this->isLiveCollectionTerminal($run, $searchBudget, $queries, $cursors)) {
            $this->markCollectionComplete($criteria, $run);

            return new DiscoveryCollectionResult($snapshot, true);
        }

        // Let the pipeline consume durable candidates before collecting another page.
        if (count($snapshot) > (int) $run->consumed) {
            return new DiscoveryCollectionResult($snapshot, false);
        }

        $apiKey = config('services.serpapi.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new DiscoveryConfigurationException(
                'La découverte d’entreprises ne peut pas démarrer : la clé API du fournisseur de recherche n’est pas configurée.'
            );
        }

        if ($queries === []) {
            throw new DiscoveryConfigurationException(
                'La découverte d’entreprises ne peut pas démarrer : aucune requête ni aucun moteur de recherche n’est activé.'
            );
        }

        if ($searchBudget === 0) {
            return new DiscoveryCollectionResult($snapshot, false);
        }

        [$snapshot, $providerOutage] = $this->discoverFromSerpApiBySearchBudget(
            $criteria,
            $run,
            $searchBudget,
            $queries,
            $workDeadlineAt,
        );

        $freshRun = $run->fresh() ?? $run;
        $freshCriteria = $criteria->fresh() ?? $criteria;
        $freshCursors = $this->normaliseCursors($freshCriteria, $queries);

        $terminal = $this->isLiveCollectionTerminal($freshRun, $searchBudget, $queries, $freshCursors);
        $searchProviderDown = false;

        // The provider stopped responding this invocation. Split by whether there
        // is already-paid-for work to fall back on: an empty snapshot means the
        // run truly has nothing, so the job must terminalize it; a non-empty
        // snapshot means candidates exist to process, so collection is simply
        // done (stop asking a dead provider) and processing drains what's there.
        if ($providerOutage) {
            if ($snapshot === []) {
                $searchProviderDown = true;
            } else {
                $terminal = true;
                Log::channel('discovery')->warning('[CompanyDiscoveryService] Search provider outage detected mid-run — draining already-collected candidates instead of retrying the provider.', [
                    'criteria_id' => $criteria->id,
                    'run_id' => $run->id,
                ]);
            }
        }

        if ($terminal) {
            $this->markCollectionComplete($freshCriteria, $freshRun);
        }

        return new DiscoveryCollectionResult($snapshot, $terminal, $searchProviderDown);
    }

    /**
     * Persist collection completion without a schema change. Discovery cursors
     * already hold durable provider state; tying the marker to the run id keeps
     * it safe when the same criterion starts a later discovery.
     */
    private function markCollectionComplete(ProspectCriteria $criteria, DiscoveryRun $run): void
    {
        DB::transaction(function () use ($criteria, $run): void {
            /** @var ProspectCriteria|null $lockedCriteria */
            $lockedCriteria = ProspectCriteria::whereKey($criteria->getKey())->lockForUpdate()->first();
            /** @var DiscoveryRun|null $freshRun */
            $freshRun = DiscoveryRun::whereKey($run->getKey())->first();

            if (! $lockedCriteria || ! $freshRun || $freshRun->status !== 'running') {
                return;
            }

            $cursors = is_array($lockedCriteria->discovery_cursors)
                ? $lockedCriteria->discovery_cursors
                : [];
            $cursors[ProspectCriteria::DISCOVERY_COLLECTION_COMPLETE_RUN_KEY] = (int) $run->getKey();
            $lockedCriteria->forceFill(['discovery_cursors' => $cursors])->save();
        });
    }

    /**
     * Persist exhausted=true for one query×engine stream whose cursor is
     * permanently unusable (e.g. SerpApiClient::validateStart() rejects a
     * corrupted start offset). Without this the stream retries the same
     * invalid cursor forever and isLiveCollectionTerminal() never concludes.
     *
     * @param  array{q: string, engine: string}  $queryDef
     */
    private function markCursorExhausted(ProspectCriteria $criteria, array $queryDef): void
    {
        DB::transaction(function () use ($criteria, $queryDef): void {
            /** @var ProspectCriteria|null $locked */
            $locked = ProspectCriteria::whereKey($criteria->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            // normaliseCursors() below only ever sees this single query, so its
            // `_rotation` validity check would otherwise drop an unrelated rotation
            // pointer as a side effect of this narrow, single-stream write.
            $storedRotation = is_array($locked->discovery_cursors)
                ? ($locked->discovery_cursors['_rotation'] ?? null)
                : null;

            $cursors = $this->normaliseCursors($locked, [$queryDef]);
            $key = $this->queryKey($queryDef);
            $existing = is_array($cursors[$key] ?? null) ? $cursors[$key] : [];

            $cursors[$key] = [
                'q' => $queryDef['q'],
                'engine' => $queryDef['engine'],
                'start' => max(0, (int) ($existing['start'] ?? 0)),
                'exhausted' => true,
                'provider_params' => $existing['provider_params'] ?? [],
            ];

            if (is_string($storedRotation)) {
                $cursors['_rotation'] = $storedRotation;
            }

            $locked->forceFill(['discovery_cursors' => $cursors])->save();
        });
    }

    private function collectLocalRun(DiscoveryRun $run): DiscoveryCollectionResult
    {
        $snapshot = DB::transaction(function () use ($run): array {
            /** @var DiscoveryRun|null $lockedRun */
            $lockedRun = DiscoveryRun::whereKey($run->getKey())->lockForUpdate()->first();

            if (! $lockedRun) {
                return [];
            }

            if ($lockedRun->candidates_snapshot === null) {
                $lockedRun->forceFill([
                    'candidates_snapshot' => $this->discoverFromFixtures(PHP_INT_MAX),
                ])->save();
            } else {
                $lockedRun->touch();
            }

            return $this->snapshot($lockedRun);
        });

        return new DiscoveryCollectionResult($snapshot, true);
    }

    /**
     * Fetch live SerpAPI account balance/usage for the superadmin quota page.
     *
     * Verified live 2026-07-04 (STATUS 200): SerpAPI /account returns plan_searches_left, total_searches_left, this_month_usage, searches_per_month, plan_name.
     *
     * @return array{plan_searches_left: ?int, total_searches_left: ?int, this_month_usage: ?int, searches_per_month: ?int, plan_name: ?string}|null
     */
    public function accountUsage(): ?array
    {
        if ($this->isLocal()) {
            return null;
        }

        $apiKey = config('services.serpapi.api_key');

        if (! $apiKey) {
            return null;
        }

        $fetch = function (): ?array {
            try {
                $execution = $this->serpApi->account(new ProviderCallContext(
                    hash('sha256', 'serpapi:legacy:account:'.Str::uuid()),
                    0,
                    engine: 'account',
                ));
                $json = $execution->response?->data ?? [];

                DB::transaction(fn () => $this->providerCalls->settle(
                    $execution,
                    $json === [] ? 0 : 1,
                    0,
                ));

                return [
                    'plan_searches_left' => $json['plan_searches_left'] ?? null,
                    'total_searches_left' => $json['total_searches_left'] ?? null,
                    'this_month_usage' => $json['this_month_usage'] ?? null,
                    'searches_per_month' => $json['searches_per_month'] ?? null,
                    'plan_name' => $json['plan_name'] ?? null,
                ];
            } catch (ProviderRequestException $exception) {
                Log::channel('discovery')->warning('[CompanyDiscoveryService] SerpAPI account request failed', [
                    'status' => $exception->httpStatus,
                    'error_code' => $exception->safeCode,
                ]);

                return null;
            } catch (\Throwable $e) {
                Log::channel('discovery')->warning('[CompanyDiscoveryService] SerpAPI account call threw an exception', [
                    'exception_class' => $e::class,
                ]);

                return null;
            }
        };

        // ponytail: the cached value is always an array so a failed fetch actually caches —
        // Cache::remember treats a stored null as a miss and would re-hit the vendor on every
        // page load. Key is versioned because the cached SHAPE changed; a pre-existing entry
        // under the old key would be read as a malformed payload after deploy.
        $cached = Cache::remember('provider.serpapi.account.v2', now()->addMinutes(10), fn (): array => [
            'payload' => $fetch(),
            'fetched_at' => now()->toIso8601String(),
        ]);

        return $cached['payload'] === null
            ? null
            : $cached['payload'] + ['fetched_at' => $cached['fetched_at']];
    }
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Single choke point for turning a SERP link into a storable company domain.
     * Used by the fixture, cursor and cursorless paths alike — returns null for
     * blocked hosts AND for document URLs (PDF, Word, Excel…).
     */
    public function extractDomain(string $url): ?string
    {
        if ($this->blocklist->isBlockedUrl($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host);

        return $host && ! $this->isBlockedDomain($host) ? $host : null;
    }

    public function isBlockedDomain(string $domain): bool
    {
        return $this->blocklist->isBlocked($domain);
    }

    // ── Local fixture driver ──────────────────────────────────────────────────

    /**
     * Local dev driver. Uses one fixture per selected engine family: generic web
     * results for Google/Bing and local listings for Google Maps/Google Local.
     */
    private function discoverFromFixtures(int $max): array
    {
        $families = $this->engines->fixtureFamilies($this->engines->selected());
        $organic = in_array('web', $families, true)
            ? ($this->readFixture('serpapi.json', true) ?? [])
            : [];
        $maps = in_array('local', $families, true)
            ? ($this->readFixture('serpapi_maps.json', false) ?? [])
            : [];

        $results = [];
        $seen = [];

        foreach ($organic as $item) {
            if (count($results) >= $max) {
                return $results;
            }

            if (! is_array($item)) {
                continue;
            }

            $url = $item['link'] ?? $item['url'] ?? null;
            $domain = is_string($url) ? $this->extractDomain($url) : null;

            if (! $domain || isset($seen[$domain])) {
                continue;
            }

            $seen[$domain] = true;
            $results[] = [
                'domain' => $domain,
                'title' => $item['title'] ?? null,
                'snippet' => $item['snippet'] ?? null,
                'url' => $url,
            ];
        }

        // The maps fixture may be either a bare list of local_results items or the
        // full SerpAPI envelope ({"local_results": [...]}) — accept both.
        $localResults = is_array($maps['local_results'] ?? null) ? $maps['local_results'] : $maps;

        foreach ($this->mapMapsResults($localResults, null) as $candidate) {
            if (count($results) >= $max) {
                break;
            }

            if (isset($seen[$candidate['domain']])) {
                continue;
            }

            $seen[$candidate['domain']] = true;
            unset($candidate['discovery_query']);
            $results[] = $candidate;
        }

        return $results;
    }

    /**
     * @return array<mixed>|null null = missing/invalid and the caller should bail
     */
    private function readFixture(string $file, bool $warnWhenMissing): ?array
    {
        $path = base_path('database/fixtures/discovery/'.$file);

        if (! file_exists($path)) {
            if ($warnWhenMissing) {
                Log::channel('discovery')->warning('[CompanyDiscoveryService] Local fixture missing', ['path' => $path]);
            }

            return $warnWhenMissing ? null : [];
        }

        $raw = json_decode(file_get_contents($path), true);

        if (! is_array($raw)) {
            Log::channel('discovery')->warning('[CompanyDiscoveryService] Local fixture is not a valid JSON array.', ['path' => $path]);

            return $warnWhenMissing ? null : [];
        }

        return $raw;
    }

    // ── Real SerpAPI driver ───────────────────────────────────────────────────

    private function discoverFromSerpApi(ProspectCriteria $criteria, ?DiscoveryRun $run, int $need): array
    {
        $apiKey = config('services.serpapi.api_key');

        if (! $apiKey) {
            Log::channel('discovery')->warning('[CompanyDiscoveryService] SerpAPI key not configured — skipping discovery.', [
                'criteria_id' => $criteria->id,
            ]);

            return [];
        }

        $queries = $this->enabledQueries($criteria);

        if ($queries === []) {
            return $run ? $this->snapshot($run) : [];
        }

        if ($run === null) {
            return $this->discoverFromSerpApiWithoutCursor($criteria, $queries, $need);
        }

        $snapshot = $this->snapshot($run);
        if (count($snapshot) >= $need) {
            return $snapshot;
        }

        $cursors = $this->normaliseCursors($criteria, $queries);
        $order = $this->rotatedQueryOrder($queries, $cursors);
        $searches = 0;

        foreach ($order as $query) {
            if (count($snapshot) >= $need || $searches >= self::MAX_SEARCHES_PER_RUN) {
                break;
            }

            $key = $this->queryKey($query);

            while (count($snapshot) < $need && $searches < self::MAX_SEARCHES_PER_RUN) {
                $cursors = $this->normaliseCursors($criteria->fresh() ?? $criteria, $queries);
                $cursor = $cursors[$key] ?? $query + ['start' => 0, 'exhausted' => false];

                if ((bool) ($cursor['exhausted'] ?? false)) {
                    break;
                }

                $start = max(0, (int) ($cursor['start'] ?? 0));
                $providerParams = is_array($cursor['provider_params'] ?? null) ? $cursor['provider_params'] : [];
                $this->recordAttemptRotation($criteria, $queries, $query);

                try {
                    $execution = $this->fetchPage(
                        $this->searchContext($run, $query, $start, $providerParams),
                        $query,
                        $start,
                        $providerParams,
                    );
                } catch (ProviderRequestException $exception) {
                    Log::channel('discovery')->warning('[CompanyDiscoveryService] SerpAPI request failed', [
                        'criteria_id' => $criteria->id,
                        'query' => $query['q'],
                        'engine' => $query['engine'],
                        'start' => $start,
                        'status' => $exception->httpStatus,
                        'error_code' => $exception->safeCode,
                    ]);

                    return $snapshot;
                } catch (\Throwable $e) {
                    Log::channel('discovery')->warning('[CompanyDiscoveryService] SerpAPI call threw an exception', [
                        'criteria_id' => $criteria->id,
                        'query' => $query['q'],
                        'engine' => $query['engine'],
                        'start' => $start,
                        'exception_class' => $e::class,
                    ]);

                    return $snapshot;
                }

                $searches++;

                if ($execution->replayed || $execution->response === null) {
                    return $this->snapshot($run->fresh());
                }

                [$pageCandidates, $exhausted, $nextStart, $nextParams, $resultCount] = $this->parsePage(
                    $criteria,
                    $query,
                    $execution,
                    $snapshot,
                );

                $committed = $this->appendPage(
                    $criteria,
                    $run,
                    $queries,
                    $query,
                    $start,
                    $pageCandidates,
                    $exhausted,
                    $nextStart,
                    $nextParams,
                    $execution,
                    $resultCount,
                );
                if (! $committed) {
                    return $this->snapshot($run->fresh());
                }

                $snapshot = $this->snapshot($run->fresh());

                if ($pageCandidates === [] || $exhausted) {
                    break;
                }

                if (! app()->runningUnitTests()) {
                    usleep(600_000);
                }
            }
        }

        return $snapshot;
    }

    /**
     * Live SerpAPI run mode where the budget is provider search calls. Each call
     * appends up to PAGE_SIZE new candidates to the durable run snapshot.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool} [$snapshot, $providerOutage].
     *         $providerOutage is invocation-scoped — it means a 429 (quota
     *         exhausted) was hit and the collection loop stopped immediately
     *         rather than burning the rest of the reservation on a dead
     *         provider. It says nothing about whether the run holds usable
     *         work; the caller decides that from $snapshot.
     */
    private function discoverFromSerpApiBySearchBudget(
        ProspectCriteria $criteria,
        DiscoveryRun $run,
        int $searchBudget,
        array $queries,
        ?float $workDeadlineAt,
    ): array {
        $snapshot = $this->snapshot($run);
        $cursors = $this->normaliseCursors($criteria, $queries);
        $order = $this->rotatedQueryOrder($queries, $cursors);
        $attempts = 0;
        $maxSearches = min($searchBudget, self::MAX_SEARCHES_PER_RUN);

        // Round-robin: at most one attempt per query×engine stream per round.
        // A broken provider stream cannot prevent the remaining streams from running.
        while ($attempts < $maxSearches) {
            $attemptedThisRound = false;

            foreach ($order as $query) {
                if ($attempts >= $maxSearches) {
                    break;
                }

                $key = $this->queryKey($query);
                $cursors = $this->normaliseCursors($criteria->fresh() ?? $criteria, $queries);
                $cursor = $cursors[$key] ?? $query + ['start' => 0, 'exhausted' => false];

                if ((bool) ($cursor['exhausted'] ?? false)) {
                    continue;
                }

                $start = max(0, (int) ($cursor['start'] ?? 0));
                $providerParams = is_array($cursor['provider_params'] ?? null) ? $cursor['provider_params'] : [];

                $timeoutSeconds = $this->requestTimeoutSeconds($workDeadlineAt);
                if ($timeoutSeconds === null) {
                    return [$snapshot, false];
                }

                $transportReserved = false;
                $reserve = function () use (&$transportReserved, $run, $criteria, $queries, $query): bool {
                    if (! $this->reserveSerpApiAttempt($run)) {
                        return false;
                    }

                    $transportReserved = true;
                    // Rotate after the durable debit and immediately before I/O.
                    $this->recordAttemptRotation($criteria, $queries, $query);

                    return true;
                };

                try {
                    $execution = $this->fetchPage(
                        $this->searchContext($run, $query, $start, $providerParams),
                        $query,
                        $start,
                        $providerParams,
                        $reserve,
                        $timeoutSeconds,
                    );
                } catch (ProviderRequestException $exception) {
                    if ($transportReserved) {
                        $attempts++;
                        $attemptedThisRound = true;
                    }
                    if ($exception->safeCode === 'serpapi_budget_unavailable') {
                        return [$this->snapshot($run->fresh()), false];
                    }

                    Log::channel('discovery')->warning('[CompanyDiscoveryService] SerpAPI request failed', [
                        'criteria_id' => $criteria->id,
                        'query' => $query['q'],
                        'engine' => $query['engine'],
                        'start' => $start,
                        'status' => $exception->httpStatus,
                        'error_code' => $exception->safeCode,
                    ]);

                    if ($exception->httpStatus === 429 || $exception->safeCode === 'rate_limit') {
                        return [$snapshot, true];
                    }

                    continue;
                } catch (InvalidArgumentException $exception) {
                    if ($transportReserved) {
                        $attempts++;
                        $attemptedThisRound = true;
                    }

                    // The provider client rejected this stream's own stored cursor as
                    // structurally invalid (bad start offset, bad params, …) — it would
                    // never succeed on retry, so mark it exhausted instead of looping
                    // on it forever across every future job attempt.
                    $this->markCursorExhausted($criteria, $query);

                    // Never log the raw exception text here (see CompanyDiscoveryLoggingTest)
                    // — SerpApiClient's validation messages are safe constant codes today,
                    // but this file's convention stays exception_class only, no message text.
                    Log::channel('discovery')->warning('[CompanyDiscoveryService] discovery_cursor_invalidated — corrupt cursor rejected by the provider client, marking stream exhausted.', [
                        'criteria_id' => $criteria->id,
                        'query' => $query['q'],
                        'engine' => $query['engine'],
                        'start' => $start,
                        'exception_class' => $exception::class,
                    ]);

                    continue;
                } catch (\Throwable $e) {
                    if ($transportReserved) {
                        $attempts++;
                        $attemptedThisRound = true;
                    }
                    Log::channel('discovery')->warning('[CompanyDiscoveryService] SerpAPI call threw an exception', [
                        'criteria_id' => $criteria->id,
                        'query' => $query['q'],
                        'engine' => $query['engine'],
                        'start' => $start,
                        'exception_class' => $e::class,
                    ]);

                    continue;
                }

                if ($transportReserved) {
                    $attempts++;
                    $attemptedThisRound = true;
                }

                if ($execution->replayed || $execution->response === null) {
                    $snapshot = $this->snapshot($run->fresh());
                    continue;
                }

                [$pageCandidates, $exhausted, $nextStart, $nextParams, $resultCount] = $this->parsePage(
                    $criteria,
                    $query,
                    $execution,
                    $snapshot,
                );

                $committed = $this->appendPage(
                    $criteria,
                    $run,
                    $queries,
                    $query,
                    $start,
                    $pageCandidates,
                    $exhausted,
                    $nextStart,
                    $nextParams,
                    $execution,
                    $resultCount,
                );
                if (! $committed) {
                    return [$this->snapshot($run->fresh()), false];
                }

                $snapshot = $this->snapshot($run->fresh());

                $this->pauseBetweenProviderRequests($workDeadlineAt);
            }

            if (! $attemptedThisRound) {
                break;
            }
        }

        return [$snapshot, false];
    }

    /**
     * @param  list<array{q: string, engine: string}>  $queries
     * @param  array<string, mixed>  $cursors
     */
    private function isLiveCollectionTerminal(
        DiscoveryRun $run,
        int $searchBudget,
        array $queries,
        array $cursors,
    ): bool {
        $reserved = (int) ($run->searches_reserved ?? $run->credits_reserved ?? 0);
        $consumed = (int) ($run->searches_consumed ?? 0);

        if ($consumed >= $reserved) {
            return true;
        }

        if ($queries === []) {
            return false;
        }

        foreach ($queries as $query) {
            $cursor = $cursors[$this->queryKey($query)] ?? null;
            if (! is_array($cursor) || ! (bool) ($cursor['exhausted'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function requestTimeoutSeconds(?float $workDeadlineAt): ?int
    {
        if ($workDeadlineAt === null) {
            return 20;
        }

        $remaining = (int) floor($workDeadlineAt - microtime(true));

        return $remaining < 1 ? null : min(20, $remaining);
    }

    private function pauseBetweenProviderRequests(?float $workDeadlineAt): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $delayMicros = 600_000;

        if ($workDeadlineAt !== null) {
            $remainingMicros = (int) floor(($workDeadlineAt - microtime(true)) * 1_000_000);
            // Preserve the full second required to start another provider call.
            $delayMicros = min($delayMicros, max(0, $remainingMicros - 1_000_000));
        }

        if ($delayMicros > 0) {
            usleep($delayMicros);
        }
    }

    /**
     * @param  list<array{q: string, engine: string}>  $queries
     */
    private function discoverFromSerpApiWithoutCursor(ProspectCriteria $criteria, array $queries, int $max): array
    {
        $results = [];
        $seen = [];

        foreach ($queries as $query) {
            if (count($results) >= $max) {
                break;
            }

            try {
                $execution = $this->fetchPage(
                    $this->searchContext(null, $query, 0, []),
                    $query,
                    0,
                );
                if ($execution->replayed || $execution->response === null) {
                    continue;
                }

                $page = $this->rawPageCandidates($query, $execution);
                DB::transaction(fn () => $this->providerCalls->settle(
                    $execution,
                    $this->normalizedResultCount($query, $execution),
                    1,
                ));

                foreach ($page as $candidate) {
                    if (count($results) >= $max) {
                        break;
                    }

                    if (isset($seen[$candidate['domain']])) {
                        continue;
                    }

                    $seen[$candidate['domain']] = true;
                    $results[] = $candidate;
                }
            } catch (ProviderRequestException $exception) {
                Log::channel('discovery')->warning('[CompanyDiscoveryService] SerpAPI request failed', [
                    'criteria_id' => $criteria->id,
                    'query' => $query['q'],
                    'engine' => $query['engine'],
                    'status' => $exception->httpStatus,
                    'error_code' => $exception->safeCode,
                ]);
            } catch (\Throwable $e) {
                Log::channel('discovery')->warning('[CompanyDiscoveryService] SerpAPI call threw an exception', [
                    'criteria_id' => $criteria->id,
                    'query' => $query['q'],
                    'engine' => $query['engine'],
                    'exception_class' => $e::class,
                ]);
            }

            // Polite rate-limiting between queries (~600 ms)
            usleep(600_000);
        }

        return $results;
    }

    /**
     * Expand every enabled, unique criteria query across the globally selected
     * discovery engines. Legacy per-query engine keys are intentionally ignored.
     *
     * @return list<array{q: string, engine: string}>
     */
    private function enabledQueries(ProspectCriteria $criteria): array
    {
        $rows = ! empty($criteria->ai_queries)
            ? collect($criteria->ai_queries)
                ->filter(fn ($r) => is_array($r) && ($r['enabled'] ?? true) === true)
                ->map(fn (array $r) => $r['q'] ?? null)
                ->all()
            : $this->buildQueries($criteria);

        $queries = [];
        $texts = [];

        foreach ($rows as $q) {
            if (! is_string($q) || trim($q) === '') {
                continue;
            }
            $texts[trim($q)] = true;
        }

        foreach (array_keys($texts) as $q) {
            foreach ($this->engines->selected() as $engine) {
                $queries[] = ['q' => $q, 'engine' => $engine];
            }
        }

        return $queries;
    }

    private function normaliseEngine(mixed $engine): string
    {
        return in_array($engine, $this->engines->ids(), true) ? $engine : self::ENGINE_GOOGLE;
    }

    /**
     * Stable cursor key for a query.
     *
     * Organic queries keep the historical md5($q) key UNCHANGED so every cursor already
     * stored in prospect_criteria.discovery_cursors keeps pointing at its query — hashing
     * the engine unconditionally would silently reset pagination for every criteria.
     * Maps queries get an engine-prefixed key: the same text on a different engine is a
     * genuinely different result stream and must paginate independently.
     *
     * @param  array{q: string, engine: string}  $query
     */
    private function queryKey(array $query): string
    {
        $engine = $this->normaliseEngine($query['engine'] ?? null);

        return $engine === self::ENGINE_GOOGLE
            ? md5($query['q'])
            : md5($engine.':'.$query['q']);
    }

    /**
     * Read-only cursor state for the query-preview card, grouped by query text.
     * Only streams with real state are returned (started or exhausted).
     *
     * @param  list<string>  $queryTexts
     * @return array<string, list<array{engine: string, label: string, page: int, exhausted: bool}>>
     */
    public function cursorPreview(ProspectCriteria $criteria, array $queryTexts): array
    {
        $stored = is_array($criteria->discovery_cursors) ? $criteria->discovery_cursors : [];
        $preview = [];

        foreach ($queryTexts as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $chips = [];
            foreach ($this->engines->selected() as $engine) {
                $cursor = $stored[$this->queryKey(['q' => trim($text), 'engine' => $engine])] ?? null;
                if (! is_array($cursor)) {
                    continue;
                }

                $exhausted = (bool) ($cursor['exhausted'] ?? false);
                $start = max(0, (int) ($cursor['start'] ?? 0));
                if (! $exhausted && $start === 0) {
                    continue;
                }

                $chips[] = [
                    'engine' => $engine,
                    'label' => $this->engines->get($engine)->label(),
                    'page' => intdiv($start, $this->pageSizeFor($engine)) + 1,
                    'exhausted' => $exhausted,
                ];
            }

            if ($chips !== []) {
                $preview[$text] = $chips;
            }
        }

        return $preview;
    }

    /**
     * Results per page for the given engine. Organic returns 10; Google Maps returns 20
     * and its serpapi_pagination.next advances &start by 20.
     */
    private function pageSizeFor(string $engine): int
    {
        return $this->engines->get($this->normaliseEngine($engine))->pageSize();
    }

    /**
     * Every current query text for the criteria — enabled AND disabled rows alike.
     * Used only to decide which discovery_cursors entries are still relevant (see
     * the orphan prune in normaliseCursors() below); falls back to buildQueries()
     * same as enabledQueries() so a criteria without saved ai_queries doesn't have
     * every cursor pruned.
     *
     * @return array<string, true> keyed by trimmed query text for O(1) lookup
     */
    private function currentQueryTexts(ProspectCriteria $criteria): array
    {
        $rows = ! empty($criteria->ai_queries)
            ? collect($criteria->ai_queries)
                ->filter(fn ($r) => is_array($r))
                ->map(fn (array $r) => $r['q'] ?? null)
                ->all()
            : $this->buildQueries($criteria);

        $texts = [];
        foreach ($rows as $q) {
            if (is_string($q) && trim($q) !== '') {
                $texts[trim($q)] = true;
            }
        }

        return $texts;
    }

    /**
     * @param  list<array{q: string, engine: string}>  $queries
     * @return array<string, mixed>
     */
    private function normaliseCursors(ProspectCriteria $criteria, array $queries): array
    {
        $stored = is_array($criteria->discovery_cursors) ? $criteria->discovery_cursors : [];
        $validQueryTexts = $this->currentQueryTexts($criteria);
        $validKeys = [];
        $cursors = [];

        foreach ($queries as $query) {
            $key = $this->queryKey($query);
            $validKeys[$key] = true;
            $existing = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $adapter = $this->engines->get($query['engine']);

            $cursors[$key] = [
                'q' => $query['q'],
                'engine' => $query['engine'],
                'start' => max(0, (int) ($existing['start'] ?? 0)),
                'exhausted' => (bool) ($existing['exhausted'] ?? false),
                'provider_params' => $adapter->sanitizeCursorParams(
                    is_array($existing['provider_params'] ?? null) ? $existing['provider_params'] : []
                ),
            ];
        }

        foreach ($stored as $storedKey => $storedCursor) {
            if ($storedKey === '_rotation' || isset($cursors[$storedKey]) || ! is_array($storedCursor)) {
                continue;
            }

            // A stream not among the currently active $queries is only kept when its
            // query text still exists among the criteria's ai_queries (e.g. disabled,
            // or its engine was deselected) — otherwise it's an orphan left behind by
            // an ai_target/ai_exclude edit that regenerated ai_queries with new md5
            // keys, and the cursor blob would otherwise grow forever.
            $storedText = is_string($storedCursor['q'] ?? null) ? $storedCursor['q'] : '';
            if (trim($storedText) === '' || ! isset($validQueryTexts[trim($storedText)])) {
                continue;
            }

            $storedEngine = $this->normaliseEngine($storedCursor['engine'] ?? null);
            $cursors[$storedKey] = [
                'q' => $storedText,
                'engine' => $storedEngine,
                'start' => max(0, (int) ($storedCursor['start'] ?? 0)),
                'exhausted' => (bool) ($storedCursor['exhausted'] ?? false),
                'provider_params' => $this->engines->get($storedEngine)->sanitizeCursorParams(
                    is_array($storedCursor['provider_params'] ?? null) ? $storedCursor['provider_params'] : []
                ),
            ];
        }

        $rotation = $stored['_rotation'] ?? null;
        if (is_string($rotation) && isset($validKeys[$rotation])) {
            $cursors['_rotation'] = $rotation;
        }

        // A completed collection's marker is a bare int, not a cursor array, so the
        // loop above never touches it — but it must still survive round-tripping
        // through normaliseCursors() or the next write here would silently drop it.
        $done = $stored[ProspectCriteria::DISCOVERY_COLLECTION_COMPLETE_RUN_KEY] ?? null;
        if (is_numeric($done)) {
            $cursors[ProspectCriteria::DISCOVERY_COLLECTION_COMPLETE_RUN_KEY] = (int) $done;
        }

        return $cursors;
    }

    /**
     * @param  list<array{q: string, engine: string}>  $queries
     * @param  array<string, mixed>  $cursors
     * @return list<array{q: string, engine: string}>
     */
    private function rotatedQueryOrder(array $queries, array $cursors): array
    {
        if ($queries === []) {
            return [];
        }

        $keys = array_map(fn (array $query) => $this->queryKey($query), $queries);
        $last = $cursors['_rotation'] ?? null;
        $index = is_string($last) ? array_search($last, $keys, true) : false;
        $start = $index === false ? 0 : ($index + 1) % count($queries);

        return array_values(array_merge(
            array_slice($queries, $start),
            array_slice($queries, 0, $start)
        ));
    }

    /**
     * Issue one engine-appropriate SerpAPI search page.
     *
     * google_maps params verified live 2026-07-19 (STATUS 200):
     * GET https://serpapi.com/search.json?engine=google_maps&type=search&q=fabricant+textile+Casablanca&hl=fr
     * → top-level keys search_metadata, search_parameters, search_information, local_results,
     *   serpapi_pagination. local_results held 20 items (page size 20, NOT 10 like organic),
     *   16 of which carried a non-empty `website`. Item keys include title, address, phone,
     *   country, type, types, rating, reviews, gps_coordinates, website.
     *   serpapi_pagination.next advances &start by 20.
     *
     * @param  array{q: string, engine: string}  $query
     */
    private function fetchPage(
        ProviderCallContext $context,
        array $query,
        int $start,
        array $providerParams = [],
        ?Closure $reserve = null,
        int $timeoutSeconds = 20,
    ): ProviderExecution {
        $engine = $this->normaliseEngine($query['engine'] ?? null);
        $parameters = $this->searchParameters($engine, $query['q'], $start, $providerParams);

        if ($reserve !== null) {
            return $this->serpApi->searchWithReservation(
                $context,
                $engine,
                $query['q'],
                $start,
                $parameters,
                $reserve,
                $timeoutSeconds,
            );
        }

        return $this->serpApi->search(
            $context,
            $engine,
            $query['q'],
            $start,
            $parameters,
        );
    }

    /** @return array<string, int|string> */
    private function searchParameters(
        string $engine,
        string $query,
        int $start,
        array $providerParams,
    ): array {
        $parameters = $this->engines->get($engine)->params($query, $start, $providerParams);
        unset(
            $parameters['engine'],
            $parameters['q'],
            $parameters['start'],
            $parameters['first'],
            $parameters['api_key'],
            $parameters['async'],
            $parameters['no_cache'],
        );
        ksort($parameters);

        return $parameters;
    }

    /** @param array{q: string, engine: string} $query */
    private function searchContext(
        ?DiscoveryRun $run,
        array $query,
        int $start,
        array $providerParams,
    ): ProviderCallContext {
        $engine = $this->normaliseEngine($query['engine'] ?? null);
        $canonical = [
            'provider' => 'serpapi',
            'operation' => $engine,
            'run_id' => $run?->getKey(),
            'query' => $query['q'],
            'start' => $start,
            'parameters' => $this->searchParameters($engine, $query['q'], $start, $providerParams),
        ];

        // Cursorless legacy discovery has no durable business row to replay;
        // give each invocation its own audit call. Run-backed calls remain stable.
        if ($run === null) {
            $canonical['invocation'] = (string) Str::uuid();
        }

        return new ProviderCallContext(
            hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            // DiscoveryRun already owns the live quota reservation/consumption.
            // A zero-unit provider call audits transport without charging twice.
            $run === null && ! $this->isLocal() ? 1 : 0,
            engine: $engine,
        );
    }

    /**
     * Turn a fetched page into filtered candidates + the exhausted flag.
     *
     * @param  array{q: string, engine: string}  $query
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: ?int, 3: array<string, string>, 4: int}
     */
    private function parsePage(ProspectCriteria $criteria, array $query, ProviderExecution $execution, array $snapshot): array
    {
        $engine = $this->normaliseEngine($query['engine'] ?? null);
        $payload = $execution->response?->data ?? [];
        $pageResult = $this->engines->get($engine)->parse(is_array($payload) ? $payload : [], $query['q']);

        $page = $this->dedupeAgainstSnapshot($this->rawPageCandidates($query, $execution), $snapshot);

        return [
            $this->rejectKnownDomains($criteria, $page),
            $pageResult->exhausted,
            $pageResult->nextStart,
            $pageResult->nextParams,
            count($pageResult->candidates),
        ];
    }

    /**
     * Engine-specific normalisation into the candidate contract. No dedup, no DB.
     *
     * @param  array{q: string, engine: string}  $query
     * @return list<array<string, mixed>>
     */
    private function rawPageCandidates(array $query, ProviderExecution $execution): array
    {
        $engine = $this->normaliseEngine($query['engine'] ?? null);
        $payload = $execution->response?->data ?? [];
        $parsed = $this->engines->get($engine)->parse(is_array($payload) ? $payload : [], $query['q']);
        $page = [];

        foreach ($parsed->candidates as $candidate) {
            $url = $candidate['url'] ?? null;
            $domain = is_string($url) ? $this->extractDomain($url) : null;
            if ($domain === null) {
                continue;
            }

            // The adapter's staging contract is richer and intentionally keeps
            // rows without domains. This legacy pipeline remains domain-only,
            // so trim staging-only keys only after the complete page was parsed.
            $legacy = [
                'domain' => $domain,
                'title' => $candidate['title'] ?? null,
                'snippet' => $candidate['snippet'] ?? null,
                'url' => $url,
                'discovery_query' => $candidate['discovery_query'] ?? $query['q'],
            ];
            foreach (['phone', 'country', 'sector_hint'] as $optional) {
                if (isset($candidate[$optional]) && $candidate[$optional] !== '') {
                    $legacy[$optional] = $candidate[$optional];
                }
            }
            $page[] = $legacy;
        }

        return $page;
    }

    /** @param array{q: string, engine: string} $query */
    private function normalizedResultCount(array $query, ProviderExecution $execution): int
    {
        $engine = $this->normaliseEngine($query['engine'] ?? null);
        $payload = $execution->response?->data ?? [];

        return count($this->engines->get($engine)->parse(
            is_array($payload) ? $payload : [],
            $query['q'],
        )->candidates);
    }

    /**
     * Normalise SerpAPI google_maps local_results into the candidate contract, plus the
     * optional phone/country/sector_hint keys the enrichment path consumes as fallbacks.
     *
     * Results with no `website`, or whose website fails extractDomain() (blocklisted host,
     * document URL, unparseable), are skipped — a Maps entry without a reachable site is
     * not a prospectable company.
     *
     * @return list<array<string, mixed>>
     */
    private function mapMapsResults(array $localResults, ?string $query): array
    {
        $normalized = $this->engines->get(self::ENGINE_GOOGLE_MAPS)->parse(
            ['local_results' => $localResults],
            $query ?? '',
        )->candidates;
        $page = [];

        foreach ($normalized as $candidate) {
            $url = $candidate['url'] ?? null;
            $domain = is_string($url) ? $this->extractDomain($url) : null;
            if ($domain === null) {
                continue;
            }

            $legacy = [
                'domain' => $domain,
                'title' => $candidate['title'] ?? null,
                'snippet' => $candidate['snippet'] ?? null,
                'url' => $url,
                'discovery_query' => $query,
            ];
            foreach (['phone', 'country', 'sector_hint'] as $optional) {
                if (isset($candidate[$optional]) && $candidate[$optional] !== '') {
                    $legacy[$optional] = $candidate[$optional];
                }
            }

            $page[] = $legacy;
        }

        return $page;
    }

    /**
     * Drop candidates whose domain already appears in the run snapshot or earlier in
     * the same page.
     *
     * @param  list<array<string, mixed>>  $page
     * @return list<array<string, mixed>>
     */
    private function dedupeAgainstSnapshot(array $page, array $snapshot): array
    {
        $seen = [];

        foreach ($snapshot as $candidate) {
            if (is_array($candidate) && ! empty($candidate['domain'])) {
                $seen[$candidate['domain']] = true;
            }
        }

        $deduped = [];

        foreach ($page as $candidate) {
            $domain = $candidate['domain'] ?? null;

            if (! $domain || isset($seen[$domain])) {
                continue;
            }

            $seen[$domain] = true;
            $deduped[] = $candidate;
        }

        return $deduped;
    }

    /**
     * Drop candidates that are already a visible Company, or already rejected under
     * THIS criteria. Shared by the organic and google_maps paths.
     *
     * @param  list<array<string, mixed>>  $page
     * @return list<array<string, mixed>>
     */
    private function rejectKnownDomains(ProspectCriteria $criteria, array $page): array
    {
        if ($page === []) {
            return [];
        }

        $pageDomains = array_values(array_unique(array_column($page, 'domain')));

        $visibleDomains = Company::whereIn('domain', $pageDomains)
            ->pluck('domain')
            ->all();

        $rejectedHere = Company::withRejected()
            ->whereIn('domain', $pageDomains)
            ->where('criteria_id', $criteria->id)
            ->where('qualification_status', 'rejected')
            ->pluck('domain')
            ->all();

        $blocked = array_fill_keys(array_merge($visibleDomains, $rejectedHere), true);

        return array_values(array_filter(
            $page,
            fn (array $candidate) => ! isset($blocked[$candidate['domain']])
        ));
    }

    /**
     * @param  list<array{q: string, engine: string}>  $queries
     * @param  array{q: string, engine: string}  $query
     * @param  list<array<string, mixed>>  $pageCandidates
     */
    private function appendPage(
        ProspectCriteria $criteria,
        DiscoveryRun $run,
        array $queries,
        array $query,
        int $expectedStart,
        array $pageCandidates,
        bool $exhausted,
        ?int $nextStart,
        array $nextParams,
        ProviderExecution $execution,
        int $resultCount,
    ): bool {
        return DB::transaction(function () use ($criteria, $run, $queries, $query, $expectedStart, $pageCandidates, $exhausted, $nextStart, $nextParams, $execution, $resultCount) {
            /** @var ProspectCriteria|null $lockedCriteria */
            $lockedCriteria = ProspectCriteria::whereKey($criteria->id)->lockForUpdate()->first();
            /** @var DiscoveryRun|null $lockedRun */
            $lockedRun = DiscoveryRun::whereKey($run->id)->lockForUpdate()->first();

            if (! $lockedCriteria || ! $lockedRun || $lockedRun->status !== 'running') {
                $this->providerCalls->settle($execution, max(0, $resultCount), 0, [
                    'reason' => 'business_state_changed',
                    'offset' => $expectedStart,
                    'query_hash' => hash('sha256', $query['q']),
                ]);

                return false;
            }

            $cursors = $this->normaliseCursors($lockedCriteria, $queries);
            $key = $this->queryKey($query);
            $storedStart = (int) ($cursors[$key]['start'] ?? 0);

            if ($storedStart !== $expectedStart) {
                $this->providerCalls->settle($execution, max(0, $resultCount), 0, [
                    'reason' => 'cursor_mismatch',
                    'offset' => $expectedStart,
                    'query_hash' => hash('sha256', $query['q']),
                ]);

                return false;
            }

            $snapshot = $this->snapshot($lockedRun);
            $seen = [];
            foreach ($snapshot as $candidate) {
                if (is_array($candidate) && ! empty($candidate['domain'])) {
                    $seen[$candidate['domain']] = true;
                }
            }

            foreach ($pageCandidates as $candidate) {
                $domain = $candidate['domain'] ?? null;
                if (! $domain || isset($seen[$domain])) {
                    continue;
                }

                $seen[$domain] = true;
                $snapshot[] = $candidate;
            }

            // SerpAPI's next URL is authoritative. Bing page sizes vary, so adding
            // a fixed page size would overlap or skip results.
            $cursors[$key] = [
                'q' => $query['q'],
                'engine' => $query['engine'],
                'start' => $nextStart ?? $expectedStart,
                'exhausted' => $exhausted,
                'provider_params' => $this->engines->get($query['engine'])->sanitizeCursorParams($nextParams),
            ];
            $cursors['_rotation'] = $key;

            $lockedRun->forceFill(['candidates_snapshot' => array_values($snapshot)])->save();
            $lockedCriteria->forceFill(['discovery_cursors' => $cursors])->save();
            $this->providerCalls->settle(
                $execution,
                max(0, $resultCount),
                // The legacy DiscoveryRun counter above remains the sole quota
                // debit. The provider ledger is an idempotent transport audit.
                0,
                [
                    'offset' => $expectedStart,
                    'query_hash' => hash('sha256', $query['q']),
                ],
            );

            return true;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(?DiscoveryRun $run): array
    {
        if ($run === null || ! is_array($run->candidates_snapshot)) {
            return [];
        }

        return array_values(array_filter(
            $run->candidates_snapshot,
            fn ($candidate) => is_array($candidate) && ! empty($candidate['domain'])
        ));
    }

    private function reserveSerpApiAttempt(DiscoveryRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $locked = DiscoveryRun::whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'running') {
                return false;
            }

            $reserved = (int) ($locked->searches_reserved ?? $locked->credits_reserved ?? 0);
            $consumed = (int) ($locked->searches_consumed ?? 0);
            if ($consumed >= $reserved) {
                return false;
            }

            $locked->searches_consumed = $consumed + 1;
            $locked->save();

            return true;
        });
    }

    /**
     * Rotate immediately after reserving an attempt, before network I/O. The page
     * cursor is left untouched so a failed stream retries the same provider page,
     * while the next invocation starts from the following stream.
     *
     * @param  list<array{q: string, engine: string}>  $queries
     * @param  array{q: string, engine: string}  $query
     */
    private function recordAttemptRotation(ProspectCriteria $criteria, array $queries, array $query): void
    {
        DB::transaction(function () use ($criteria, $queries, $query): void {
            $locked = ProspectCriteria::whereKey($criteria->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            $cursors = $this->normaliseCursors($locked, $queries);
            $cursors['_rotation'] = $this->queryKey($query);
            $locked->forceFill(['discovery_cursors' => $cursors])->save();
        });
    }

    /**
     * Build SerpAPI search queries from criteria.
     *
     * Pure criteria → string[] function; no side effects, no HTTP.
     * Public visibility enables unit testing and the future preview endpoint (D12).
     *
     * Falls back to France + Maroc (sensible TCL defaults) when countries is empty.
     * ISO-2 codes are mapped to French labels via config('global.data.company_countries').
     * Legacy free-text values (e.g. "France") and unknown codes pass through unchanged.
     *
     * Result is sliced to config('services.serpapi.max_queries_per_run', 40) — D10.
     *
     * @return list<string>
     */
    public function buildQueries(ProspectCriteria $criteria): array
    {
        $sectors = $criteria->sectors ?? [];
        $countries = $criteria->countries ?? [];

        // TCL France context: freight-forwarding defaults
        $freightKeywords = [
            'transitaire',
            'freight forwarder',
            'commissionnaire de transport',
            'logistique',
            'transport maritime',
            'transport aérien',
        ];

        $queries = [];

        // ISO-2 → French label mapping (D10 / D12).
        // Legacy free-text ("France") and unknown codes pass through unchanged.
        $labels = config('global.data.company_countries', []);
        $raw = ! empty($countries) ? $countries : ['France', 'Maroc'];

        $countryTokens = array_map(function ($v) use ($labels) {
            if (isset($labels[$v])) {
                // ISO code → French label
                return $labels[$v];
            }
            // Passthrough: already a label or junk.
            // Log at debug only when $v is neither a key NOR a value in company_countries.
            if (! in_array($v, $labels, true)) {
                Log::channel('discovery')->debug('[CompanyDiscoveryService] Unrecognised country token — passing through.', [
                    'token' => $v,
                ]);
            }

            return $v;
        }, $raw);

        foreach ($countryTokens as $country) {
            // Sector-specific queries
            foreach ($sectors as $sector) {
                $queries[] = "{$sector} transitaire {$country}";
                $queries[] = "{$sector} freight forwarder {$country}";
            }

            // Freight-keyword queries
            foreach ($freightKeywords as $kw) {
                $queries[] = "{$kw} {$country} entreprise";
            }
        }

        $queries = array_values(array_unique(array_filter($queries)));

        // D10: budget slice — cap query list to avoid 700+ queries from UE-27 × sectors.
        // 27 countries × (6 keywords + 2×sectors) can exceed 700 × 600 ms.
        // daily_limit caps results, not queries; the budget caps API consumption.
        $budget = (int) config('services.serpapi.max_queries_per_run', 40);
        $total = count($queries);
        if ($total > $budget) {
            $dropped = $total - $budget;
            Log::channel('discovery')->info('[CompanyDiscoveryService] Query budget exceeded — dropping queries.', [
                'criteria_id' => $criteria->id,
                'total' => $total,
                'budget' => $budget,
                'dropped' => $dropped,
            ]);
            $queries = array_slice($queries, 0, $budget);
        }

        return $queries;
    }

    private function isLocal(): bool
    {
        return config('services.serpapi.driver', 'local') === 'local';
    }
}
