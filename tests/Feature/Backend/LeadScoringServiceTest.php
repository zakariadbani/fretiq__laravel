<?php

namespace Tests\Feature\Backend;

use App\Models\ProspectCriteria;
use App\Services\Scoring\GeminiScoringDriver;
use App\Services\Scoring\HeuristicScoringDriver;
use App\Services\Scoring\LeadScoringService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LeadScoringServiceTest — unit + integration tests for the lead-scoring layer.
 *
 * Tests service behaviour: heuristic scoring, Gemini driver fallback, fixture
 * invariant, and HTTP isolation using Http::fake().
 *
 * Database: fretiq_test (RefreshDatabase wraps each test).
 * No auth required — these tests call services directly.
 */
class LeadScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name'        => 'Test Scoring ' . uniqid(),
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 10,
            'is_active'   => true,
        ], $overrides));
    }

    private function makeSerpApiCandidate(array $entry): array
    {
        // serpapi.json uses "link" — normalize to match service expectations
        return [
            'url'     => $entry['link']    ?? '',
            'link'    => $entry['link']    ?? '',
            'title'   => $entry['title']   ?? '',
            'snippet' => $entry['snippet'] ?? '',
        ];
    }

    private function loadSerpapiFixture(): array
    {
        $path = base_path('database/fixtures/discovery/serpapi.json');
        $data = json_decode(file_get_contents($path), true);
        $this->assertIsArray($data, 'serpapi.json must be a JSON array');
        return $data;
    }

    // ── Heuristic: determinism ────────────────────────────────────────────────

    /**
     * Scoring the same candidate twice must produce identical results.
     */
    public function test_heuristic_is_deterministic(): void
    {
        $criteria  = $this->makeCriteria();
        $candidate = [
            'url'     => 'https://www.geodis.com/fr',
            'title'   => 'GEODIS — Commissionnaire de transport et logistique',
            'snippet' => 'GEODIS est un opérateur mondial de la supply chain offrant des solutions de transport, logistique et freight forwarding en Europe, Asie, Amériques.',
        ];

        /** @var HeuristicScoringDriver $driver */
        $driver  = app(HeuristicScoringDriver::class);
        $result1 = $driver->score($candidate, $criteria);
        $result2 = $driver->score($candidate, $criteria);

        $this->assertSame($result1['score'], $result2['score'],       'Score must be deterministic');
        $this->assertSame($result1['explanation'], $result2['explanation'], 'Explanation must be deterministic');
    }

    // ── Heuristic: freight candidate scores high ──────────────────────────────

    /**
     * A real freight company from the serpapi fixture should score ≥ 50.
     */
    public function test_freight_candidate_scores_high(): void
    {
        $criteria  = $this->makeCriteria();
        $fixture   = $this->loadSerpapiFixture();

        // Use the first fixture entry (Bolloré — a major French transitaire)
        $candidate = $this->makeSerpApiCandidate($fixture[0]);

        /** @var HeuristicScoringDriver $driver */
        $driver = app(HeuristicScoringDriver::class);
        $result = $driver->score($candidate, $criteria);

        $this->assertGreaterThanOrEqual(50, $result['score'],
            "Freight candidate must score ≥ 50, got {$result['score']}");
    }

    // ── Heuristic: directory candidate scores low ─────────────────────────────

    /**
     * A blacklisted directory domain must score < 50.
     */
    public function test_directory_candidate_scores_low(): void
    {
        $criteria  = $this->makeCriteria();
        $candidate = [
            'url'     => 'https://www.pagesjaunes.fr/pros/transitaires-france',
            'link'    => 'https://www.pagesjaunes.fr/pros/transitaires-france',
            'title'   => 'Transitaires — annuaire des commissionnaires de transport',
            'snippet' => 'Trouvez les meilleurs transitaires et commissionnaires de transport en France. Logistique internationale, fret aérien, transport maritime.',
        ];

        /** @var HeuristicScoringDriver $driver */
        $driver = app(HeuristicScoringDriver::class);
        $result = $driver->score($candidate, $criteria);

        $this->assertLessThan(50, $result['score'],
            "Directory candidate must score < 50, got {$result['score']}");
    }

    // ── Heuristic: score and explanation shape ────────────────────────────────

    /**
     * Result is always an int in [0, 100] with a non-empty explanation string.
     */
    public function test_heuristic_returns_valid_shape(): void
    {
        $criteria = $this->makeCriteria();

        /** @var HeuristicScoringDriver $driver */
        $driver = app(HeuristicScoringDriver::class);

        $inputs = [
            // Minimal candidate (no data)
            [],
            // Freight candidate
            ['url' => 'https://geodis.com', 'title' => 'GEODIS logistique', 'snippet' => 'fret international'],
            // Junk candidate
            ['url' => 'https://example.com', 'title' => 'Vente en ligne', 'snippet' => 'Achetez nos produits.'],
            // Blacklisted
            ['url' => 'https://linkedin.com/company/transitaire', 'title' => 'Transitaire sur LinkedIn', 'snippet' => 'Voir les profils.'],
        ];

        foreach ($inputs as $i => $candidate) {
            $result = $driver->score($candidate, $criteria);

            $this->assertIsInt($result['score'],     "Input {$i}: score must be int");
            $this->assertGreaterThanOrEqual(0, $result['score'],   "Input {$i}: score ≥ 0");
            $this->assertLessThanOrEqual(100, $result['score'],    "Input {$i}: score ≤ 100");
            $this->assertIsString($result['explanation'],          "Input {$i}: explanation must be string");
            $this->assertNotEmpty(trim($result['explanation']),    "Input {$i}: explanation must not be empty");
        }
    }

    // ── Fixture invariant ─────────────────────────────────────────────────────

    /**
     * HARD INVARIANT: every serpapi.json entry must score ≥ 50 for
     * criteria sectors=['transport'], countries=['France'].
     */
    public function test_all_fixture_entries_score_above_threshold(): void
    {
        $criteria = $this->makeCriteria([
            'sectors'   => ['transport'],
            'countries' => ['France'],
        ]);

        /** @var HeuristicScoringDriver $driver */
        $driver  = app(HeuristicScoringDriver::class);
        $fixture = $this->loadSerpapiFixture();

        foreach ($fixture as $index => $entry) {
            $candidate = $this->makeSerpApiCandidate($entry);
            $result    = $driver->score($candidate, $criteria);

            $this->assertGreaterThanOrEqual(
                50,
                $result['score'],
                sprintf(
                    'Fixture entry #%d (%s) scored %d < 50. Explanation: %s',
                    $index,
                    $entry['link'] ?? 'unknown',
                    $result['score'],
                    $result['explanation']
                )
            );
        }
    }

    // ── Gemini fallback when API key is null ──────────────────────────────────

    /**
     * With driver=gemini but no API key, the service must fall back to
     * heuristic and never fire an outbound HTTP request.
     */
    public function test_gemini_fallback_when_no_api_key(): void
    {
        Http::fake();

        config([
            'services.scoring.driver'   => 'gemini',
            'services.gemini.api_key'   => null,
        ]);

        $criteria  = $this->makeCriteria();
        $candidate = [
            'url'     => 'https://www.geodis.com/fr',
            'title'   => 'GEODIS — Commissionnaire de transport',
            'snippet' => 'Transport logistique fret international.',
        ];

        /** @var LeadScoringService $service */
        $service = app(LeadScoringService::class);
        $result  = $service->score($candidate, $criteria);

        // Must return a valid scored result (from heuristic fallback)
        $this->assertIsInt($result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertNotEmpty(trim($result['explanation']));

        // Must not have fired any HTTP request
        Http::assertNothingSent();
    }

    // ── Gemini happy path ─────────────────────────────────────────────────────

    /**
     * With driver=gemini and a fake API key, Http::fake returns a valid
     * Gemini envelope → service returns the AI score/explanation.
     */
    public function test_gemini_happy_path_returns_ai_result(): void
    {
        $geminiUrl = 'https://generativelanguage.googleapis.com/*';

        Http::fake([
            $geminiUrl => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"score": 87, "explanation": "Bon prospect."}'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config([
            'services.scoring.driver'  => 'gemini',
            'services.gemini.api_key'  => 'fake-gemini-key',
            'services.gemini.model'    => 'gemini-2.0-flash',
        ]);

        $criteria  = $this->makeCriteria();
        $candidate = [
            'url'     => 'https://www.geodis.com/fr',
            'title'   => 'GEODIS — Commissionnaire de transport',
            'snippet' => 'Transport logistique fret international.',
        ];

        /** @var LeadScoringService $service */
        $service = app(LeadScoringService::class);
        $result  = $service->score($candidate, $criteria);

        $this->assertSame(87, $result['score']);
        $this->assertSame('Bon prospect.', $result['explanation']);
    }

    // ── Gemini garbage response → heuristic fallback ──────────────────────────

    /**
     * When Gemini returns unparseable / garbage JSON, the service must
     * fall back to the heuristic driver and still return a valid result.
     */
    public function test_gemini_garbage_response_falls_back_to_heuristic(): void
    {
        $geminiUrl = 'https://generativelanguage.googleapis.com/*';

        Http::fake([
            $geminiUrl => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                // Not valid JSON scoring object
                                ['text' => 'Je ne suis pas sûr de pouvoir répondre à cette question.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config([
            'services.scoring.driver'  => 'gemini',
            'services.gemini.api_key'  => 'fake-gemini-key',
            'services.gemini.model'    => 'gemini-2.0-flash',
        ]);

        $criteria  = $this->makeCriteria();
        $candidate = [
            'url'     => 'https://www.geodis.com/fr',
            'title'   => 'GEODIS — Commissionnaire de transport et logistique',
            'snippet' => 'GEODIS est un opérateur mondial de la supply chain.',
        ];

        /** @var LeadScoringService $service */
        $service = app(LeadScoringService::class);
        $result  = $service->score($candidate, $criteria);

        // Must return a valid heuristic result (fallback)
        $this->assertIsInt($result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertNotEmpty(trim($result['explanation']));

        // Heuristic explanation contains "Heuristique" (not Gemini output)
        $this->assertStringContainsString('Heuristique', $result['explanation']);
    }

    // ── LeadScoringService heuristic driver (default) ─────────────────────────

    /**
     * Default driver (heuristic) scores consistently via the orchestrating service.
     */
    public function test_service_uses_heuristic_by_default(): void
    {
        config(['services.scoring.driver' => 'heuristic']);

        $criteria  = $this->makeCriteria();
        $candidate = [
            'url'     => 'https://www.clasquin.com/',
            'title'   => 'Clasquin — Commissionnaire de transport international',
            'snippet' => 'Clasquin est un commissionnaire de transport international indépendant.',
        ];

        /** @var LeadScoringService $service */
        $service = app(LeadScoringService::class);

        /** @var HeuristicScoringDriver $heuristic */
        $heuristic = app(HeuristicScoringDriver::class);

        $fromService   = $service->score($candidate, $criteria);
        $fromHeuristic = $heuristic->score($candidate, $criteria);

        $this->assertSame($fromHeuristic['score'],       $fromService['score']);
        $this->assertSame($fromHeuristic['explanation'], $fromService['explanation']);
    }
}
