<?php

namespace Tests\Unit;

use App\Services\Campaign\TemplateBuilder\SectionCatalog;
use App\Services\Campaign\TemplateBuilder\TemplateComposer;
use Tests\TestCase;

/**
 * TemplateComposerTest — renders every header × footer × middle combination
 * and asserts the composed HTML is structurally correct, keeps the exact
 * verbatim CDN image URLs, preserves literal merge tags through Blade,
 * carries NO unsubscribe/désabonner content, and escapes user-authored slot
 * copy (XSS guard).
 *
 * Uses the real view()->render() pipeline — needs the Laravel app booted, but
 * no DB.
 */
class TemplateComposerTest extends TestCase
{
    private function composer(): TemplateComposer
    {
        return new TemplateComposer();
    }

    /**
     * @param  array<string, mixed>  $slotOverrides
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function state(string $header, string $footer, string $middle, array $slotOverrides = [], array $overrides = []): array
    {
        $slots = [
            'hero_title' => 'Optimisez vos flux de transport',
            'intro'      => ['Un premier paragraphe.', 'Un second paragraphe pour {{company.name}}.'],
            'bullets'    => ['Bullet un', 'Bullet deux', 'Bullet trois'],
        ];

        if ($middle === 'departures') {
            $slots['departures'] = [
                ['origin' => 'Goussainville (France)', 'frequency' => '4 départs par semaine'],
                ['origin' => 'Barcelone (Espagne)', 'frequency' => '2 à 3 départs par semaine'],
            ];
        } elseif ($middle === 'kpi') {
            $slots['kpis'] = [
                ['value' => '48 h', 'label' => 'Enlèvement'],
                ['value' => 'IATA', 'label' => 'Agent agréé'],
                ['value' => '100 %', 'label' => 'Suivi documentaire'],
            ];
        } elseif ($middle === 'benefits') {
            $slots['benefits'] = [
                ['title' => 'Réactivité', 'text' => 'Un interlocuteur mobilisé pour vos demandes.'],
                ['title' => 'Visibilité', 'text' => 'Des informations à chaque étape.'],
                ['title' => 'Souplesse', 'text' => 'Une réponse adaptée à vos délais.'],
            ];
        } elseif ($middle === 'process') {
            $slots['process_steps'] = SectionCatalog::middleDefaults()['process']['process_steps'];
            $slots['process_highlight'] = SectionCatalog::middleDefaults()['process']['process_highlight'];
        } elseif ($middle === 'case_study') {
            $slots['case_study'] = ['title' => 'Cas client', 'challenge' => 'Flux irrégulier', 'solution' => 'Pilotage TCL', 'result' => 'Délais stabilisés'];
        } elseif ($middle === 'checklist') {
            $slots['checklist_title'] = 'Checklist départ';
            $slots['checklist_items'] = ['Documents validés', 'Marchandise prête', 'Contact confirmé'];
        } elseif ($middle === 'solutions') {
            $slots['solutions'] = [['title' => 'Route', 'text' => 'Départs réguliers'], ['title' => 'Aérien', 'text' => 'Gestion des urgences']];
        } elseif ($middle === 'offer') {
            $slots['offer'] = ['title' => 'Offre dédiée', 'description' => 'Une étude adaptée à vos flux.', 'highlight' => 'Étude personnalisée'];
        }

        $slots = array_replace($slots, $slotOverrides);

        return array_replace([
            'header_variant' => $header,
            'hero_variant'   => SectionCatalog::heroForHeader($header),
            'middle_variant' => $middle,
            'footer_variant' => $footer,
            'preview_text'   => 'Un aperçu de test pour la campagne.',
            'cta'            => ['intent' => 'quote', 'label' => 'Demander une cotation'],
            'slots'          => $slots,
        ], $overrides);
    }

    public function test_quote_and_chatbot_cta_intents_keep_tcl_transport_root(): void
    {
        $intents = SectionCatalog::ctaIntents();

        $this->assertSame('https://tcltransport.com/', $intents['quote']['url']);
        $this->assertSame('https://tcltransport.com/', $intents['chatbot']['url']);
    }

    public function test_services_cta_targets_tcl_transport_services_page(): void
    {
        $this->assertSame(
            'https://tcltransport.com/nos-services/',
            SectionCatalog::ctaIntents()['services']['url']
        );

        $html = $this->composer()->compose($this->state(
            'logo_center',
            'detailed',
            'process',
            [],
            ['cta' => ['intent' => 'services', 'label' => 'Découvrir nos services']],
        ));

        $this->assertStringContainsString('href="https://tcltransport.com/nos-services/"', $html);
    }

    public function test_warehouse_tour_cta_targets_tcl_transport_3d_tour(): void
    {
        $this->assertSame(
            'https://tcltransport.com/visite-virtuelle-360/entrepot/',
            SectionCatalog::ctaIntents()['warehouse_tour']['url']
        );

        $html = $this->composer()->compose($this->state(
            'logo_tagline',
            'compact',
            'offer',
            [],
            ['cta' => ['intent' => 'warehouse_tour', 'label' => 'Visiter nos entrepôts en 3D']],
        ));

        $this->assertStringContainsString('href="https://tcltransport.com/visite-virtuelle-360/entrepot/"', $html);
    }

    public function test_default_state_uses_source_backed_process_content(): void
    {
        $state = SectionCatalog::defaultState();

        $this->assertSame('process', $state['middle_variant']);
        $this->assertSame('services', $state['cta']['intent']);
        $this->assertSame(
            'TCL Transport : Expertise logistique 3PL pour vos besoins en transport',
            $state['slots']['hero_title']
        );
        $this->assertSame(
            SectionCatalog::middleDefaults()['process']['process_steps'],
            $state['slots']['process_steps']
        );
        $this->assertStringNotContainsString('ratio volume/coût', json_encode($state));
    }
    public function test_first_name_can_be_excluded_from_both_heroes_in_french_and_english(): void
    {
        foreach (SectionCatalog::HEADERS as $header) {
            foreach (['fr' => 'Bonjour,', 'en' => 'Hello,'] as $locale => $greeting) {
                $html = $this->composer()->compose($this->state(
                    $header,
                    'compact',
                    'process',
                    overrides: ['include_first_name' => false],
                ), $locale);

                $this->assertStringContainsString('>' . $greeting . '</p>', $html, "header={$header} locale={$locale}");
                $this->assertStringNotContainsString('{{contact.first_name}}', $html, "header={$header} locale={$locale}");
            }
        }
    }


    // ── Every header × footer × middle combination ─────────────────────────────

    public function test_every_header_footer_middle_combination_composes_valid_signature_markup(): void
    {
        foreach (SectionCatalog::HEADERS as $header) {
            foreach (SectionCatalog::FOOTERS as $footer) {
                foreach (SectionCatalog::MIDDLES as $middle) {
                    $html = $this->composer()->compose($this->state($header, $footer, $middle));

                    $context = "header={$header} footer={$footer} middle={$middle}";

                    // ── Doctype + shell ──────────────────────────────────────
                    $this->assertStringStartsWith('<!DOCTYPE html', trim($html), $context);

                    // ── Header signature ─────────────────────────────────────
                    if ($header === 'logo_center') {
                        $this->assertStringContainsString('width="170"', $html, $context);
                        $this->assertStringNotContainsString('Transport &amp; logistique', $html, $context);
                    } else {
                        $this->assertStringContainsString('Transport &amp; logistique', $html, $context);
                        $this->assertStringContainsString('width="132"', $html, $context);
                    }

                    // ── Hero signature ───────────────────────────────────────
                    if ($header === 'logo_center') {
                        $this->assertStringContainsString('Optimisez vos flux de transport', $html, $context);
                        $this->assertStringNotContainsString('Votre fret, notre priorité', $html, $context);
                    } else {
                        $this->assertStringContainsString('Votre fret, notre priorité', $html, $context);
                        $this->assertStringContainsString('class="hero-title"', $html, $context);
                    }

                    // ── Middle signature ─────────────────────────────────────
                    if ($middle === 'departures') {
                        $this->assertStringContainsString('Origine', $html, $context);
                        $this->assertStringContainsString('Fréquence', $html, $context);
                        $this->assertStringContainsString('Goussainville (France)', $html, $context);
                    } elseif ($middle === 'kpi') {
                        $this->assertStringContainsString('48 h', $html, $context);
                        $this->assertStringContainsString('Enlèvement', $html, $context);
                    } elseif ($middle === 'benefits') {
                        $this->assertStringContainsString('Réactivité', $html, $context);
                        $this->assertStringContainsString('border-top:4px solid', $html, $context);
                    } elseif ($middle === 'process') {
                        foreach (SectionCatalog::middleDefaults()['process']['process_steps'] as $step) {
                            $this->assertStringContainsString($step, $html, $context);
                        }
                        $this->assertStringContainsString('Groupage routier depuis Goussainville', $html, $context);
                    } elseif ($middle === 'case_study') {
                        $this->assertStringContainsString('Contrainte', $html, $context);
                        $this->assertStringContainsString('Réponse TCL', $html, $context);
                        $this->assertStringContainsString('Résultat', $html, $context);
                    } elseif ($middle === 'checklist') {
                        $this->assertStringContainsString('Checklist départ', $html, $context);
                        $this->assertStringContainsString('Documents validés', $html, $context);
                    } elseif ($middle === 'solutions') {
                        $this->assertStringContainsString('Route', $html, $context);
                        $this->assertStringContainsString('Gestion des urgences', $html, $context);
                    } else {
                        $this->assertStringContainsString('Offre dédiée', $html, $context);
                        $this->assertStringContainsString('Étude personnalisée', $html, $context);
                    }

                    // ── Footer signature ─────────────────────────────────────
                    if ($footer === 'detailed') {
                        $this->assertStringContainsString("LET'S GROW TOGETHER !", $html, $context);
                        $this->assertStringContainsString(SectionCatalog::LINKEDIN_ICON_URL, $html, $context);
                    } else {
                        $this->assertStringNotContainsString('GROW TOGETHER', $html, $context);
                        $this->assertStringContainsString('TCL — 353 Bd Mohammed V', $html, $context);
                    }

                    // ── Exact CDN logo URL, verbatim ─────────────────────────
                    $this->assertStringContainsString(SectionCatalog::LOGO_WHITE_URL, $html, $context);

                    // ── No unsubscribe content, anywhere ──────────────────────
                    $lower = mb_strtolower($html);
                    $this->assertStringNotContainsString('unsubscribe', $lower, $context);
                    $this->assertStringNotContainsString('désabonner', $lower, $context);
                    $this->assertStringNotContainsString('{{unsubscribe_url}}', $lower, $context);

                    // ── Literal merge tags survive Blade compilation ──────────
                    $this->assertStringContainsString('{{contact.first_name}}', $html, $context);
                    $this->assertStringContainsString('{{company.name}}', $html, $context);

                    // ── CTA href starts with the configured base URL ─────────
                    $expectedUrl = SectionCatalog::ctaIntents()['quote']['url'];
                    $this->assertStringContainsString('href="' . $expectedUrl . '"', $html, $context);
                    $this->assertStringStartsWith(config('prospecting.site.base_url'), $expectedUrl, $context);

                    // ── Preheader contains preview text ───────────────────────
                    $this->assertStringContainsString('Un aperçu de test pour la campagne.', $html, $context);
                }
            }
        }
    }

    public function test_new_middle_copy_is_html_escaped(): void
    {
        $html = $this->composer()->compose($this->state('logo_center', 'compact', 'offer', [
            'offer' => ['title' => '<script>alert(1)</script>', 'description' => 'Texte', 'highlight' => 'Important'],
        ]));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_solution_card_renders_link_when_url_present(): void
    {
        $html = $this->composer()->compose($this->state('logo_center', 'compact', 'solutions', [
            'solutions' => [
                ['title' => 'Route', 'text' => 'Départs réguliers', 'url' => 'https://tcltransport.com/transport-routier/#cotationroutier', 'link_label' => 'Cotation routier'],
                ['title' => 'Aérien', 'text' => 'Gestion des urgences'],
            ],
        ]));

        $this->assertStringContainsString('href="https://tcltransport.com/transport-routier/#cotationroutier"', $html);
        $this->assertStringContainsString('Cotation routier →', $html);
    }

    public function test_solution_card_has_no_link_without_url(): void
    {
        $html = $this->composer()->compose($this->state('logo_center', 'compact', 'solutions'));

        $this->assertStringNotContainsString('Demander une cotation →', $html);
    }

    // ── Note wording sanity (no accidental "Se désabonner" wording variant) ────

    public function test_footer_detailed_has_no_stray_unsubscribe_anchor(): void
    {
        $html = $this->composer()->compose($this->state('logo_center', 'detailed', 'departures'));

        $this->assertStringNotContainsString('<a href="{{unsubscribe_url}}"', $html);
    }

    public function test_footer_compact_keeps_compliance_sentence_without_the_anchor(): void
    {
        $html = $this->composer()->compose($this->state('logo_tagline', 'compact', 'benefits'));

        $this->assertStringContainsString('prise de contact professionnelle', $html);
        $this->assertStringNotContainsString('<a href="{{unsubscribe_url}}"', $html);
    }

    // ── XSS: script payload in slots stays encoded ──────────────────────────────

    public function test_script_payload_in_hero_title_is_html_escaped(): void
    {
        $html = $this->composer()->compose($this->state(
            'logo_center',
            'detailed',
            'departures',
            slotOverrides: ['hero_title' => '<script>alert(1)</script>']
        ));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_script_payload_in_bullet_is_html_escaped(): void
    {
        $html = $this->composer()->compose($this->state(
            'logo_tagline',
            'compact',
            'kpi',
            slotOverrides: ['bullets' => ['<script>alert(2)</script>', 'Bullet normal', 'Bullet trois']]
        ));

        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $html);
    }

    public function test_script_payload_in_process_highlight_is_html_escaped(): void
    {
        $html = $this->composer()->compose($this->state(
            'logo_center',
            'detailed',
            'process',
            slotOverrides: ['process_highlight' => '<script>alert(3)</script>']
        ));

        $this->assertStringNotContainsString('<script>alert(3)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(3)&lt;/script&gt;', $html);
    }

    // ── CTA color follows hero variant ───────────────────────────────────────

    public function test_cta_is_blue_for_white_hero(): void
    {
        $html = $this->composer()->compose($this->state('logo_center', 'detailed', 'kpi'));

        $this->assertStringContainsString('border-radius:4px;background-color:#0548a5;', $html);
    }

    public function test_cta_is_yellow_for_navy_hero(): void
    {
        $html = $this->composer()->compose($this->state('logo_tagline', 'compact', 'kpi'));

        // The CTA table cell specifically uses border-radius + the yellow fill.
        $this->assertStringContainsString('border-radius:4px;background-color:#e3c400;', $html);
    }

    // ── Invalid state throws ─────────────────────────────────────────────────

    public function test_compose_throws_validation_exception_for_unknown_variant(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->composer()->compose($this->state('logo_center', 'detailed', 'departures', overrides: [
            'header_variant' => 'not_a_real_variant',
        ]));
    }

    public function test_english_composition_translates_fixed_chrome(): void
    {
        $html = $this->composer()->compose($this->state('logo_tagline', 'compact', 'case_study'), 'en');

        $this->assertStringContainsString('<html lang="en"', $html);
        $this->assertStringContainsString('Transport &amp; logistics', $html);
        $this->assertStringContainsString('Your freight, our priority', $html);
        $this->assertStringContainsString('Why TCL Transport?', $html);
        $this->assertStringContainsString('Challenge', $html);
        $this->assertStringContainsString('Kind regards,', $html);
        $this->assertStringNotContainsString('Pourquoi TCL Transport ?', $html);
    }

    public function test_compose_rejects_unknown_locale(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->composer()->compose($this->state('logo_tagline', 'compact', 'benefits'), 'de');
    }
}
