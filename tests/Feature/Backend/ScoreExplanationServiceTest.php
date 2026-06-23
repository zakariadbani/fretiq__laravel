<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Services\Scoring\ScoreExplanationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ScoreExplanationServiceTest — verifies the fallback narrative path of
 * ScoreExplanationService when Gemini is not configured.
 *
 * Database: fretiq_test (RefreshDatabase wraps each test).
 * No auth required — service is called directly.
 */
class ScoreExplanationServiceTest extends TestCase
{
    use RefreshDatabase;

    // ponytail: one check on the fallback branch — the only non-trivial logic; Gemini path is network-mocked out.
    public function test_fallback_narrative_when_gemini_unconfigured(): void
    {
        config(['services.gemini.api_key' => null]);

        // Any accidental HTTP call will fail the test loudly.
        Http::preventStrayRequests();

        /** @var Company $company */
        $company = Company::factory()->create([
            'ai_score' => 72,
            'sector'   => 'Transport maritime',
            'country'  => 'FR',
        ]);

        $svc  = app(ScoreExplanationService::class);
        $text = $svc->explain($company);

        $this->assertNotEmpty($text, 'Fallback must return a non-empty string when ai_score is set.');
        $this->assertTrue(str_contains($text, '72'), 'Fallback must include the numeric score (72) in the returned text.');
        $this->assertStringContainsString('fort potentiel', $text, 'Score 72 must map to tier "fort potentiel".');
    }
}
