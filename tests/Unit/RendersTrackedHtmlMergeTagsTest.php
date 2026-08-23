<?php

namespace Tests\Unit;

use App\Mail\Concerns\RendersTrackedHtml;
use App\Models\Company;
use App\Models\Contact;
use Tests\TestCase;

/**
 * Unit tests for RendersTrackedHtml::renderMergeTags().
 *
 * Extends Tests\TestCase (not PHPUnit\Framework\TestCase) because the Eloquent
 * models need the Laravel app booted. No DB is touched: Contact / Company are
 * built in memory and the company relation is set via setRelation().
 *
 * Supported tokens under test:
 *   {{contact.name}}, {{contact.first_name}}, {{contact.email}},
 *   {{company.name}}, {{company.sector}}, {{unsubscribe_url}}
 */
class RendersTrackedHtmlMergeTagsTest extends TestCase
{
    private const UNSUBSCRIBE_URL = 'https://fretiq.test/unsubscribe/abc123';

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Build an in-memory Contact, optionally with an in-memory Company relation.
     *
     * @param  array<string, mixed>       $contactAttrs
     * @param  array<string, mixed>|null  $companyAttrs  Null → no company relation.
     */
    private function makeContact(array $contactAttrs = [], ?array $companyAttrs = []): Contact
    {
        $contact = new Contact(array_merge([
            'name'  => 'Karim Bennani',
            'email' => 'karim@example.test',
        ], $contactAttrs));

        $contact->setRelation(
            'company',
            $companyAttrs === null ? null : new Company(array_merge([
                'name'   => 'Acme Transport',
                'sector' => 'Transport & Logistique',
            ], $companyAttrs))
        );

        return $contact;
    }

    private function render(string $text, Contact $contact): string
    {
        return RendersTrackedHtmlMergeTagsFixture::renderMergeTags(
            $text,
            $contact,
            self::UNSUBSCRIBE_URL,
        );
    }

    // ── All six tokens in one pass ─────────────────────────────────────────────

    public function test_all_six_tokens_replaced_in_one_string(): void
    {
        $input = '{{contact.name}}|{{contact.first_name}}|{{contact.email}}'
               . '|{{company.name}}|{{company.sector}}|{{unsubscribe_url}}';

        $expected = 'Karim Bennani|Karim|karim@example.test'
                  . '|Acme Transport|Transport &amp; Logistique|' . self::UNSUBSCRIBE_URL;

        $this->assertSame($expected, $this->render($input, $this->makeContact()));
    }

    // ── {{contact.first_name}} derivation ──────────────────────────────────────

    public function test_first_name_takes_the_segment_before_the_first_space(): void
    {
        $contact = $this->makeContact(['name' => 'Karim Bennani']);

        $this->assertSame('Karim', $this->render('{{contact.first_name}}', $contact));
    }

    public function test_single_word_name_renders_in_full_as_first_name(): void
    {
        $contact = $this->makeContact(['name' => 'Karim']);

        // Str::before returns the whole string when the needle is absent.
        $this->assertSame('Karim', $this->render('{{contact.first_name}}', $contact));
    }

    public function test_multi_word_name_keeps_only_the_first_token(): void
    {
        $contact = $this->makeContact(['name' => 'Jean Pierre De La Fontaine']);

        $this->assertSame('Jean', $this->render('{{contact.first_name}}', $contact));
    }

    public function test_extra_whitespace_is_squished_before_splitting(): void
    {
        $contact = $this->makeContact(['name' => '   Karim    Bennani   ']);

        $this->assertSame('Karim', $this->render('{{contact.first_name}}', $contact));
    }

    // ── Null / empty name ──────────────────────────────────────────────────────

    public function test_null_name_renders_empty_for_name_and_first_name(): void
    {
        $contact = $this->makeContact(['name' => null]);

        $this->assertSame('', $this->render('{{contact.name}}', $contact));
        $this->assertSame('', $this->render('{{contact.first_name}}', $contact));
    }

    public function test_empty_name_renders_empty_for_name_and_first_name(): void
    {
        $contact = $this->makeContact(['name' => '']);

        $this->assertSame('', $this->render('{{contact.name}}', $contact));
        $this->assertSame('', $this->render('{{contact.first_name}}', $contact));
    }

    // ── Missing company relation ───────────────────────────────────────────────

    public function test_contact_without_company_renders_empty_company_tokens(): void
    {
        $contact = $this->makeContact([], null);

        $this->assertSame('', $this->render('{{company.name}}', $contact));
        $this->assertSame('', $this->render('{{company.sector}}', $contact));
    }

    public function test_company_with_null_sector_renders_empty_sector(): void
    {
        $contact = $this->makeContact([], ['sector' => null]);

        $this->assertSame('Acme Transport', $this->render('{{company.name}}', $contact));
        $this->assertSame('', $this->render('{{company.sector}}', $contact));
    }

    // ── HTML escaping ──────────────────────────────────────────────────────────

    public function test_contact_name_is_html_escaped(): void
    {
        $contact = $this->makeContact(['name' => 'A & B']);

        $this->assertSame('A &amp; B', $this->render('{{contact.name}}', $contact));
    }

    public function test_first_name_is_html_escaped(): void
    {
        $contact = $this->makeContact(['name' => 'A&B Karim']);

        $this->assertSame('A&amp;B', $this->render('{{contact.first_name}}', $contact));
    }

    public function test_company_name_and_sector_are_html_escaped(): void
    {
        $contact = $this->makeContact([], [
            'name'   => 'Dupont & Fils',
            'sector' => '<b>Chimie</b>',
        ]);

        $this->assertSame('Dupont &amp; Fils', $this->render('{{company.name}}', $contact));
        $this->assertSame('&lt;b&gt;Chimie&lt;/b&gt;', $this->render('{{company.sector}}', $contact));
    }

    // ── Unsubscribe URL stays raw (href-safe by contract) ──────────────────────

    public function test_unsubscribe_url_is_not_escaped(): void
    {
        $result = $this->render('<a href="{{unsubscribe_url}}">x</a>', $this->makeContact());

        $this->assertSame('<a href="' . self::UNSUBSCRIBE_URL . '">x</a>', $result);
    }

    // ── Passthrough ────────────────────────────────────────────────────────────

    public function test_unknown_token_passes_through_unchanged(): void
    {
        $input = 'Tel : {{contact.phone}}';

        $this->assertSame($input, $this->render($input, $this->makeContact()));
    }

    public function test_text_without_tokens_is_unchanged(): void
    {
        $input = 'Aucune variable ici.';

        $this->assertSame($input, $this->render($input, $this->makeContact()));
    }

    public function test_clean_html_receives_exactly_one_visible_unsubscribe_link(): void
    {
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            '<p>Bonjour Karim</p>',
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertSame(1, substr_count($result, 'href="' . self::UNSUBSCRIBE_URL . '"'));
        $this->assertStringContainsString('Se désabonner', $result);
    }

    public function test_legacy_unsubscribe_link_keeps_its_placement_without_appending_a_duplicate(): void
    {
        $source = '<p>Bonjour Karim</p><p class="legacy"><a href="{{unsubscribe_url}}">Ne plus recevoir</a></p>';

        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            $source,
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertSame(1, substr_count($result, 'href="' . self::UNSUBSCRIBE_URL . '"'));
        $this->assertStringContainsString('<p class="legacy"><a href="' . self::UNSUBSCRIBE_URL . '">Ne plus recevoir</a></p>', $result);
        $this->assertStringContainsString('/track/open/tracking-token', $result);
    }

    public function test_multiple_legacy_unsubscribe_links_keep_only_the_first_clickable_link(): void
    {
        $source = '<p><a class="first" href="{{unsubscribe_url}}">Premier lien</a></p>'
            . "<p><a data-kind='extra' href='{{unsubscribe_url}}'>Second lien</a></p>";

        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            $source,
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertSame(1, substr_count($result, self::UNSUBSCRIBE_URL));
        $this->assertStringContainsString('<a class="first" href="' . self::UNSUBSCRIBE_URL . '">Premier lien</a>', $result);
        $this->assertStringContainsString('<p>Second lien</p>', $result);
    }

    public function test_non_clickable_legacy_placeholder_is_removed_and_driver_footer_is_appended(): void
    {
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            '<p>URL brute : {{unsubscribe_url}}</p>',
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertSame(1, substr_count($result, self::UNSUBSCRIBE_URL));
        $this->assertSame(1, substr_count($result, 'href="' . self::UNSUBSCRIBE_URL . '"'));
        $this->assertStringContainsString('Se désabonner', $result);
    }

    public function test_pcre_failure_falls_back_to_one_driver_owned_clickable_link(): void
    {
        $previousLimit = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', '100');
            $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
                '<p><a href="{{unsubscribe_url}}">Premier lien</a></p>'
                    . '<a ' . str_repeat('x', 10000)
                    . ' href="{{unsubscribe_url}}">Lien pathologique</a>',
                $this->makeContact(),
                self::UNSUBSCRIBE_URL,
            );
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }

        $this->assertSame(1, substr_count($result, self::UNSUBSCRIBE_URL));
        $this->assertSame(1, substr_count($result, 'href="' . self::UNSUBSCRIBE_URL . '"'));
    }

    public function test_pixel_and_driver_footer_are_inserted_before_full_document_closing_tags(): void
    {
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            '<!doctype html><html><body><p>Contenu</p></body></html>',
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertLessThan(stripos($result, '</body>'), strpos($result, '/track/open/tracking-token'));
        $this->assertLessThan(stripos($result, '</body>'), strpos($result, 'href="' . self::UNSUBSCRIBE_URL . '"'));
        $this->assertLessThan(stripos($result, '</html>'), strpos($result, 'href="' . self::UNSUBSCRIBE_URL . '"'));
    }

    public function test_document_injection_uses_real_last_body_closing_tag_after_comment_marker(): void
    {
        $source = '<html><body><!-- archived </body> marker --><p>Content</p></body></html>';
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            $source,
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertStringContainsString('<!-- archived </body> marker --><p>Content</p>', $result);
        $this->assertGreaterThan(strpos($result, '<p>Content</p>'), strpos($result, '/track/open/tracking-token'));
        $this->assertLessThan(strripos($result, '</body>'), strpos($result, '/track/open/tracking-token'));
    }

    public function test_unquoted_legacy_href_keeps_first_custom_placement(): void
    {
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            '<p class="custom"><a href={{unsubscribe_url}}>Lien sans guillemets</a></p>',
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertSame(1, substr_count($result, self::UNSUBSCRIBE_URL));
        $this->assertStringContainsString(
            '<p class="custom"><a href=' . self::UNSUBSCRIBE_URL . '>Lien sans guillemets</a></p>',
            $result,
        );
    }

    public function test_commented_unsubscribe_anchor_is_ignored_in_favour_of_visible_anchor(): void
    {
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            '<!-- <a href="{{unsubscribe_url}}">archived</a> -->'
                . '<p><a href="{{unsubscribe_url}}">visible</a></p>',
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertSame(1, substr_count($result, self::UNSUBSCRIBE_URL));
        $this->assertStringContainsString('<!-- <a href="">archived</a> -->', $result);
        $this->assertStringContainsString(
            '<p><a href="' . self::UNSUBSCRIBE_URL . '">visible</a></p>',
            $result,
        );
    }

    public function test_commented_body_marker_after_real_closing_tag_is_ignored_for_injection(): void
    {
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            '<html><body><p>Content</p></body><!-- archived </body> marker --></html>',
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertStringContainsString(
            '<p>Content</p><img src=',
            $result,
        );
        $this->assertStringContainsString('</body><!-- archived </body> marker --></html>', $result);
        $this->assertLessThan(strpos($result, '</body><!--'), strpos($result, '/track/open/tracking-token'));
    }

    public function test_unclosed_comment_is_neutralized_before_visible_driver_footer(): void
    {
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            '<p>Content</p><!-- archived <a href="{{unsubscribe_url}}">hidden</a>',
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertSame(1, substr_count($result, self::UNSUBSCRIBE_URL));
        $this->assertStringContainsString('<!-- archived <a href="">hidden</a>-->', $result);
        $this->assertLessThan(strpos($result, '/track/open/tracking-token'), strpos($result, '-->'));
        $this->assertLessThan(strpos($result, 'href="' . self::UNSUBSCRIBE_URL . '"'), strpos($result, '-->'));
    }

    public function test_unclosed_script_is_closed_before_pixel_and_footer_injection(): void
    {
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            '<p>Content</p><script>var x="</body>";</script-no-close>',
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertStringContainsString('</script-no-close></script>', $result);
        $this->assertLessThan(strpos($result, '/track/open/tracking-token'), strpos($result, '</script>'));
        $this->assertLessThan(strpos($result, 'href="' . self::UNSUBSCRIBE_URL . '"'), strpos($result, '</script>'));
    }

    public function test_large_unclosed_comment_is_scanned_without_pcre_error_and_footer_stays_visible(): void
    {
        $source = '<p>Content</p><!--' . str_repeat('x', 1250000)
            . '<a href="{{unsubscribe_url}}">hidden</a>';

        $previousLimit = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', '1');
            $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
                $source,
                $this->makeContact(),
                self::UNSUBSCRIBE_URL,
            );
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }

        $this->assertSame(PREG_NO_ERROR, preg_last_error());
        $this->assertSame(1, substr_count($result, self::UNSUBSCRIBE_URL));
        $this->assertLessThan(strpos($result, '/track/open/tracking-token'), strrpos($result, '-->'));
        $this->assertLessThan(strpos($result, 'href="' . self::UNSUBSCRIBE_URL . '"'), strrpos($result, '-->'));
    }

    public function test_large_unclosed_raw_text_block_does_not_depend_on_pcre_backtracking(): void
    {
        $source = '<p>Content</p><script>' . str_repeat('</script-no-close>', 70000)
            . '{{unsubscribe_url}}';

        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            $source,
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertSame(PREG_NO_ERROR, preg_last_error());
        $this->assertSame(1, substr_count($result, self::UNSUBSCRIBE_URL));
        $this->assertLessThan(strpos($result, '/track/open/tracking-token'), strrpos($result, '</script>'));
    }

    // ── Click-through link rewriting ───────────────────────────────────────────

    public function test_link_rewrite_wraps_http_hrefs_through_click_route(): void
    {
        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            '<p><a href="https://example.test/offer?a=1&amp;b=2">Offer</a></p>',
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertStringNotContainsString('href="https://example.test/offer', $result);
        $this->assertMatchesRegularExpression('~href="[^"]*/track/click/tracking-token[^"]*"~', $result);
    }

    public function test_link_rewrite_skips_unsubscribe_mailto_tel_and_anchor_hrefs(): void
    {
        $source = '<p><a href="{{unsubscribe_url}}">Se désabonner</a></p>'
            . '<p><a href="mailto:contact@fretiq.test">Email</a></p>'
            . '<p><a href="tel:+33100000000">Call</a></p>'
            . '<p><a href="#section">Jump</a></p>';

        $result = RendersTrackedHtmlMergeTagsFixture::renderTrackedHtml(
            $source,
            $this->makeContact(),
            self::UNSUBSCRIBE_URL,
        );

        $this->assertStringContainsString('href="' . self::UNSUBSCRIBE_URL . '"', $result);
        $this->assertStringContainsString('href="mailto:contact@fretiq.test"', $result);
        $this->assertStringContainsString('href="tel:+33100000000"', $result);
        $this->assertStringContainsString('href="#section"', $result);
        $this->assertStringNotContainsString('/track/click/', $result);
    }
}

/**
 * Minimal consumer of the trait so the static method can be called without
 * pulling a full Mailable (and its constructor requirements) into the test.
 */
class RendersTrackedHtmlMergeTagsFixture
{
    use RendersTrackedHtml;

    public static function renderTrackedHtml(
        string $source,
        Contact $contact,
        string $unsubscribeUrl,
    ): string {
        $fixture = new self();
        $html = self::renderMergeTags($source, $contact, $unsubscribeUrl);

        return $fixture->appendTrackingPixelAndFooter(
            $html,
            'tracking-token',
            $unsubscribeUrl,
            'fr',
        );
    }
}
