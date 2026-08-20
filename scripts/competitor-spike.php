<?php

/**
 * scripts/competitor-spike.php
 *
 * Empirical-verification diagnostic (CLAUDE.md §1) for the B0 "competitors of
 * our clients" spike — probes three live providers (Hunter discover
 * similar_to, SerpAPI "concurrents de <name>", Gemini) against five
 * hardcoded seed companies to compare precision, count-per-seed, and
 * credits-per-seed BEFORE any feature code is written.
 *
 * READ-ONLY apart from one deliberate diagnostic row: probe 2 calls the real
 * HunterClient::discover() through ProviderCallLedger, which always writes a
 * provider_calls row and settles it. Every other probe bypasses the ledger
 * entirely (raw Http:: calls, or GeminiClient which has no DB dependency).
 * No Eloquent writes, no save()/create(), no artisan calls, no migrations.
 *
 * Run via:
 *   php artisan tinker --execute "require 'scripts/competitor-spike.php';"
 *
 * Never prints the Hunter, SerpAPI, or Gemini API key — only HTTP status +
 * truncated response bodies.
 */

// ── EDIT BEFORE RUNNING ─────────────────────────────────────────────────────
$SPIKE_SEEDS = [
    ['name' => 'Promedic',    'domain' => 'promedic-healthcare.ma', 'sector' => 'Materiel medical',        'country' => 'MA'],
    ['name' => 'Manorbois',   'domain' => 'manorbois.com',          'sector' => 'Negociant en bois',        'country' => 'MA'],
    ['name' => 'Geissmann',   'domain' => 'geissmann.ma',           'sector' => 'Equipements industriels',  'country' => 'MA'],
    ['name' => 'Matrelec',    'domain' => 'matrelec.ma',            'sector' => 'Materiel electrique',      'country' => 'MA'],
    ['name' => 'Baticomptoir', 'domain' => 'baticomptoir.com',      'sector' => 'Materiaux de construction', 'country' => 'MA'],
];
// ─────────────────────────────────────────────────────────────────────────────

function competitor_spike_probe(string $label, callable $body): void
{
    echo "PROBE {$label}\n";
    try {
        $body();
    } catch (\Throwable $e) {
        echo "STATUS: ERROR\n";
        echo 'BODY: ' . substr($e->getMessage(), 0, 1200) . "\n";
    }
    echo "\n";
}

function competitor_spike_organic_domain(string $link): ?string
{
    $host = parse_url(trim($link), PHP_URL_HOST);
    if (! is_string($host) || $host === '') {
        return null;
    }

    return preg_replace('/^www\./i', '', strtolower(rtrim($host, '.'))) ?: null;
}

// ── Probe 0 — seed sanity ───────────────────────────────────────────────────
foreach ($SPIKE_SEEDS as $seed) {
    competitor_spike_probe("0 seed sanity ({$seed['name']} / {$seed['domain']})", function () use ($seed) {
        $response = \Illuminate\Support\Facades\Http::timeout(10)->head('https://' . $seed['domain']);
        echo 'STATUS: ' . $response->status() . "\n";
    });
}

// ── Probe 1 — Hunter discover + similar_to, RAW (bypass HunterClient) ──────
$hunterApiKey = (string) config('services.hunter.api_key');
$hunterBaseUrl = rtrim((string) config('services.hunter.base_url'), '/');
$probe1Domains = [];    // seed domain => [returned company domains]
$probe1RowCounts = [];  // seed domain => row count

foreach ($SPIKE_SEEDS as $i => $seed) {
    competitor_spike_probe("1 Hunter discover similar_to ({$seed['name']} / {$seed['domain']})", function () use ($seed, $hunterApiKey, $hunterBaseUrl, &$probe1Domains, &$probe1RowCounts) {
        $response = \Illuminate\Support\Facades\Http::withToken($hunterApiKey)->acceptJson()->timeout(30)
            ->post($hunterBaseUrl . '/discover', [
                'similar_to' => ['domain' => $seed['domain']],
                'limit' => 25,
            ]);
        echo 'STATUS: ' . $response->status() . "\n";

        $body = $response->json() ?? [];
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $errors = $body['errors'] ?? null;

        echo 'meta.filters: ' . json_encode($meta['filters'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
        echo 'meta.results: ' . ($meta['results'] ?? 'n/a') . "\n";
        echo 'data_count: ' . count($data) . "\n";

        foreach (array_slice($data, 0, 10) as $row) {
            $domain = is_array($row) ? ($row['domain'] ?? 'n/a') : 'n/a';
            $org = is_array($row) ? ($row['organization'] ?? 'n/a') : 'n/a';
            $industry = is_array($row) ? ($row['industry'] ?? 'n/a') : 'n/a';
            $hq = is_array($row) ? ($row['headquarters'] ?? 'n/a') : 'n/a';
            echo "  {$domain} | {$org} | {$industry} | " . (is_scalar($hq) ? $hq : json_encode($hq, JSON_UNESCAPED_UNICODE)) . "\n";
        }

        foreach ($data as $row) {
            if (is_array($row) && isset($row['domain']) && is_string($row['domain'])) {
                $probe1Domains[$seed['domain']][] = strtolower($row['domain']);
            }
        }
        $probe1RowCounts[$seed['domain']] = count($data);

        if ($errors !== null) {
            echo 'errors: ' . substr(json_encode($errors, JSON_UNESCAPED_UNICODE), 0, 1200) . "\n";
        }
    });

    if ($i < count($SPIKE_SEEDS) - 1) {
        sleep(1);
    }
}

// ── Probe 2 — wrapper passthrough, seed #1 only ─────────────────────────────
competitor_spike_probe("2 Hunter wrapper passthrough ({$SPIKE_SEEDS[0]['name']} / {$SPIKE_SEEDS[0]['domain']})", function () use ($SPIKE_SEEDS, $probe1RowCounts) {
    $seed = $SPIKE_SEEDS[0];
    $ledger = app(\App\Services\Providers\ProviderCallLedger::class);
    $hunterClient = app(\App\Services\Providers\Hunter\HunterClient::class);

    try {
        $context = new \App\Services\Providers\ProviderCallContext(
            hash('sha256', 'spike|' . $seed['domain'] . '|' . time()),
            0,
            engine: 'discover',
        );

        $execution = $hunterClient->discover($context, [
            'similar_to' => ['domain' => $seed['domain']],
            'limit' => 25,
        ]);

        $response = $execution->response;
        $rows = $response?->data ?? [];
        $rowCount = count($rows);

        echo 'STATUS: ' . ($response?->httpStatus ?? 'n/a') . "\n";
        echo "row_count: {$rowCount}\n";
        echo 'probe1_row_count_same_seed: ' . ($probe1RowCounts[$seed['domain']] ?? 'n/a') . "\n";

        if (! $execution->replayed && $response !== null) {
            // settle() alone, NOT wrapping discover() — a savepoint rollback
            // on a Hunter 4xx inside an outer transaction would erase the
            // diagnostic provider_calls row entirely (consultant finding).
            \Illuminate\Support\Facades\DB::transaction(function () use ($ledger, $execution, $rowCount) {
                $ledger->settle($execution, $rowCount, 0.0, []);
            });
            echo "settled: yes\n";
        } else {
            echo "settled: skipped (replayed=" . ($execution->replayed ? 'true' : 'false') . ")\n";
        }
    } catch (\App\Services\Providers\ProviderRequestException $e) {
        echo "STATUS: ERROR\n";
        echo "safeCode: {$e->safeCode}\n";
        echo 'httpStatus: ' . ($e->httpStatus ?? 'null') . "\n";
    }
});

// ── Probe 3 — SerpAPI "concurrents de <name>", raw ──────────────────────────
$serpApiKey = (string) config('services.serpapi.api_key');
$noiseDirectory = [
    'societe.com', 'pappers.fr', 'verif.com', 'infogreffe.fr', 'linkedin.com',
    'wikipedia.org', 'indeed.com', 'usinenouvelle.com', 'glassdoor.com', 'glassdoor.fr',
    'youtube.com', 'facebook.com', 'bloomberg.com', 'zoominfo.com', 'crunchbase.com', 'owler.com',
];
$probe3RowCounts = [];   // seed name => organic_results count
$probe3UsableCounts = []; // seed name => non-noise domain count
$probe3NoiseShares = [];  // seed name => noise share

foreach ($SPIKE_SEEDS as $i => $seed) {
    competitor_spike_probe("3 SerpAPI concurrents ({$seed['name']})", function () use ($seed, $serpApiKey, $noiseDirectory, &$probe3RowCounts, &$probe3UsableCounts, &$probe3NoiseShares) {
        $query = 'concurrents de ' . $seed['name'];
        $response = \Illuminate\Support\Facades\Http::timeout(30)->get('https://serpapi.com/search.json', [
            'engine' => 'google',
            'q' => $query,
            'hl' => 'fr',
            'gl' => 'fr',
            'num' => 10,
            'api_key' => $serpApiKey,
        ]);
        echo 'STATUS: ' . $response->status() . "\n";
        echo "query: {$query}\n";

        $body = $response->json() ?? [];
        $organic = is_array($body['organic_results'] ?? null) ? $body['organic_results'] : [];
        $probe3RowCounts[$seed['name']] = count($organic);
        echo 'organic_results_count: ' . count($organic) . "\n";

        $domains = [];
        foreach ($organic as $item) {
            $link = is_array($item) ? ($item['link'] ?? null) : null;
            if (! is_string($link)) {
                continue;
            }
            $domain = competitor_spike_organic_domain($link);
            if ($domain === null) {
                continue;
            }
            $isNoise = in_array($domain, $noiseDirectory, true);
            $domains[] = $isNoise;
            echo '  ' . $domain . ($isNoise ? ' [NOISE]' : '') . "\n";
        }
        $noiseCount = count(array_filter($domains));
        $usableCount = count($domains) - $noiseCount;
        $probe3UsableCounts[$seed['name']] = $usableCount;
        $noiseShare = count($domains) > 0 ? round($noiseCount / count($domains), 2) : null;
        $probe3NoiseShares[$seed['name']] = $noiseShare;
        echo 'noise_share: ' . ($noiseShare ?? 'n/a') . "\n";

        echo 'has related_searches: ' . (isset($body['related_searches']) ? 'yes' : 'no') . "\n";
        if (isset($body['related_searches'])) {
            echo 'related_searches: ' . substr(json_encode($body['related_searches'], JSON_UNESCAPED_UNICODE), 0, 1200) . "\n";
        }
        echo 'has knowledge_graph: ' . (isset($body['knowledge_graph']) ? 'yes' : 'no') . "\n";
        if (isset($body['knowledge_graph'])) {
            echo 'knowledge_graph: ' . substr(json_encode($body['knowledge_graph'], JSON_UNESCAPED_UNICODE), 0, 1200) . "\n";
        }
    });

    if ($i < count($SPIKE_SEEDS) - 1) {
        sleep(1);
    }
}

// ── Probe 4 — Gemini ─────────────────────────────────────────────────────────
$geminiClient = new \App\Services\Gemini\GeminiClient();
$probe4RowCounts = [];          // seed name => competitor count returned
$probe4UsableCounts = [];       // seed name => resolved (non-hallucinated) count
$probe4HallucinationShares = []; // seed name => hallucination share

foreach ($SPIKE_SEEDS as $seed) {
    competitor_spike_probe("4 Gemini competitors ({$seed['name']})", function () use ($seed, $geminiClient, &$probe4RowCounts, &$probe4UsableCounts, &$probe4HallucinationShares) {
        $prompt = "Tu es un expert du secteur industriel et du fret. Voici une entreprise :\n"
            . "Nom : {$seed['name']}\n"
            . "Domaine : {$seed['domain']}\n"
            . "Secteur : {$seed['sector']}\n"
            . "Pays : {$seed['country']}\n\n"
            . "Liste jusqu'à 10 concurrents RÉELS et EXISTANTS de cette entreprise, jamais inventés. "
            . "Réponds uniquement avec un tableau JSON d'objets {\"nom\": ..., \"domaine\": ..., \"pourquoi\": ...}, "
            . "où \"domaine\" est le nom de domaine principal réel de chaque concurrent.";

        $response = $geminiClient->request($prompt, 30, ['response_mime_type' => 'application/json']);
        echo 'STATUS: ' . $response->status() . "\n";

        $text = $geminiClient->extractText($response);
        $data = $text !== null ? $geminiClient->decodeJson($text) : null;
        $competitors = is_array($data) ? $data : [];
        $probe4RowCounts[$seed['name']] = count($competitors);
        echo 'competitor_count: ' . count($competitors) . "\n";

        $resolved = 0;
        $total = 0;
        foreach ($competitors as $c) {
            $nom = is_array($c) ? ($c['nom'] ?? 'n/a') : 'n/a';
            $domaine = is_array($c) ? ($c['domaine'] ?? null) : null;
            if (! is_string($domaine) || trim($domaine) === '') {
                continue;
            }
            $total++;
            $ok = false;
            try {
                \Illuminate\Support\Facades\Http::timeout(10)->head('https://' . $domaine);
                $ok = true;
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $resolved++;
            }
            echo "  {$nom} | {$domaine} | " . ($ok ? 'resolved' : 'FAILED') . "\n";
        }
        $probe4UsableCounts[$seed['name']] = $resolved;
        $hallucinationShare = $total > 0 ? round(($total - $resolved) / $total, 2) : null;
        $probe4HallucinationShares[$seed['name']] = $hallucinationShare;
        echo "resolved: {$resolved}/{$total}\n";
        echo 'hallucination_share: ' . ($hallucinationShare ?? 'n/a') . "\n";
    });
}

// ── Summary ──────────────────────────────────────────────────────────────────
function competitor_spike_mean(array $values): ?float
{
    $values = array_filter($values, static fn ($v) => $v !== null);
    if ($values === []) {
        return null;
    }

    return round(array_sum($values) / count($values), 2);
}

echo "=== SUMMARY ===\n";

$hunterMeanRows = competitor_spike_mean(array_values($probe1RowCounts));
echo "Hunter discover    | mean rows/seed = " . ($hunterMeanRows ?? 'n/a')
    . " | mean usable rows/seed = " . ($hunterMeanRows ?? 'n/a')
    . " | noise/hallucination share = n/a"
    . " | credits/seed = 0 internal units (1 Hunter API call)\n";

$serpMeanRows = competitor_spike_mean(array_values($probe3RowCounts));
$serpMeanUsable = competitor_spike_mean(array_values($probe3UsableCounts));
$serpMeanNoise = competitor_spike_mean(array_values($probe3NoiseShares));
echo "SerpAPI concurrents | mean rows/seed = " . ($serpMeanRows ?? 'n/a')
    . " | mean usable rows/seed = " . ($serpMeanUsable ?? 'n/a')
    . " | noise share = " . ($serpMeanNoise ?? 'n/a')
    . " | credits/seed = 1 paid search\n";

$geminiMeanRows = competitor_spike_mean(array_values($probe4RowCounts));
$geminiMeanUsable = competitor_spike_mean(array_values($probe4UsableCounts));
$geminiMeanHallucination = competitor_spike_mean(array_values($probe4HallucinationShares));
echo "Gemini              | mean rows/seed = " . ($geminiMeanRows ?? 'n/a')
    . " | mean usable rows/seed = " . ($geminiMeanUsable ?? 'n/a')
    . " | hallucination share = " . ($geminiMeanHallucination ?? 'n/a')
    . " | credits/seed = unmetered\n";

echo "\nHunter probe 1 pairwise seed-overlap (shared domains between unrelated seed pairs):\n";
$seedDomainKeys = array_keys($probe1Domains);
for ($a = 0; $a < count($seedDomainKeys); $a++) {
    for ($b = $a + 1; $b < count($seedDomainKeys); $b++) {
        $domA = array_unique($probe1Domains[$seedDomainKeys[$a]] ?? []);
        $domB = array_unique($probe1Domains[$seedDomainKeys[$b]] ?? []);
        $overlap = count(array_intersect($domA, $domB));
        echo "  {$seedDomainKeys[$a]} vs {$seedDomainKeys[$b]}: {$overlap} shared domain(s)\n";
    }
}

echo "\nDONE - read-only probes complete\n";
