<?php

namespace Tests\Unit;

use App\Services\Campaign\ZohoCampaignsDriver;
use PHPUnit\Framework\TestCase;

/**
 * Pure static unit tests for ZohoCampaignsDriver::translateMergeTags().
 *
 * No network, no DB, no Laravel bootstrap required.
 */
class ZohoMergeTagTranslationTest extends TestCase
{
    // ── Individual placeholder translations ────────────────────────────────────

    public function test_contact_name_translates_to_fname_tag(): void
    {
        $result = ZohoCampaignsDriver::translateMergeTags('Bonjour {{contact.name}},');
        $this->assertSame('Bonjour $[FNAME]$,', $result);
    }

    public function test_contact_email_translates_to_email_tag(): void
    {
        $result = ZohoCampaignsDriver::translateMergeTags('Votre email : {{contact.email}}');
        $this->assertSame('Votre email : $[EMAIL]$', $result);
    }

    public function test_company_name_translates_to_company_tag(): void
    {
        $result = ZohoCampaignsDriver::translateMergeTags('Société : {{company.name}}');
        $this->assertSame('Société : $[COMPANY]$', $result);
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
<p>Bonjour $[FNAME]$,</p>
<p>Votre société : $[COMPANY]$</p>
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
        $this->assertSame('Bonjour $[FNAME]$, tél : {{contact.phone}}', $result);
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
}
