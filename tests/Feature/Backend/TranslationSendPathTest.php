<?php

namespace Tests\Feature\Backend;

use App\Mail\CampaignMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\LocalCampaignsDriver;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Send-path tests for language resolution.
 *
 * Verifies that LocalCampaignsDriver::send() picks the right language (EN/FR)
 * based on the contact's company country, and that override subjects are not
 * translated.
 *
 * Mail::fake() is used throughout — no real emails are sent.
 * Http::fake() is NOT needed here because no Gemini calls happen at send time.
 */
class TranslationSendPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        config([
            'services.zoho.driver' => 'local',
            'translation.base_language'     => 'fr',
            'translation.target_languages'  => ['en'],
            'translation.francophone_countries' => ['FR', 'BE', 'LU', 'MC', 'CH', 'CA'],
            'translation.footer' => [
                'fr' => ['intro' => 'Vous recevez cet email.', 'link_label' => 'Se désabonner'],
                'en' => ['intro' => 'You are receiving this email.', 'link_label' => 'Unsubscribe'],
            ],
        ]);

        Mail::fake();
    }

    // ── Fixture helpers ────────────────────────────────────────────────────────

    private function makeTemplate(array $overrides = []): CampaignTemplate
    {
        return CampaignTemplate::create(array_merge([
            'name'         => 'Template FR Base',
            'subject'      => 'Sujet en français {{contact.name}}',
            'html_content' => '<p>Bonjour {{contact.name}}, contenu FR.</p>',
            'preview_text' => 'Aperçu FR',
        ], $overrides));
    }

    private function addEnTranslation(CampaignTemplate $template): CampaignTemplateTranslation
    {
        $hashes = $template->sourceHashes();

        return CampaignTemplateTranslation::create([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Subject in English {{contact.name}}',
            'html_content'         => '<p>Hello {{contact.name}}, EN content.</p>',
            'preview_text'         => 'EN preview',
            'is_ai_generated'      => true,
            'reviewed_at'          => null,
            'src_subject_hash'     => $hashes['subject'],
            'src_preview_hash'     => $hashes['preview'],
            'src_body_hash'        => $hashes['body'],
        ]);
    }

    private function makeSender(): SenderIdentity
    {
        return SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => 'noreply@tcl.test',
        ]);
    }

    private function makeContactWithCountry(?string $country, string $email): array
    {
        $co = Company::create([
            'name'                 => "Company {$email}",
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'country'              => $country,
        ]);

        $ct = Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Test User',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        $ct->load('company');

        return [$co, $ct];
    }

    private function makeCampaign(Segment $segment, CampaignTemplate $template, SenderIdentity $sender, ?string $subjectOverride = null): Campaign
    {
        return Campaign::create([
            'name'               => 'Test Campaign',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
            'subject'            => $subjectOverride,
        ]);
    }

    private function makeRecipient(CampaignRun $run, Contact $contact): CampaignRecipient
    {
        return CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact->id,
            'email'           => $contact->email,
            'status'          => 'queued',
        ]);
    }

    private function makeRun(Campaign $campaign): CampaignRun
    {
        return CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'status'         => 'sending',
            'driver'         => 'local',
            'occurrence_key' => 'test-' . \Illuminate\Support\Str::uuid(),
            'run_at'         => now(),
        ]);
    }

    private function driverSend(CampaignRecipient $recipient, Campaign $campaign, CampaignRun $run): string
    {
        $driver = app(LocalCampaignsDriver::class);
        $token  = 'test-token-' . $recipient->id;
        $unsub  = 'https://example.test/unsubscribe/' . $recipient->id;

        return $driver->send($recipient, $campaign, $run, $token, $unsub);
    }

    // ── DE contact → EN content ───────────────────────────────────────────────

    public function test_de_contact_receives_en_html_and_footer(): void
    {
        $template = $this->makeTemplate();
        $enTr = $this->addEnTranslation($template);
        $template->load('translations');

        $sender   = $this->makeSender();
        $segment  = Segment::create(['name' => 'Test', 'scope' => 'client']);
        $campaign = $this->makeCampaign($segment, $template, $sender);
        $run      = $this->makeRun($campaign);

        [, $deContact] = $this->makeContactWithCountry('DE', 'de@acme.test');
        $recipient = $this->makeRecipient($run, $deContact);

        $this->driverSend($recipient, $campaign, $run);

        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($deContact, $enTr) {
            if (! $mail->hasTo($deContact->email)) {
                return false;
            }

            // Render the content to inspect HTML
            $rendered = $mail->render();

            // Must contain EN html_content (after merge tag substitution)
            $this->assertStringContainsString('EN content', $rendered,
                'DE contact must receive EN HTML content');

            // Must contain EN footer
            $this->assertStringContainsString('Unsubscribe', $rendered,
                'DE contact must receive EN unsubscribe footer');
            $this->assertStringNotContainsString('Se désabonner', $rendered,
                'DE contact must NOT receive FR footer');

            return true;
        });
    }

    // ── FR contact → FR base content ─────────────────────────────────────────

    public function test_fr_contact_receives_fr_html_and_footer(): void
    {
        $template = $this->makeTemplate();
        $this->addEnTranslation($template); // EN exists, but FR contact must get FR
        $template->load('translations');

        $sender   = $this->makeSender();
        $segment  = Segment::create(['name' => 'Test', 'scope' => 'client']);
        $campaign = $this->makeCampaign($segment, $template, $sender);
        $run      = $this->makeRun($campaign);

        [, $frContact] = $this->makeContactWithCountry('FR', 'fr@acme.test');
        $recipient = $this->makeRecipient($run, $frContact);

        $this->driverSend($recipient, $campaign, $run);

        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($frContact) {
            if (! $mail->hasTo($frContact->email)) {
                return false;
            }

            $rendered = $mail->render();

            $this->assertStringContainsString('contenu FR', $rendered,
                'FR contact must receive FR HTML content');
            $this->assertStringContainsString('Se désabonner', $rendered,
                'FR contact must receive FR footer');
            $this->assertStringNotContainsString('Unsubscribe', $rendered,
                'FR contact must NOT receive EN footer');

            return true;
        });
    }

    // ── Null country → FR base ────────────────────────────────────────────────

    public function test_null_country_contact_receives_fr_content(): void
    {
        $template = $this->makeTemplate();
        $this->addEnTranslation($template);
        $template->load('translations');

        $sender   = $this->makeSender();
        $segment  = Segment::create(['name' => 'Test', 'scope' => 'client']);
        $campaign = $this->makeCampaign($segment, $template, $sender);
        $run      = $this->makeRun($campaign);

        [, $nullContact] = $this->makeContactWithCountry(null, 'unknown@acme.test');
        $recipient = $this->makeRecipient($run, $nullContact);

        $this->driverSend($recipient, $campaign, $run);

        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($nullContact) {
            if (! $mail->hasTo($nullContact->email)) {
                return false;
            }

            $rendered = $mail->render();
            $this->assertStringContainsString('contenu FR', $rendered,
                'Null-country contact must receive FR content');

            return true;
        });
    }

    // ── Override subject untranslated ─────────────────────────────────────────

    public function test_campaign_subject_override_used_unchanged_for_de_contact(): void
    {
        $template = $this->makeTemplate();
        $this->addEnTranslation($template);
        $template->load('translations');

        $sender   = $this->makeSender();
        $segment  = Segment::create(['name' => 'Test', 'scope' => 'client']);

        // Campaign has a French subject override
        $campaign = $this->makeCampaign($segment, $template, $sender, 'Sujet override en français');
        $run      = $this->makeRun($campaign);

        [, $deContact] = $this->makeContactWithCountry('DE', 'override-de@acme.test');
        $recipient = $this->makeRecipient($run, $deContact);

        $this->driverSend($recipient, $campaign, $run);

        // Subject is the override (FR), not the EN template subject
        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($deContact) {
            if (! $mail->hasTo($deContact->email)) {
                return false;
            }

            // The subject must be the override (after merge tag rendering)
            $this->assertSame(
                'Sujet override en français',
                $mail->envelope()->subject,
                'Campaign subject override must be used unchanged for DE recipient'
            );

            // Body must still be EN
            $rendered = $mail->render();
            $this->assertStringContainsString('EN content', $rendered,
                'Body must still be EN even when subject is overridden');

            return true;
        });
    }

    // ── No EN row → FR base for DE contact ───────────────────────────────────

    public function test_de_contact_gets_fr_base_when_no_en_row(): void
    {
        $template = $this->makeTemplate();
        // Deliberately NO EN translation

        $sender   = $this->makeSender();
        $segment  = Segment::create(['name' => 'Test', 'scope' => 'client']);
        $campaign = $this->makeCampaign($segment, $template, $sender);
        $run      = $this->makeRun($campaign);

        [, $deContact] = $this->makeContactWithCountry('DE', 'deno@acme.test');
        $recipient = $this->makeRecipient($run, $deContact);

        $this->driverSend($recipient, $campaign, $run);

        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($deContact) {
            if (! $mail->hasTo($deContact->email)) {
                return false;
            }

            $rendered = $mail->render();
            $this->assertStringContainsString('contenu FR', $rendered,
                'DE contact must fall back to FR when no EN row exists');

            return true;
        });
    }

    // ── FIX 2 regression: footer must not crash or vanish when config is null ─

    /**
     * When config('translation.footer') is null (misconfiguration or missing file),
     * CampaignMailable must still render a non-empty unsubscribe footer using the
     * hardcoded FR fallback, and must not throw an error.
     */
    public function test_null_footer_config_renders_hardcoded_fallback(): void
    {
        // Override footer config to null — simulates missing/broken config
        config()->set('translation.footer', null);

        $template = $this->makeTemplate();
        $this->addEnTranslation($template);
        $template->load('translations');

        $sender   = $this->makeSender();
        $segment  = Segment::create(['name' => 'NullFooter', 'scope' => 'client']);
        $campaign = $this->makeCampaign($segment, $template, $sender);
        $run      = $this->makeRun($campaign);

        [, $deContact] = $this->makeContactWithCountry('DE', 'nullfooter@acme.test');
        $recipient = $this->makeRecipient($run, $deContact);

        // Must not throw
        $this->driverSend($recipient, $campaign, $run);

        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($deContact) {
            if (! $mail->hasTo($deContact->email)) {
                return false;
            }

            $rendered = $mail->render();

            // Must contain SOME unsubscribe text — the hardcoded FR fallback
            $this->assertStringContainsString('Se désabonner', $rendered,
                'Null footer config must fall back to hardcoded FR unsubscribe text');

            // Must not be empty around the footer area
            $this->assertNotEmpty($rendered, 'Rendered email must not be empty');

            return true;
        });
    }

    // ── Full CampaignService::sendRun() with mixed countries ─────────────────

    public function test_send_run_mixed_countries_receive_correct_language(): void
    {
        $template = $this->makeTemplate();
        $this->addEnTranslation($template);

        $sender   = $this->makeSender();
        $segment  = Segment::create(['name' => 'Mixed', 'scope' => 'client']);
        $campaign = $this->makeCampaign($segment, $template, $sender);

        // Create contacts for DE, FR, null country
        [, $deContact]   = $this->makeContactWithCountry('DE', 'de@mixed.test');
        [, $frContact]   = $this->makeContactWithCountry('FR', 'fr@mixed.test');
        [, $nullContact] = $this->makeContactWithCountry(null, 'null@mixed.test');

        $service = app(CampaignService::class);
        $run = $service->scheduleOneShot($campaign);
        $service->sendRun($run);

        // 3 emails must have been sent
        Mail::assertSentCount(3);

        // DE contact → EN footer
        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($deContact) {
            if (! $mail->hasTo($deContact->email)) {
                return false;
            }
            $this->assertStringContainsString('Unsubscribe', $mail->render());
            return true;
        });

        // FR contact → FR footer
        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($frContact) {
            if (! $mail->hasTo($frContact->email)) {
                return false;
            }
            $this->assertStringContainsString('Se désabonner', $mail->render());
            return true;
        });

        // Null country → FR footer
        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($nullContact) {
            if (! $mail->hasTo($nullContact->email)) {
                return false;
            }
            $this->assertStringContainsString('Se désabonner', $mail->render());
            return true;
        });
    }
}
