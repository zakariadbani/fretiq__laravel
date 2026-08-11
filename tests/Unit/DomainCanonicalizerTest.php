<?php

namespace Tests\Unit;

use App\Services\Discovery\DomainCanonicalizer;
use Pdp\Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DomainCanonicalizerTest extends TestCase
{
    #[DataProvider('domains')]
    public function test_it_normalizes_url_idn_and_multilevel_suffix(string $input, ?string $host, ?string $registrable): void
    {
        $result = app(DomainCanonicalizer::class)->canonicalize($input);

        $this->assertSame($host, $result?->host);
        $this->assertSame($registrable, $result?->registrableDomain);
    }

    public static function domains(): array
    {
        return [
            ['https://WWW.Example.CO.UK/path', 'example.co.uk', 'example.co.uk'],
            ['https://bücher.de', 'xn--bcher-kva.de', 'xn--bcher-kva.de'],
            ['sub.example.com.au', 'sub.example.com.au', 'example.com.au'],
            ['not a domain', null, null],
            ['127.0.0.1', null, null],
            ['example.invalidtld', null, null],
        ];
    }

    public function test_it_identifies_platform_hosts_without_false_positive_lookalikes(): void
    {
        $canonicalizer = app(DomainCanonicalizer::class);

        $this->assertTrue($canonicalizer->isPlatform('fr.linkedin.com'));
        $this->assertTrue($canonicalizer->isPlatform('maps.google.com'));
        $this->assertTrue($canonicalizer->isPlatform('company.sharepoint.com'));
        $this->assertTrue($canonicalizer->isPlatform('company.github.io'));
        $this->assertTrue($canonicalizer->isPlatform('www.societe.com'));
        $this->assertTrue($canonicalizer->isPlatform('tenant.pages.dev'));
        $this->assertTrue($canonicalizer->isPlatform('tenant.herokuapp.com'));
        $this->assertFalse($canonicalizer->isPlatform('notlinkedin.com'));
    }

    #[DataProvider('unsafeOrUnsupportedInputs')]
    public function test_it_rejects_unsafe_or_unsupported_url_inputs(string $input): void
    {
        $this->assertNull(app(DomainCanonicalizer::class)->canonicalize($input));
    }

    public static function unsafeOrUnsupportedInputs(): array
    {
        return [
            ['sales@example.com'],
            ['mailto:sales@example.com'],
            ['https://user:secret@example.com'],
            ['ftp://example.com'],
            ['javascript://example.com'],
        ];
    }

    public function test_it_accepts_scheme_relative_urls_as_https_style_input(): void
    {
        $result = app(DomainCanonicalizer::class)->canonicalize('//example.com/path');

        $this->assertNotNull($result);
        $this->assertSame('example.com', $result->host);
        $this->assertSame('example.com', $result->registrableDomain);
    }

    #[DataProvider('privateSuffixDomains')]
    public function test_it_keeps_tenants_of_private_suffixes_as_registrable_domains(string $input, string $expected): void
    {
        $result = app(DomainCanonicalizer::class)->canonicalize($input);

        $this->assertNotNull($result);
        $this->assertSame($expected, $result->registrableDomain);
        $this->assertTrue($result->isPlatform);
    }

    public static function privateSuffixDomains(): array
    {
        return [
            ['tenant.pages.dev', 'tenant.pages.dev'],
            ['tenant.herokuapp.com', 'tenant.herokuapp.com'],
        ];
    }

    public function test_idn_is_returned_as_the_expected_punycode_host(): void
    {
        $result = app(DomainCanonicalizer::class)->canonicalize('https://bücher.de');

        $this->assertNotNull($result);
        $this->assertSame('xn--bcher-kva.de', $result->host);
        $this->assertSame('xn--bcher-kva.de', $result->registrableDomain);
    }

    public function test_the_local_public_suffix_list_is_loaded_once_by_the_container(): void
    {
        $this->assertFileExists(config('prospecting.public_suffix_list_path'));
        $this->assertSame(app(Rules::class), app(Rules::class));
    }
}
