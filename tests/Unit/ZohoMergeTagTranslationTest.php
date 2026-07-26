<?php

namespace Tests\Unit;

use App\Services\Campaign\ZohoCampaignsDriver;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Pure static unit tests for ZohoCampaignsDriver::translateMergeTags().
 *
 * No network, no DB, no Laravel bootstrap required. A handful of tests below bind a
 * bare-minimum Illuminate config repository into the container (no service providers,
 * no HTTP kernel) purely so ZohoCampaignsDriver::prepareHtmlContent() can read
 * config('services.zoho.append_unsubscribe_fallback') — this is NOT a full Laravel
 * framework bootstrap.
 */
class ZohoMergeTagTranslationTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * Bind a minimal config repository so prepareHtmlContent()'s
     * config('services.zoho.append_unsubscribe_fallback') read resolves without
     * bootstrapping the full framework.
     */
    private function bindAppendUnsubscribeFallback(bool $enabled): void
    {
        $container = new Container();
        $container->instance('config', new ConfigRepository([
            'services' => [
                'zoho' => [
                    'append_unsubscribe_fallback' => $enabled,
                ],
            ],
        ]));
        Container::setInstance($container);
    }
    // ── Individual placeholder translations ────────────────────────────────────

    public function test_contact_name_translates_to_fname_tag_with_fallback(): void
    {
        $result = ZohoCampaignsDriver::translateMergeTags('Bonjour {{contact.name}},');
        $this->assertSame('Bonjour $[FNAME|client|client]$,', $result);
    }

    public function test_contact_first_name_translates_to_fname_tag_with_fallback(): void
    {
        $result = ZohoCampaignsDriver::translateMergeTags('Bonjour {{contact.first_name}},');
        $this->assertSame('Bonjour $[FNAME|client|client]$,', $result);
    }

    /**
     * {{company.sector}} has no Zoho equivalent — it must pass through unchanged.
     * UNVERIFIED against the live Zoho merge-tag list (see MERGE_TAG_MAP docblock).
     */
    public function test_company_sector_has_no_zoho_equivalent_and_passes_through(): void
    {
        $input = 'Secteur : {{company.sector}}';
        $this->assertSame($input, ZohoCampaignsDriver::translateMergeTags($input));
    }

    public function test_contact_email_translates_to_email_tag(): void
    {
        $result = ZohoCampaignsDriver::translateMergeTags('Votre email : {{contact.email}}');
        $this->assertSame('Votre email : $[EMAIL]$', $result);
    }

    public function test_company_name_translates_to_exact_companyname_tag(): void
    {
        $result = ZohoCampaignsDriver::translateMergeTags('Société : {{company.name}}');
        $this->assertSame('Société : $[COMPANYNAME]$', $result);
    }

    public function test_unsubscribe_url_translates_to_zoho_unsubscribe_tag(): void
    {
        $result = ZohoCampaignsDriver::translateMergeTags('<a href="{{unsubscribe_url}}">Se désabonner</a>');
        $this->assertSame('<a href="$[LI:UNSUBSCRIBE]$">Se désabonner</a>', $result);
    }

    // ── Multiple tags in one HTML string ───────────────────────────────────────

    public function test_multiple_tags_in_html_all_translated(): void
    {
        $input = <<<'HTML'
<p>Bonjour {{contact.name}},</p>
<p>Votre société : {{company.name}}</p>
<p>Email : {{contact.email}}</p>
<p><a href="{{unsubscribe_url}}">Se désabonner</a></p>
HTML;

        $expected = <<<'HTML'
<p>Bonjour $[FNAME|client|client]$,</p>
<p>Votre société : $[COMPANYNAME]$</p>
<p>Email : $[EMAIL]$</p>
<p><a href="$[LI:UNSUBSCRIBE]$">Se désabonner</a></p>
HTML;

        $this->assertSame($expected, ZohoCampaignsDriver::translateMergeTags($input));
    }

    // ── Unknown placeholder passes through unchanged ───────────────────────────

    public function test_unknown_placeholder_passes_through_unchanged(): void
    {
        $input  = 'Téléphone : {{contact.phone}}';
        $result = ZohoCampaignsDriver::translateMergeTags($input);
        $this->assertSame($input, $result);
    }

    public function test_unknown_placeholder_alongside_known_ones(): void
    {
        $input    = 'Bonjour {{contact.name}}, tél : {{contact.phone}}';
        $result   = ZohoCampaignsDriver::translateMergeTags($input);
        $this->assertSame('Bonjour $[FNAME|client|client]$, tél : {{contact.phone}}', $result);
    }

    // ── Plain text without any tags is returned unchanged ─────────────────────

    public function test_plain_text_without_tags_unchanged(): void
    {
        $input  = 'Aucune variable ici. Texte ordinaire.';
        $result = ZohoCampaignsDriver::translateMergeTags($input);
        $this->assertSame($input, $result);
    }

    public function test_empty_string_unchanged(): void
    {
        $this->assertSame('', ZohoCampaignsDriver::translateMergeTags(''));
    }

    public function test_prepare_html_translates_existing_unsubscribe_placeholder_without_appending_another(): void
    {
        $input = '<p>Contenu</p><p class="legacy"><a href="{{unsubscribe_url}}">Se désabonner</a></p>';

        $result = ZohoCampaignsDriver::prepareHtmlContent($input);

        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertStringContainsString('<p class="legacy"><a href="$[LI:UNSUBSCRIBE]$">Se désabonner</a></p>', $result);
    }

    public function test_prepare_html_appends_one_visible_unsubscribe_link_when_source_has_none(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent('<p>Contenu sans pied de page</p>');

        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertStringContainsString('<a href="$[LI:UNSUBSCRIBE]$"', $result);
        $this->assertStringContainsString('Se désabonner', $result);
    }

    public function test_prepare_html_rewrites_legacy_stratus_image_urls(): void
    {
        $legacyLogoUrl = 'https://stratus.campaign-image.com/images/17383084625551_t%C3%A9l%C3%A9chargement-removebg-p_zc_v1_1_962996000020175003.png';
        $legacyLinkedInUrl = 'https://stratus.campaign-image.com/images/17383084636946_linkedin@2x_zc_v1_6_962996000020175003.png';
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<img src="' . $legacyLogoUrl . '"><img src="' . $legacyLinkedInUrl . '">'
        );

        $this->assertStringContainsString('https://fretiq.digaevo.com/assets/media/email/tcl-logo-white.png', $result);
        $this->assertStringContainsString('https://fretiq.digaevo.com/assets/media/email/linkedin.png', $result);
        $this->assertStringNotContainsString('stratus.campaign-image.com', $result);
    }

    public function test_prepare_html_translates_company_name_to_exact_companyname_tag(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<p>{{company.name}}</p><a href="{{unsubscribe_url}}">unsubscribe</a>'
        );

        $this->assertStringContainsString('$[COMPANYNAME]$', $result);
        $this->assertStringNotContainsString('$[COMPANYNAME|', $result);
    }

    public function test_prepare_html_keeps_only_first_of_multiple_clickable_unsubscribe_links(): void
    {
        $input = '<p><a class="first" href="{{unsubscribe_url}}">Premier lien</a></p>'
            . "<p><a data-kind='extra' href='$[LI:UNSUBSCRIBE]$'>Second lien</a></p>";

        $result = ZohoCampaignsDriver::prepareHtmlContent($input);

        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertStringContainsString('<a class="first" href="$[LI:UNSUBSCRIBE]$">Premier lien</a>', $result);
        $this->assertStringContainsString('<p>Second lien</p>', $result);
    }

    public function test_prepare_html_removes_non_clickable_tag_and_appends_driver_footer(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<p>Tag brut : {{unsubscribe_url}}</p>'
        );

        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertSame(1, substr_count($result, 'href="$[LI:UNSUBSCRIBE]$"'));
        $this->assertStringContainsString('Se désabonner', $result);
    }

    public function test_prepare_html_pcre_failure_falls_back_to_one_driver_owned_clickable_link(): void
    {
        $previousLimit = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', '100');
            $result = ZohoCampaignsDriver::prepareHtmlContent(
                '<p><a href="{{unsubscribe_url}}">Premier lien</a></p>'
                    . '<a ' . str_repeat('x', 10000)
                    . ' href="{{unsubscribe_url}}">Lien pathologique</a>'
            );
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }

        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertSame(1, substr_count($result, 'href="$[LI:UNSUBSCRIBE]$"'));
    }

    public function test_prepare_html_inserts_driver_footer_before_full_document_closing_tags(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<!doctype html><html><body><p>Contenu</p></body></html>'
        );

        $this->assertLessThan(stripos($result, '</body>'), strpos($result, 'href="$[LI:UNSUBSCRIBE]$"'));
        $this->assertLessThan(stripos($result, '</html>'), strpos($result, 'href="$[LI:UNSUBSCRIBE]$"'));
    }

    public function test_prepare_html_uses_real_last_body_closing_tag_after_comment_marker(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<html><body><!-- archived </body> marker --><p>Content</p></body></html>'
        );

        $this->assertStringContainsString('<!-- archived </body> marker --><p>Content</p>', $result);
        $this->assertGreaterThan(strpos($result, '<p>Content</p>'), strpos($result, 'href="$[LI:UNSUBSCRIBE]$"'));
        $this->assertLessThan(strripos($result, '</body>'), strpos($result, 'href="$[LI:UNSUBSCRIBE]$"'));
    }

    public function test_prepare_html_preserves_unquoted_legacy_href_placement(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<p class="custom"><a href={{unsubscribe_url}}>Lien sans guillemets</a></p>'
        );

        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertStringContainsString(
            '<p class="custom"><a href=$[LI:UNSUBSCRIBE]$>Lien sans guillemets</a></p>',
            $result,
        );
    }

    public function test_prepare_html_ignores_commented_anchor_in_favour_of_visible_anchor(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<!-- <a href="{{unsubscribe_url}}">archived</a> -->'
                . '<p><a href="{{unsubscribe_url}}">visible</a></p>'
        );

        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertStringContainsString('<!-- <a href="">archived</a> -->', $result);
        $this->assertStringContainsString(
            '<p><a href="$[LI:UNSUBSCRIBE]$">visible</a></p>',
            $result,
        );
    }

    public function test_prepare_html_ignores_commented_body_marker_after_real_closing_tag(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<html><body><p>Content</p></body><!-- archived </body> marker --></html>'
        );

        $this->assertStringContainsString('<p>Content</p><div style=', $result);
        $this->assertStringContainsString('</body><!-- archived </body> marker --></html>', $result);
        $this->assertLessThan(
            strpos($result, '</body><!--'),
            strpos($result, 'href="$[LI:UNSUBSCRIBE]$"'),
        );
    }

    public function test_prepare_html_neutralizes_unclosed_comment_before_visible_footer(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<p>Content</p><!-- archived <a href="{{unsubscribe_url}}">hidden</a>'
        );

        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertStringContainsString('<!-- archived <a href="">hidden</a>-->', $result);
        $this->assertLessThan(strpos($result, 'href="$[LI:UNSUBSCRIBE]$"'), strpos($result, '-->'));
    }

    public function test_prepare_html_closes_unclosed_script_before_footer_injection(): void
    {
        $result = ZohoCampaignsDriver::prepareHtmlContent(
            '<p>Content</p><script>var x="</body>";</script-no-close>'
        );

        $this->assertStringContainsString('</script-no-close></script>', $result);
        $this->assertLessThan(strpos($result, 'href="$[LI:UNSUBSCRIBE]$"'), strpos($result, '</script>'));
    }

    public function test_prepare_html_scans_large_unclosed_comment_without_pcre_error(): void
    {
        $source = '<p>Content</p><!--' . str_repeat('x', 1250000)
            . '<a href="{{unsubscribe_url}}">hidden</a>';

        $previousLimit = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', '1');
            $result = ZohoCampaignsDriver::prepareHtmlContent($source);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }

        $this->assertSame(PREG_NO_ERROR, preg_last_error());
        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertLessThan(strpos($result, 'href="$[LI:UNSUBSCRIBE]$"'), strrpos($result, '-->'));
    }

    public function test_prepare_html_large_unclosed_raw_text_block_avoids_pcre_backtracking(): void
    {
        $source = '<p>Content</p><script>' . str_repeat('</script-no-close>', 70000)
            . '{{unsubscribe_url}}';

        $result = ZohoCampaignsDriver::prepareHtmlContent($source);

        $this->assertSame(PREG_NO_ERROR, preg_last_error());
        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertLessThan(strpos($result, 'href="$[LI:UNSUBSCRIBE]$"'), strrpos($result, '</script>'));
    }

    // ── append_unsubscribe_fallback config gate (Phase 4) ──────────────────────

    public function test_prepare_html_appends_nothing_when_fallback_disabled_and_source_has_no_unsubscribe_link(): void
    {
        $this->bindAppendUnsubscribeFallback(false);

        $result = ZohoCampaignsDriver::prepareHtmlContent('<p>Contenu du builder, sans section désabonnement</p>');

        $this->assertSame(0, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertStringNotContainsString('Se désabonner', $result);
        $this->assertSame('<p>Contenu du builder, sans section désabonnement</p>', $result);
    }

    /**
     * Multiple unsubscribe anchors, with the PATHOLOGICAL one NOT first (a clean,
     * successfully-substitutable anchor precedes it). Guards against a false negative
     * where the driver would read "no failure" (e.g. from global PCRE error state,
     * which later successful preg_* operations can reset) even though normalization
     * genuinely failed and could not guarantee the original link survived.
     *
     * Empirical note: with UnsubscribeHtmlNormalizer's current anchor-matching regex,
     * a directly-instrumented repro (callback wrapped with a call counter) showed the
     * outer preg_replace_callback() call fails atomically for the WHOLE call — the
     * callback is invoked ZERO times, even for the earlier clean anchor — once the
     * pathological anchor is present anywhere in the subject, regardless of ini
     * pcre.backtrack_limit (default 1_000_000 already fails at this padding size; the
     * error is PREG_JIT_STACKLIMIT_ERROR, not PREG_BACKTRACK_LIMIT_ERROR). So a true
     * "one match callback succeeds, THEN a later match fails" internal sequencing could
     * NOT be constructed against this regex — PHP/PCRE aborts before invoking any
     * callback once the pathological content is anywhere in scope. This test therefore
     * validates the requested input SHAPE (multiple anchors, pathological not first) and
     * confirms the driver still appends the rescue footer for it; it does not (and per
     * this empirical finding, currently cannot) additionally prove the callback ran for
     * the first anchor before the failure was detected. The fix is nonetheless correct
     * for the false-negative class this guards against: it reads normalize()'s explicit
     * $failed return element rather than global PCRE error state, so it is not sensitive
     * to how many matches happened to succeed before the failure was detected.
     */
    public function test_prepare_html_still_appends_fallback_on_normalizer_pcre_failure_even_when_flag_disabled(): void
    {
        $this->bindAppendUnsubscribeFallback(false);

        $previousLimit = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', '100');
            $result = ZohoCampaignsDriver::prepareHtmlContent(
                '<p><a href="{{unsubscribe_url}}">Premier lien</a></p>'
                    . '<a ' . str_repeat('x', 10000)
                    . ' href="{{unsubscribe_url}}">Lien pathologique</a>'
            );
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }

        // Rescue holds regardless of the flag: the normalizer could not guarantee
        // the original link survived, so the driver-owned footer is appended anyway.
        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertSame(1, substr_count($result, 'href="$[LI:UNSUBSCRIBE]$"'));
        $this->assertStringContainsString('Se désabonner', $result);
    }

    public function test_prepare_html_still_translates_and_keeps_legacy_unsubscribe_link_when_flag_disabled(): void
    {
        $this->bindAppendUnsubscribeFallback(false);

        $input = '<p>Contenu</p><p class="legacy"><a href="{{unsubscribe_url}}">Se désabonner</a></p>';

        $result = ZohoCampaignsDriver::prepareHtmlContent($input);

        $this->assertSame(1, substr_count($result, '$[LI:UNSUBSCRIBE]$'));
        $this->assertStringContainsString('<p class="legacy"><a href="$[LI:UNSUBSCRIBE]$">Se désabonner</a></p>', $result);
    }
}
