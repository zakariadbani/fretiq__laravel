<?php

namespace Tests\Unit;

use App\Models\SenderIdentity;
use App\Services\Campaign\TemplateBuilder\SectionCatalog;
use App\Services\Campaign\TemplateBuilder\TemplateComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Footer contact email is compose-time — resolved from the default+active
 * SenderIdentity and passed into TemplateComposer::compose() by the caller
 * (compose() itself stays DB-free, see TemplateComposerTest docblock).
 * Replaces the old string-literal grep against footer-detailed.blade.php,
 * which broke by construction once the mailto/text became dynamic.
 */
class CampaignTemplateContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function state(): array
    {
        $state = SectionCatalog::defaultState();
        $state['footer_variant'] = 'detailed';

        return $state;
    }

    public function test_detailed_footer_uses_the_default_sender_identity_email(): void
    {
        SenderIdentity::create([
            'name' => 'Sales — TCL France',
            'email' => 'ventes@tcltransport.com',
            'is_default' => true,
            'is_active' => true,
        ]);

        $html = (new TemplateComposer())->compose($this->state(), 'fr', SenderIdentity::defaultContactEmail());

        $this->assertStringContainsString('mailto:ventes@tcltransport.com', $html);
        $this->assertStringContainsString('>ventes@tcltransport.com</a>', $html);
    }

    public function test_detailed_footer_falls_back_to_the_literal_default_when_no_sender_identity_is_resolved(): void
    {
        $html = (new TemplateComposer())->compose($this->state(), 'fr', SenderIdentity::defaultContactEmail());

        $this->assertStringContainsString('mailto:sales@tcltransport.com', $html);
        $this->assertStringContainsString('>sales@tcltransport.com</a>', $html);
    }
}
