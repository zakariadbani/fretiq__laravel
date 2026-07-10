<?php

namespace Tests\Feature\Backend;

use App\Services\Translation\GeminiTranslationDriver;
use App\Services\Translation\HtmlTextSegmenter;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for GeminiTranslationDriver::translate().
 *
 * All HTTP calls are Http::fake()'d — never hits the network.
 * The test confirms each guard condition returns null.
 */
class GeminiTranslationDriverTest extends TestCase
{
    use RefreshDatabase;

    private GeminiTranslationDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->driver = new GeminiTranslationDriver(new HtmlTextSegmenter());
    }

    // ── Fixture helpers ────────────────────────────────────────────────────────

    /**
     * A minimal HTML body with one translatable text run.
     */
    private function html(): string
    {
        return '<p>Hello {{contact.name}}, welcome.</p>';
    }

    /**
     * Build a valid Gemini JSON response text for the given runs.
     */
    private function geminiJson(array $runs, string $subject = 'EN subject', ?string $preview = 'EN preview'): string
    {
        return json_encode([
            'subject'      => $subject,
            'preview_text' => $preview,
            'runs'         => $runs,
        ]);
    }

    private function geminiResponseBody(string $jsonText, string $finishReason = 'STOP'): array
    {
        return [
            'candidates' => [
                [
                    'finishReason' => $finishReason,
                    'content'      => [
                        'parts' => [
                            ['text' => $jsonText],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function fakeGemini(array $body, int $status = 200): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($body, $status),
        ]);
    }

    private function setApiKey(?string $key = 'fake-api-key'): void
    {
        config([
            'services.gemini.api_key' => $key,
            'services.gemini.model'   => 'gemini-2.0-flash',
        ]);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_clean_response_returns_translated_array(): void
    {
        $subject = 'Sujet FR {{contact.name}}';
        $html    = '<p>Bonjour {{contact.name}}, bienvenue.</p>';

        // Segment the HTML to know what runs will be sent
        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        // Build matching EN runs (same count, merge tags preserved)
        $enRuns = array_map(function (string $run) {
            return str_replace('Bonjour', 'Hello', str_replace('bienvenue', 'welcome', $run));
        }, $inputRuns);

        $jsonText = $this->geminiJson($enRuns, 'EN Subject {{contact.name}}', 'EN preview');
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate($subject, 'Aperçu FR', $html, 'fr', 'en');

        $this->assertNotNull($result, 'Clean Gemini response must return a non-null result');
        $this->assertIsString($result['subject']);
        $this->assertIsString($result['html_content']);
        $this->assertNotEmpty(trim($result['subject']));
    }

    public function test_markup_is_byte_identical_after_translation(): void
    {
        $html = '<table><tr><td><strong>Contenu {{contact.name}}</strong></td></tr></table>';

        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        // Return the same runs (identity translation) — markup must remain identical
        $jsonText = $this->geminiJson($inputRuns, 'EN subject', null);
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $html, 'fr', 'en');

        $this->assertNotNull($result);
        // All markup tags must be preserved byte-for-byte
        preg_match_all('/<[^>]+>/', $html, $originalTags);
        preg_match_all('/<[^>]+>/', $result['html_content'], $resultTags);

        sort($originalTags[0]);
        sort($resultTags[0]);
        $this->assertSame($originalTags[0], $resultTags[0],
            'HTML markup tags must be byte-identical after translation');
    }

    // ── Guard: blank API key → null ───────────────────────────────────────────

    public function test_blank_api_key_returns_null(): void
    {
        Http::fake(); // No calls expected
        config(['services.gemini.api_key' => null]);

        $result = $this->driver->translate('FR subject', null, $this->html(), 'fr', 'en');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_empty_string_api_key_returns_null(): void
    {
        Http::fake();
        config(['services.gemini.api_key' => '']);

        $result = $this->driver->translate('FR subject', null, $this->html(), 'fr', 'en');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    // ── Guard: HTTP failure → null ────────────────────────────────────────────

    public function test_http_500_returns_null(): void
    {
        $this->fakeGemini([], 500);
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $this->html(), 'fr', 'en');

        $this->assertNull($result);
    }

    public function test_http_429_returns_null(): void
    {
        $this->fakeGemini([], 429);
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $this->html(), 'fr', 'en');

        $this->assertNull($result);
    }

    // ── Guard: MAX_TOKENS → null ──────────────────────────────────────────────

    public function test_finish_reason_max_tokens_returns_null(): void
    {
        $html    = $this->html();
        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        $jsonText = $this->geminiJson($inputRuns, 'EN subject');
        $this->fakeGemini($this->geminiResponseBody($jsonText, 'MAX_TOKENS'));
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $html, 'fr', 'en');

        $this->assertNull($result);
    }

    // ── Guard: runs length mismatch → null ───────────────────────────────────

    public function test_runs_length_mismatch_returns_null(): void
    {
        $html = $this->html();

        // Return fewer runs than expected (1 run expected, 2 returned)
        $jsonText = $this->geminiJson(
            ['EN text.', 'EXTRA RUN — should cause failure'],
            'EN subject'
        );
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $html, 'fr', 'en');

        $this->assertNull($result);
    }

    public function test_runs_length_zero_when_expected_returns_null(): void
    {
        $html = '<p>Bonjour, contenu ici.</p>';

        // Return empty runs when there should be at least one
        $jsonText = $this->geminiJson([], 'EN subject');
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $html, 'fr', 'en');

        $this->assertNull($result);
    }

    // ── Guard: dropped merge tag → null ──────────────────────────────────────

    public function test_dropped_merge_tag_in_run_returns_null(): void
    {
        $html = '<p>Bonjour {{contact.name}}, merci.</p>';

        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        // Mutate: drop the merge tag from the run
        $mutatedRuns = array_map(function (string $run) {
            return str_replace('{{contact.name}}', 'CONTACT_NAME_DROPPED', $run);
        }, $inputRuns);

        $jsonText = $this->geminiJson($mutatedRuns, 'EN subject');
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate('FR subject {{contact.name}}', null, $html, 'fr', 'en');

        $this->assertNull($result);
    }

    public function test_added_merge_tag_in_run_returns_null(): void
    {
        $html = '<p>Bonjour, merci.</p>';

        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        // Mutate: add a merge tag that wasn't there
        $mutatedRuns = array_map(function (string $run) {
            return $run . ' {{contact.email}}';
        }, $inputRuns);

        $jsonText = $this->geminiJson($mutatedRuns, 'EN subject');
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $html, 'fr', 'en');

        $this->assertNull($result);
    }

    // ── Guard: invalid JSON → null ────────────────────────────────────────────

    public function test_invalid_json_returns_null(): void
    {
        $invalidBody = [
            'candidates' => [
                [
                    'finishReason' => 'STOP',
                    'content'      => [
                        'parts' => [
                            ['text' => 'This is not JSON at all.'],
                        ],
                    ],
                ],
            ],
        ];

        $this->fakeGemini($invalidBody);
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $this->html(), 'fr', 'en');

        $this->assertNull($result);
    }

    // ── Guard: missing subject in response → null ─────────────────────────────

    public function test_missing_subject_in_response_returns_null(): void
    {
        $html = $this->html();
        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        $bodyText = json_encode([
            // 'subject' key missing
            'preview_text' => 'EN preview',
            'runs'         => $inputRuns,
        ]);

        $this->fakeGemini($this->geminiResponseBody($bodyText));
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $html, 'fr', 'en');

        $this->assertNull($result);
    }

    // ── Merge tag preserved in subject ────────────────────────────────────────

    public function test_merge_tag_preserved_in_subject(): void
    {
        $subject = 'Bonjour {{contact.name}}, offre spéciale';
        $html    = '<p>Contenu.</p>';

        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        $jsonText = $this->geminiJson(
            $inputRuns,
            'Hello {{contact.name}}, special offer', // merge tag preserved
            null
        );
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate($subject, null, $html, 'fr', 'en');

        $this->assertNotNull($result, 'Valid merge tag preserved in subject should pass');
        $this->assertStringContainsString('{{contact.name}}', $result['subject']);
    }

    public function test_dropped_merge_tag_in_subject_returns_null(): void
    {
        $subject = 'Bonjour {{contact.name}}, offre spéciale';
        $html    = '<p>Contenu.</p>';

        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        $jsonText = $this->geminiJson(
            $inputRuns,
            'Hello CONTACT, special offer', // merge tag DROPPED
            null
        );
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate($subject, null, $html, 'fr', 'en');

        $this->assertNull($result);
    }

    // ── FIX 1 regression: reordered merge tags must be rejected ──────────────

    /**
     * A translation that reorders {{contact.name}} and {{company.name}} must be
     * rejected even though the multiset is identical — order must be preserved.
     */
    public function test_reordered_merge_tags_in_run_returns_null(): void
    {
        // Body run with two merge tags in a specific order
        $html = '<p>Bonjour {{contact.name}} chez {{company.name}}, merci.</p>';

        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        // Translation reorders the two merge tags → must be rejected
        $reorderedRuns = ['Hello {{company.name}} from {{contact.name}}, thank you.'];

        $jsonText = $this->geminiJson($reorderedRuns, 'EN subject');
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $html, 'fr', 'en');

        $this->assertNull($result, 'Reordered merge tags must be rejected by the ordered-sequence check');
    }

    /**
     * A translation that preserves merge tags in the original order must pass.
     */
    public function test_in_order_merge_tags_in_run_passes(): void
    {
        $html = '<p>Bonjour {{contact.name}} chez {{company.name}}, merci.</p>';

        $segmenter  = new HtmlTextSegmenter();
        $tokens     = $segmenter->segment($html);
        $runObjects = $segmenter->translatableRuns($tokens);
        $inputRuns  = array_column($runObjects, 'value');

        // Translation preserves order → must pass
        $inOrderRuns = ['Hello {{contact.name}} at {{company.name}}, thank you.'];

        $jsonText = $this->geminiJson($inOrderRuns, 'EN subject');
        $this->fakeGemini($this->geminiResponseBody($jsonText));
        $this->setApiKey();

        $result = $this->driver->translate('FR subject', null, $html, 'fr', 'en');

        $this->assertNotNull($result, 'In-order merge tags must pass the ordered-sequence check');
        $this->assertStringContainsString('{{contact.name}}', $result['html_content']);
        $this->assertStringContainsString('{{company.name}}', $result['html_content']);
    }

    public function test_large_html_is_translated_in_run_chunks(): void
    {
        $html = implode('', array_map(
            fn (int $i) => "<p>Bonjour ligne {$i}.</p>",
            range(1, 45),
        ));
        $sentRunCounts = [];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function (\Illuminate\Http\Client\Request $request) use (&$sentRunCounts) {
                $prompt = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

                $this->assertMatchesRegularExpression('/- runs: (\[.*\])\n\nThe "runs" field/s', $prompt);
                preg_match('/- runs: (\[.*\])\n\nThe "runs" field/s', $prompt, $matches);
                $runs = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

                $sentRunCounts[] = count($runs);

                $translatedRuns = array_map(
                    fn (string $run) => str_replace('Bonjour', 'Hello', $run),
                    $runs,
                );

                return Http::response($this->geminiResponseBody(
                    $this->geminiJson($translatedRuns, 'EN large subject', 'EN large preview'),
                ));
            },
        ]);
        $this->setApiKey();

        $result = $this->driver->translate('Sujet FR volumineux', 'Aperçu FR', $html, 'fr', 'en');

        $this->assertNotNull($result);
        $this->assertSame('EN large subject', $result['subject']);
        $this->assertStringContainsString('Hello ligne 45.', $result['html_content']);
        $this->assertGreaterThan(1, count($sentRunCounts), 'Large HTML must be split into multiple Gemini requests.');
        $this->assertContainsOnly('int', $sentRunCounts);
        $this->assertTrue(
            collect($sentRunCounts)->every(fn (int $count) => $count <= 20),
            'Each Gemini request should stay at or below the 20-run chunk size.',
        );
    }
}
