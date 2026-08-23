<?php

namespace Tests\Unit\Services\Campaign;

use App\Models\CampaignTemplate;
use App\Services\Campaign\CampaignTemplateHtmlImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CampaignTemplateHtmlImporterTest extends TestCase
{
    use RefreshDatabase;

    private function validHtml(): string
    {
        return "<!--\nCampagne : Relance clients inactifs\nObjet : On vous a manqué ?\n-->\n"
            .'<html><body><p>Bonjour {{contact.first_name}}, à bientôt.</p>'
            .'<a href="{{unsubscribe_url}}">Se désabonner</a></body></html>';
    }

    public function test_it_parses_the_header_comment_and_upserts_by_name(): void
    {
        $importer = app(CampaignTemplateHtmlImporter::class);

        $rows = $importer->parse([
            ['filename' => 'relance.html', 'contents' => $this->validHtml()],
        ]);

        $this->assertSame('Relance clients inactifs', $rows[0]['name']);
        $this->assertSame('On vous a manqué ?', $rows[0]['subject']);
        $this->assertFalse($rows[0]['will_update']);

        $result = $importer->store($rows);
        $this->assertSame(['created' => 1, 'updated' => 0], $result);
        $this->assertDatabaseHas('campaign_templates', [
            'name' => 'Relance clients inactifs',
            'subject' => 'On vous a manqué ?',
        ]);

        // Re-importing the same name (case-insensitive) updates rather than duplicates.
        $again = $importer->parse([
            ['filename' => 'RELANCE.html', 'contents' => str_replace('Relance clients inactifs', 'RELANCE CLIENTS INACTIFS', $this->validHtml())],
        ]);
        $this->assertTrue($again[0]['will_update']);

        $result = $importer->store($again);
        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertDatabaseCount('campaign_templates', 1);
    }

    public function test_it_rejects_a_file_with_a_merge_tag_outside_the_supported_set(): void
    {
        $html = "<!--\nObjet : Sujet\n-->\n<p>Téléphone : {{contact.phone}}</p>";
        $importer = app(CampaignTemplateHtmlImporter::class);

        try {
            $importer->parse([['filename' => 'bad.html', 'contents' => $html]]);
            $this->fail('Expected a validation exception for an unsupported merge tag.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('html', $exception->errors());
            $this->assertStringContainsString('{{contact.phone}}', $exception->errors()['html'][0]);
        }

        $this->assertDatabaseCount('campaign_templates', 0);
    }

    /**
     * {{contact.email}} is a supported merge tag (RendersTrackedHtml::renderMergeTags())
     * but was missing from ALLOWED_MERGE_TAGS — a legitimate template using it
     * must be accepted, not rejected outright.
     */
    public function test_it_accepts_the_contact_email_merge_tag(): void
    {
        $html = "<!--\nObjet : Sujet\n-->\n<p>Email : {{contact.email}}</p>";
        $importer = app(CampaignTemplateHtmlImporter::class);

        $rows = $importer->parse([['filename' => 'ok.html', 'contents' => $html]]);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('{{contact.email}}', $rows[0]['html_content']);
    }

    /**
     * A `Nom :` header line must win over `Campagne :` — needed because a
     * multi-email campaign (e1/e2) shares one `Campagne :` line but each
     * email needs its own distinct template name.
     */
    public function test_nom_header_takes_priority_over_campagne_header(): void
    {
        $html = "<!--\nNom : Relance V2\nCampagne : Relance clients inactifs\nObjet : On vous a manqué ?\n-->\n"
            .'<html><body><p>Bonjour {{contact.first_name}}.</p></body></html>';
        $importer = app(CampaignTemplateHtmlImporter::class);

        $rows = $importer->parse([['filename' => 'relance.html', 'contents' => $html]]);

        $this->assertSame('Relance V2', $rows[0]['name']);
    }

    /**
     * Two files resolving to the same name WITHIN one upload batch must hard-fail
     * the whole batch (never silently merge one file's row over the other's).
     * Cross-batch upsert-by-name (re-importing later) is unaffected — see
     * test_it_parses_the_header_comment_and_upserts_by_name above.
     */
    public function test_it_rejects_a_batch_with_a_duplicate_name_within_the_same_import(): void
    {
        $html1 = "<!--\nNom : Relance Duo\nObjet : Sujet 1\n-->\n<p>Bonjour {{contact.first_name}}.</p>";
        $html2 = "<!--\nNom : Relance Duo\nObjet : Sujet 2\n-->\n<p>Bonjour {{contact.first_name}}.</p>";
        $importer = app(CampaignTemplateHtmlImporter::class);

        try {
            $importer->parse([
                ['filename' => 'a.html', 'contents' => $html1],
                ['filename' => 'b.html', 'contents' => $html2],
            ]);
            $this->fail('Expected a validation exception for a duplicate name within the batch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('html', $exception->errors());
            $this->assertStringContainsString('b.html', $exception->errors()['html'][0]);
            $this->assertStringContainsString('Relance Duo', $exception->errors()['html'][0]);
        }

        $this->assertDatabaseCount('campaign_templates', 0);
    }
}
