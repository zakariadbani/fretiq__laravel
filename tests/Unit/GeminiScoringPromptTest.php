<?php

namespace Tests\Unit;

use App\Models\ProspectCriteria;
use App\Services\Gemini\GeminiClient;
use App\Services\Scoring\GeminiScoringDriver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * GeminiScoringPromptTest — the buildPrompt() contract for the homepage excerpt.
 *
 * The scorer used to see only the SERP title + snippet, so a press article ABOUT
 * France–Morocco freight flows scored like a shipper. The excerpt block plus the
 * junk-site instruction are what fix that.
 *
 * Invariant: when no excerpt is present the prompt must stay byte-identical to the
 * pre-change version, so the existing scoring tests keep passing.
 *
 * buildPrompt() is private — reached by reflection (same pattern as
 * tests/Unit/DiscoveryPipelineCompanyUpsertTest.php).
 */
class GeminiScoringPromptTest extends TestCase
{
    private function criteria(): ProspectCriteria
    {
        $criteria = new ProspectCriteria();

        $criteria->forceFill([
            'name'             => 'Textile Maroc → France',
            'sectors'          => ['textile'],
            'countries'        => ['France', 'Maroc'],
            'target_positions' => ['Directeur Supply Chain'],
            'ai_target'        => 'chargeurs textile exportant vers la France',
            'ai_exclude'       => 'transitaires et commissionnaires concurrents',
        ]);

        return $criteria;
    }

    private function buildPrompt(array $candidate): string
    {
        $driver = new GeminiScoringDriver();

        $method = (new ReflectionClass($driver))->getMethod('buildPrompt');
        $method->setAccessible(true);

        return $method->invoke($driver, $candidate, $this->criteria());
    }

    private function baseCandidate(): array
    {
        return [
            'domain'  => 'exemple.ma',
            'title'   => 'Exemple SARL — Confection textile',
            'snippet' => 'Fabricant de vêtements à Casablanca.',
            'url'     => 'https://exemple.ma/',
        ];
    }

    // ── With excerpt ──────────────────────────────────────────────────────────

    public function test_prompt_contains_the_excerpt_block_when_homepage_excerpt_is_set(): void
    {
        $excerpt = 'Journal en ligne. Toute l\'actualité économique du Maroc, en continu.';

        $prompt = $this->buildPrompt($this->baseCandidate() + ['homepage_excerpt' => $excerpt]);

        $this->assertStringContainsString("Extrait de la page d'accueil du site:", $prompt);
        $this->assertStringContainsString('"""', $prompt);
        $this->assertStringContainsString($excerpt, $prompt);

        // The block sits after the URL line, inside the "Candidat" section.
        $this->assertLessThan(
            strpos($prompt, 'Critères de prospection:'),
            strpos($prompt, "Extrait de la page d'accueil du site:"),
            "The excerpt block must appear before the 'Critères de prospection' section"
        );
        $this->assertLessThan(
            strpos($prompt, "Extrait de la page d'accueil du site:"),
            strpos($prompt, '- URL:'),
            'The excerpt block must appear after the URL line'
        );
    }

    public function test_prompt_contains_the_junk_site_instruction_when_excerpt_is_set(): void
    {
        $prompt = $this->buildPrompt(
            $this->baseCandidate() + ['homepage_excerpt' => 'Annuaire des entreprises marocaines. Trouvez un fournisseur.']
        );

        // Junk sites: low score, and explicitly NOT flagged as excluded competitors.
        $this->assertStringContainsString("site d'actualités ou de presse", $prompt);
        $this->assertStringContainsString('annuaire en ligne', $prompt);
        $this->assertStringContainsString("site d'offres d'emploi", $prompt);
        $this->assertStringContainsString('encyclopédie', $prompt);
        $this->assertStringContainsString('entre 0 et 10', $prompt);
        $this->assertStringContainsString('exclude à false', $prompt);
    }

    // ── Without excerpt (regression guard) ────────────────────────────────────

    public function test_prompt_has_no_excerpt_block_when_homepage_excerpt_is_absent(): void
    {
        $prompt = $this->buildPrompt($this->baseCandidate());

        $this->assertStringNotContainsString("Extrait de la page d'accueil du site:", $prompt);
        $this->assertStringNotContainsString('"""', $prompt);
        $this->assertStringNotContainsString("site d'actualités ou de presse", $prompt);
    }

    public function test_prompt_is_unchanged_when_excerpt_is_null_or_blank(): void
    {
        $reference = $this->buildPrompt($this->baseCandidate());

        $this->assertSame(
            $reference,
            $this->buildPrompt($this->baseCandidate() + ['homepage_excerpt' => null]),
            'A null excerpt must not alter the prompt'
        );

        $this->assertSame(
            $reference,
            $this->buildPrompt($this->baseCandidate() + ['homepage_excerpt' => "  \n "]),
            'A whitespace-only excerpt must not alter the prompt'
        );
    }

    public function test_existing_prompt_sections_are_preserved_when_excerpt_is_present(): void
    {
        $prompt = $this->buildPrompt($this->baseCandidate() + ['homepage_excerpt' => 'Fabricant de textile à Casablanca.']);

        $this->assertStringContainsString('Tu es un expert en prospection B2B pour TCL France', $prompt);
        $this->assertStringContainsString('- Domaine: exemple.ma', $prompt);
        $this->assertStringContainsString('- Cible: chargeurs textile exportant vers la France', $prompt);
        $this->assertStringContainsString('- À exclure: transitaires et commissionnaires concurrents', $prompt);
        $this->assertStringContainsString('Réponds UNIQUEMENT avec un objet JSON strict', $prompt);
    }

    public function test_gemini_request_uses_the_timeout_supplied_by_the_pipeline(): void
    {
        config(['services.gemini.api_key' => 'fake-key']);
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => '{"score": 80, "explanation": "Cible pertinente", "exclude": false}',
                ]]],
            ]],
        ])]);

        $client = new class extends GeminiClient {
            public ?int $seenTimeout = null;

            public function request(string $prompt, int $timeout, array $generationConfig): Response
            {
                $this->seenTimeout = $timeout;

                return parent::request($prompt, $timeout, $generationConfig);
            }
        };

        $result = (new GeminiScoringDriver($client))->score(
            $this->baseCandidate(),
            $this->criteria(),
            7,
        );

        $this->assertSame(7, $client->seenTimeout);
        $this->assertSame(80, $result['score']);
    }
}
