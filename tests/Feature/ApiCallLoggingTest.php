<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises the universal outgoing-HTTP-call logger (App\Listeners\LogOutgoingHttpCall)
 * end to end: fires real Http:: calls against Http::fake() responses and reads back
 * the resulting lines written to the real 'api' channel log file.
 */
class ApiCallLoggingTest extends TestCase
{
    public function test_api_calls_are_logged_with_provider_tags_and_redacted_bodies(): void
    {
        $logFile = storage_path('logs/api-' . now()->format('Y-m-d') . '.log');
        $before = is_file($logFile) ? filesize($logFile) : 0;

        Http::fake([
            'https://api.hunter.io/*' => Http::response(['data' => ['result' => 'deliverable']], 200),
            'https://campaigns.zoho.com/*' => Http::response(
                'Erreur Zoho pour john.doe@example.com : contact invalide',
                500
            ),
            'https://some-random-site.test/*' => Http::response('<html>secret third-party markup</html>', 500),
        ]);

        Http::get('https://api.hunter.io/v2/email-verifier?api_key=SUPERSECRET&email=test@example.com');
        Http::get('https://campaigns.zoho.com/api/v1.1/getlistsubscribers?listkey=abc123');
        Http::get('https://some-random-site.test/');

        $this->assertFileExists($logFile);
        $contents = substr(file_get_contents($logFile), $before);
        $lines = array_values(array_filter(explode("\n", $contents)));

        $hunterLine = $this->findLine($lines, 'hunter');
        $zohoLine = $this->findLine($lines, 'zoho_campaigns');
        $webLine = $this->findLine($lines, 'web');

        $this->assertNotNull($hunterLine, 'expected a hunter provider log line');
        $this->assertNotNull($zohoLine, 'expected a zoho_campaigns provider log line');
        $this->assertNotNull($webLine, 'expected a web provider log line');

        // Duration is nullable under Http::fake() — assert the key exists, not a value.
        $this->assertArrayHasKey('duration_ms', $hunterLine);
        $this->assertSame(200, $hunterLine['status']);
        $this->assertSame('GET', $hunterLine['method']);
        $this->assertArrayNotHasKey('api_key', $hunterLine['query']);
        $this->assertStringNotContainsString('SUPERSECRET', $contents);

        // 500 line: email-masked body excerpt present, no secret query keys.
        $this->assertSame(500, $zohoLine['status']);
        $this->assertArrayHasKey('body_excerpt', $zohoLine);
        $this->assertStringNotContainsString('john.doe@example.com', $zohoLine['body_excerpt']);
        $this->assertStringContainsString('j***@example.com', $zohoLine['body_excerpt']);
        // 'listkey' is not a secret key (real debugging signal) — redaction keeps it.
        $this->assertArrayHasKey('listkey', $zohoLine['query']);

        // web provider: never logs a body excerpt, even on failure.
        $this->assertSame(500, $webLine['status']);
        $this->assertArrayNotHasKey('body_excerpt', $webLine);
    }

    /**
     * Parse the daily-log lines for the first one whose Monolog "message" field
     * equals $provider, returning its decoded JSON context (or null).
     *
     * @param  string[]  $lines
     * @return array<string, mixed>|null
     */
    private function findLine(array $lines, string $provider): ?array
    {
        foreach ($lines as $line) {
            if (! preg_match('/\]\s+\S+\.\w+:\s+' . preg_quote($provider, '/') . '\s+(\{.*\})\s*$/', $line, $m)) {
                continue;
            }

            $context = json_decode($m[1], true);
            if (is_array($context)) {
                return $context;
            }
        }

        return null;
    }
}
