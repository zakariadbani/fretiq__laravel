<?php

namespace Tests\Unit;

use App\Services\Settings\SettingService;
use App\Support\DomainBlocklist;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * DomainBlocklistTest — settings-backed blocklist with code-level defaults.
 *
 * Extends Tests\TestCase (not PHPUnit\Framework\TestCase) because the blocklist
 * reads Setting::get(), which needs the Laravel container.
 *
 * No database: settings are injected by priming the SettingService cache key
 * ('app_settings'), which is exactly what SettingService::loadMap() reads. That
 * short-circuits buildMap() — and its Schema::hasTable('settings') probe — so the
 * suite never opens a connection. Same idiom as tests/Unit/HomepageSnapshotServiceTest.php.
 */
class DomainBlocklistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        $this->settings([]);
    }

    /**
     * Inject the settings map without touching the database.
     *
     * @param  array<string, mixed>  $map
     */
    private function settings(array $map): void
    {
        // clearCache() first: it wipes the in-process memo on the resolved
        // SettingService singleton AND the cache key, so the put() below is what
        // the next loadMap() call sees.
        app(SettingService::class)->clearCache();
        Cache::put('app_settings', $map, 3600);
    }

    /** Store a single decouverte.* setting, replacing whatever was there. */
    private function store(string $key, mixed $value): void
    {
        $this->settings(["decouverte.{$key}" => $value]);
    }

    // ── Defaults ─────────────────────────────────────────────────────────────────

    public function test_defaults_are_used_when_no_setting_row_exists(): void
    {
        $blocklist = new DomainBlocklist();

        $this->assertSame(DomainBlocklist::DEFAULT_DOMAINS, $blocklist->domains());
        $this->assertSame(DomainBlocklist::DEFAULT_EXTENSIONS, $blocklist->extensions());
        $this->assertTrue($blocklist->isBlocked('linkedin.com'));
        $this->assertTrue($blocklist->isBlocked('scribd.com'));
    }

    public function test_defaults_are_used_when_setting_is_blank(): void
    {
        $this->store('blocked_domains', "   \n  ");

        $this->assertSame(DomainBlocklist::DEFAULT_DOMAINS, (new DomainBlocklist())->domains());
    }

    public function test_default_text_helpers_seed_the_settings_fields(): void
    {
        $this->assertStringContainsString("linkedin.com\n", DomainBlocklist::defaultDomainsText());
        $this->assertSame('pdf,doc,docx,ppt,pptx,xls,xlsx', DomainBlocklist::defaultExtensionsText());
    }

    // ── Setting overrides ────────────────────────────────────────────────────────

    public function test_setting_overrides_the_defaults(): void
    {
        $this->store('blocked_domains', "spam.test\njunk.test");

        $blocklist = new DomainBlocklist();

        $this->assertSame(['spam.test', 'junk.test'], $blocklist->domains());
        $this->assertTrue($blocklist->isBlocked('spam.test'));
        // linkedin.com is a default but is NOT in the override → allowed
        $this->assertFalse($blocklist->isBlocked('linkedin.com'));
    }

    public function test_comment_lines_and_blank_lines_are_ignored(): void
    {
        $this->store('blocked_domains', "# médias\nspam.test\n\n   \n# annuaires\njunk.test\n");

        $this->assertSame(['spam.test', 'junk.test'], (new DomainBlocklist())->domains());
    }

    public function test_comma_separated_input_is_accepted(): void
    {
        $this->store('blocked_domains', 'spam.test, junk.test,WWW.Noise.TEST');

        $this->assertSame(
            ['spam.test', 'junk.test', 'noise.test'],
            (new DomainBlocklist())->domains()
        );
    }

    public function test_a_changed_setting_invalidates_the_memo(): void
    {
        // One long-lived instance: the point is that DomainBlocklist's OWN memo
        // (keyed on the raw setting string) re-reads when the setting changes.
        $blocklist = new DomainBlocklist();

        $this->store('blocked_domains', 'first.test');
        $this->assertSame(['first.test'], $blocklist->domains());

        $this->store('blocked_domains', 'second.test');
        $this->assertSame(['second.test'], $blocklist->domains());
    }

    // ── Matching semantics ───────────────────────────────────────────────────────

    public function test_subdomains_are_blocked_by_the_parent_domain(): void
    {
        $blocklist = new DomainBlocklist();

        $this->assertTrue($blocklist->isBlocked('www.douane.gouv.fr'));
        $this->assertTrue($blocklist->isBlocked('fr.linkedin.com'));
        $this->assertTrue($blocklist->isBlocked('FR.WIKIPEDIA.ORG'));
    }

    public function test_no_false_positives_on_lookalike_domains(): void
    {
        $blocklist = new DomainBlocklist();

        $this->assertFalse($blocklist->isBlocked('mon-scribd.ma'));
        $this->assertFalse($blocklist->isBlocked('notlinkedin.com'));
        $this->assertFalse($blocklist->isBlocked('gouv.fr.example.com'));
        $this->assertFalse($blocklist->isBlocked('transitaire-logistique.fr'));
        $this->assertFalse($blocklist->isBlocked(''));
    }

    // ── Extensions / URL matching ────────────────────────────────────────────────

    public function test_extensions_setting_overrides_defaults(): void
    {
        $this->store('blocked_url_extensions', '.ZIP, rar');

        $this->assertSame(['zip', 'rar'], (new DomainBlocklist())->extensions());
    }

    public function test_blocked_url_matches_document_paths_case_insensitively(): void
    {
        $blocklist = new DomainBlocklist();

        $this->assertTrue($blocklist->isBlockedUrl('https://x.ma/rapport.PDF'));
        $this->assertTrue($blocklist->isBlockedUrl('https://x.ma/docs/bilan.xlsx?download=1&v=2'));
    }

    public function test_blocked_url_ignores_paths_that_merely_mention_the_extension(): void
    {
        $blocklist = new DomainBlocklist();

        $this->assertFalse($blocklist->isBlockedUrl('https://x.ma/pdf-guide'));
        $this->assertFalse($blocklist->isBlockedUrl('https://x.ma/'));
        $this->assertFalse($blocklist->isBlockedUrl('https://x.ma'));
    }
}
