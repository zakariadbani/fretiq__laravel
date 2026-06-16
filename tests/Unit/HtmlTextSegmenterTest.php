<?php

namespace Tests\Unit;

use App\Services\Translation\HtmlTextSegmenter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HtmlTextSegmenter.
 *
 * No DB, no network, no Laravel bootstrap — pure PHP.
 *
 * Core invariant: reassemble(segment($html)) === $html  (byte-for-byte).
 */
class HtmlTextSegmenterTest extends TestCase
{
    private HtmlTextSegmenter $segmenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->segmenter = new HtmlTextSegmenter();
    }

    // ── Byte-identity invariant ────────────────────────────────────────────────

    public function test_reassemble_is_byte_identical_for_full_page_document(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: sans-serif; }
    .footer { color: #888; font-size: 11px; }
  </style>
  <title>Campagne TCL France</title>
</head>
<body>
  <p>Bonjour {{contact.name}},</p>
  <p>Votre société <strong>{{company.name}}</strong> nous intéresse.</p>
  <!--[if mso]>
    <table role="presentation"><tr><td>
  <![endif]-->
  <table>
    <tr>
      <td>Contenu principal : transport international de fret.</td>
    </tr>
  </table>
  <!--[if mso]>
    </td></tr></table>
  <![endif]-->
  <script>
    // Tracking snippet (should never be translated)
    var x = 1;
  </script>
  <div class="footer">
    <a href="{{unsubscribe_url}}">Se désabonner</a>
  </div>
</body>
</html>
HTML;

        $tokens = $this->segmenter->segment($html);
        $reassembled = $this->segmenter->reassemble($tokens);

        $this->assertSame($html, $reassembled, 'reassemble(segment(html)) must be byte-identical to input');
    }

    public function test_reassemble_byte_identical_for_empty_string(): void
    {
        $tokens = $this->segmenter->segment('');
        $this->assertSame('', $this->segmenter->reassemble($tokens));
    }

    public function test_reassemble_byte_identical_for_plain_text(): void
    {
        $html = 'Bonjour le monde';
        $tokens = $this->segmenter->segment($html);
        $this->assertSame($html, $this->segmenter->reassemble($tokens));
    }

    public function test_reassemble_byte_identical_for_simple_paragraph(): void
    {
        $html = '<p>Hello <strong>world</strong>!</p>';
        $tokens = $this->segmenter->segment($html);
        $this->assertSame($html, $this->segmenter->reassemble($tokens));
    }

    // ── Token types ────────────────────────────────────────────────────────────

    public function test_style_block_is_markup(): void
    {
        $html = '<style>body { color: red; }</style>';
        $tokens = $this->segmenter->segment($html);

        $this->assertCount(1, $tokens);
        $this->assertSame('markup', $tokens[0]['type']);
        $this->assertSame($html, $tokens[0]['value']);
    }

    public function test_script_block_is_markup(): void
    {
        $html = '<script>var x = 1;</script>';
        $tokens = $this->segmenter->segment($html);

        $this->assertCount(1, $tokens);
        $this->assertSame('markup', $tokens[0]['type']);
    }

    public function test_head_block_is_markup(): void
    {
        $html = '<head><title>Test</title><meta charset="UTF-8"></head>';
        $tokens = $this->segmenter->segment($html);

        $this->assertCount(1, $tokens);
        $this->assertSame('markup', $tokens[0]['type']);
    }

    public function test_html_comment_is_markup(): void
    {
        $html = '<!-- This is a comment -->';
        $tokens = $this->segmenter->segment($html);

        $this->assertCount(1, $tokens);
        $this->assertSame('markup', $tokens[0]['type']);
    }

    public function test_outlook_conditional_comment_is_markup(): void
    {
        $html = '<!--[if mso]><table><tr><td><![endif]-->';
        $tokens = $this->segmenter->segment($html);

        // The whole conditional is treated as a single markup token
        $markupTokens = array_filter($tokens, fn ($t) => $t['type'] === 'markup');
        $this->assertNotEmpty($markupTokens);
    }

    public function test_individual_tags_are_markup(): void
    {
        $html = '<p class="greeting">Hello</p>';
        $tokens = $this->segmenter->segment($html);

        $types = array_column($tokens, 'type');
        // Should have markup (<p>), text (Hello), markup (</p>)
        $this->assertContains('markup', $types);
        $this->assertContains('text', $types);

        // All tag tokens are markup
        foreach ($tokens as $token) {
            if (str_starts_with(trim($token['value']), '<')) {
                $this->assertSame('markup', $token['type']);
            }
        }
    }

    // ── translatableRuns filtering ─────────────────────────────────────────────

    public function test_whitespace_only_text_excluded_from_runs(): void
    {
        // Whitespace between tags is text tokens but should be excluded
        $html = "<p>\n  Hello\n  </p>";
        $tokens = $this->segmenter->segment($html);
        $runs = $this->segmenter->translatableRuns($tokens);

        foreach ($runs as $run) {
            $this->assertNotSame('', trim($run['value']), 'Whitespace-only runs must be excluded');
        }
    }

    public function test_bare_merge_tag_excluded_from_runs(): void
    {
        // A bare {{contact.name}} with only whitespace around it must be excluded from runs
        $html = '<p>{{contact.name}}</p>';
        $tokens = $this->segmenter->segment($html);
        $runs = $this->segmenter->translatableRuns($tokens);

        $runValues = array_column($runs, 'value');

        // The {{contact.name}} text token must not appear in runs
        foreach ($runValues as $value) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*\{\{[^}]+\}\}\s*$/',
                $value,
                'Bare merge tags must be excluded from runs'
            );
        }

        // Additionally, the bare merge-tag node itself must not be in runs at all
        $this->assertNotContains('{{contact.name}}', $runValues,
            '{{contact.name}} as a standalone text node must not appear in translatable runs');
    }

    public function test_bare_merge_tag_with_whitespace_excluded_from_runs(): void
    {
        // " {{contact.name}} " (with surrounding whitespace) — also excluded because
        // the trimmed value matches the bare-merge-tag pattern.
        $html = '<p> {{contact.name}} </p>';
        $tokens = $this->segmenter->segment($html);
        $runs = $this->segmenter->translatableRuns($tokens);

        // The text token " {{contact.name}} " must not appear in translatable runs
        $runValues = array_column($runs, 'value');
        foreach ($runValues as $value) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*\{\{[^}]+\}\}\s*$/',
                $value,
                'Bare merge tags with surrounding whitespace must be excluded from runs'
            );
        }

        // The specific text node with the bare merge tag must not appear
        $this->assertNotContains(' {{contact.name}} ', $runValues,
            'Text node containing only a bare merge tag must be excluded from translatable runs');
    }

    public function test_text_with_mixed_content_and_merge_tag_included(): void
    {
        // "Bonjour {{contact.name}}" — text node with a merge tag embedded IS a run
        $html = '<p>Bonjour {{contact.name}},</p>';
        $tokens = $this->segmenter->segment($html);
        $runs = $this->segmenter->translatableRuns($tokens);

        $runValues = array_column($runs, 'value');
        $this->assertNotEmpty($runValues, 'Mixed-content text runs must be included');

        $found = false;
        foreach ($runValues as $v) {
            if (str_contains($v, 'Bonjour')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, '"Bonjour" text node must appear in translatable runs');
    }

    public function test_markup_tokens_not_in_runs(): void
    {
        $html = '<p>Hello</p>';
        $tokens = $this->segmenter->segment($html);
        $runs = $this->segmenter->translatableRuns($tokens);

        // Runs only carry indices of text tokens
        foreach ($runs as $run) {
            $token = $tokens[$run['index']];
            $this->assertSame('text', $token['type'], 'Runs must only reference text tokens');
        }
    }

    public function test_empty_string_has_no_runs(): void
    {
        $tokens = $this->segmenter->segment('');
        $runs = $this->segmenter->translatableRuns($tokens);
        $this->assertSame([], $runs);
    }

    // ── Full document roundtrip with runs check ────────────────────────────────

    public function test_full_document_runs_exclude_opaque_blocks(): void
    {
        $html = <<<'HTML'
<html>
<head><style>body{color:red}</style></head>
<body>
  <p>Visible text here.</p>
  {{contact.name}}
  <!-- comment -->
  <script>var x=1;</script>
</body>
</html>
HTML;
        $tokens = $this->segmenter->segment($html);

        // Byte-identity
        $this->assertSame($html, $this->segmenter->reassemble($tokens));

        $runs = $this->segmenter->translatableRuns($tokens);
        $runValues = array_column($runs, 'value');

        // "Visible text here." should be in runs
        $hasVisible = false;
        foreach ($runValues as $v) {
            if (str_contains($v, 'Visible text here.')) {
                $hasVisible = true;
            }
        }
        $this->assertTrue($hasVisible, 'Visible paragraph text must appear in runs');

        // No run should start with a tag
        foreach ($runValues as $v) {
            $this->assertStringStartsNotWith('<', ltrim($v),
                'No run value should be a markup token');
        }
    }

    // ── FIX 5 regression: unclosed opaque blocks must not leak as text runs ──

    /**
     * An UNCLOSED <style> block (no </style>) must be classified entirely as
     * markup, so the CSS body never appears in translatableRuns.
     * Byte-identity must still hold.
     */
    public function test_unclosed_style_block_is_markup_not_text_run(): void
    {
        // Deliberately no closing </style> tag
        $html = '<style>body{color:red}';

        $tokens = $this->segmenter->segment($html);

        // Byte-identity invariant must hold
        $this->assertSame($html, $this->segmenter->reassemble($tokens),
            'reassemble(segment(html)) must be byte-identical even for unclosed <style>');

        // The CSS body must NOT appear in translatable runs
        $runs = $this->segmenter->translatableRuns($tokens);
        $runValues = array_column($runs, 'value');

        foreach ($runValues as $value) {
            $this->assertStringNotContainsString('color:red', $value,
                'CSS body inside an unclosed <style> must not appear in translatable runs');
        }

        // The entire string must be classified as markup (single token)
        $markupTokens = array_filter($tokens, fn ($t) => $t['type'] === 'markup');
        $this->assertCount(1, $markupTokens,
            'Unclosed <style> must produce exactly one markup token covering the whole string');
    }

    /**
     * An UNCLOSED <script> block must be classified as markup, not a text run.
     */
    public function test_unclosed_script_block_is_markup_not_text_run(): void
    {
        $html = '<script>var x = 1; /* no closing tag */';

        $tokens = $this->segmenter->segment($html);

        $this->assertSame($html, $this->segmenter->reassemble($tokens),
            'Byte-identity must hold for unclosed <script>');

        $runs = $this->segmenter->translatableRuns($tokens);
        $runValues = array_column($runs, 'value');

        foreach ($runValues as $value) {
            $this->assertStringNotContainsString('var x', $value,
                'JS inside unclosed <script> must not appear in translatable runs');
        }

        $markupTokens = array_filter($tokens, fn ($t) => $t['type'] === 'markup');
        $this->assertCount(1, $markupTokens,
            'Unclosed <script> must produce exactly one markup token');
    }
}
