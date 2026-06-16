<?php

namespace Tests\Unit;

use App\Services\Translation\LanguageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for LanguageResolver::forCountry().
 *
 * No DB writes — reads config only, which is booted via Laravel TestCase.
 */
class LanguageResolverTest extends TestCase
{
    private LanguageResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new LanguageResolver();
    }

    // ── Francophone countries → 'fr' ───────────────────────────────────────────

    public function test_fr_resolves_to_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry('FR'));
    }

    public function test_be_resolves_to_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry('BE'));
    }

    public function test_lu_resolves_to_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry('LU'));
    }

    public function test_mc_resolves_to_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry('MC'));
    }

    public function test_ch_resolves_to_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry('CH'));
    }

    public function test_ca_resolves_to_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry('CA'));
    }

    // ── Non-francophone countries → 'en' ──────────────────────────────────────

    public function test_de_resolves_to_english(): void
    {
        $this->assertSame('en', $this->resolver->forCountry('DE'));
    }

    public function test_us_resolves_to_english(): void
    {
        $this->assertSame('en', $this->resolver->forCountry('US'));
    }

    public function test_gb_resolves_to_english(): void
    {
        $this->assertSame('en', $this->resolver->forCountry('GB'));
    }

    public function test_nl_resolves_to_english(): void
    {
        $this->assertSame('en', $this->resolver->forCountry('NL'));
    }

    public function test_cn_resolves_to_english(): void
    {
        $this->assertSame('en', $this->resolver->forCountry('CN'));
    }

    // ── Null/empty/unknown → default (fr) ─────────────────────────────────────

    public function test_null_country_resolves_to_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry(null));
    }

    public function test_empty_string_resolves_to_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry(''));
    }

    public function test_whitespace_only_resolves_to_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry('   '));
    }

    public function test_unmapped_code_zz_resolves_to_english(): void
    {
        // 'ZZ' is not in francophone_countries, so it resolves to 'en'
        $this->assertSame('en', $this->resolver->forCountry('ZZ'));
    }

    // ── Case normalization ─────────────────────────────────────────────────────

    public function test_lowercase_de_normalizes_to_uppercase_de_resolves_english(): void
    {
        $this->assertSame('en', $this->resolver->forCountry('de'));
    }

    public function test_lowercase_fr_normalizes_resolves_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry('fr'));
    }

    public function test_mixed_case_be_resolves_french(): void
    {
        $this->assertSame('fr', $this->resolver->forCountry('Be'));
    }

    public function test_mixed_case_us_resolves_english(): void
    {
        $this->assertSame('en', $this->resolver->forCountry('uS'));
    }
}
