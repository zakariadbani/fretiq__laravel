<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DriverOwnedUnsubscribeContentTest extends TestCase
{
    private function projectFile(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }

    public function test_seeded_templates_do_not_embed_historical_unsubscribe_footers(): void
    {
        foreach ([
            'database/seeders/DefaultProspectionSeeder.php',
            'database/seeders/SequenceSeeder.php',
            'database/seeders/TclFamilleSequenceSeeder.php',
        ] as $path) {
            $source = file_get_contents($this->projectFile($path));

            $this->assertIsString($source);
            $this->assertStringNotContainsString('{{unsubscribe_url}}', $source, $path);
            $this->assertStringNotContainsString('private const FOOTER', $source, $path);
            $this->assertStringNotContainsString('{$footer}', $source, $path);
            $this->assertStringNotContainsString('Pour ne plus recevoir nos messages', $source, $path);
        }
    }

    public function test_template_form_explains_automatic_driver_owned_unsubscribe_link(): void
    {
        $source = file_get_contents($this->projectFile(
            'resources/views/backend/contents/campaign_templates/crud/form.blade.php'
        ));

        $this->assertIsString($source);
        $this->assertStringContainsString('ajouté automatiquement', $source);
        $this->assertStringNotContainsString('Insérez <code>@verbatim{{unsubscribe_url}}@endverbatim</code>', $source);
    }

    public function test_signed_zoho_content_route_uses_the_centralized_html_preparation(): void
    {
        $source = file_get_contents($this->projectFile('routes/web.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'ZohoCampaignsDriver::prepareHtmlContent($run->campaign->template->html_content)',
            $source,
        );
    }
}
