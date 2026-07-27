<?php

namespace App\Services\Campaign\TemplateBuilder;

use Illuminate\Validation\ValidationException;

/**
 * TemplateComposer — renders builder_state into the final, send-ready HTML.
 *
 * Always validates first (BuilderStateValidator is the single validation
 * authority — see its docblock), then resolves the header/hero/middle/footer
 * Blade partials for the validated variant ids and renders
 * resources/views/emails/builder/layout.blade.php.
 *
 * The composed HTML is the ONLY thing persisted to campaign_templates.html_content
 * for builder-authored templates — builder_state is metadata for re-opening the
 * builder, never re-parsed out of the HTML (table-soup HTML is not reliably
 * re-parseable; see plan "Key design decisions" #1).
 */
class TemplateComposer
{
    public function __construct(
        private readonly BuilderStateValidator $validator = new BuilderStateValidator(),
    ) {}

    /**
     * @param  array<string, mixed>  $state
     *
     * @throws ValidationException
     */
    public function compose(array $state, string $locale = 'fr'): string
    {
        if (! in_array($locale, ['fr', 'en'], true)) {
            throw ValidationException::withMessages(['locale' => ["Langue de composition inconnue : [{$locale}]."]]);
        }

        $validated = $this->validator->validate($state);

        $ctaIntents = SectionCatalog::ctaIntents();
        $intentKey  = $validated['cta']['intent'];

        if (! isset($ctaIntents[$intentKey])) {
            throw ValidationException::withMessages([
                'cta.intent' => ["Intention CTA inconnue : [{$intentKey}]."],
            ]);
        }

        return view('emails.builder.layout', [
            'headerVariant'   => $validated['header_variant'],
            'heroVariant'     => $validated['hero_variant'],
            'middleVariant'   => $validated['middle_variant'],
            'footerVariant'   => $validated['footer_variant'],
            'includeFirstName' => $validated['include_first_name'],
            'slots'           => $validated['slots'],
            'ctaLabel'        => $validated['cta']['label'],
            'ctaUrl'          => $ctaIntents[$intentKey]['url'],
            'previewText'     => $validated['preview_text'],
            'logoWhiteUrl'    => SectionCatalog::LOGO_WHITE_URL,
            'linkedinIconUrl' => SectionCatalog::LINKEDIN_ICON_URL,
            'locale'          => $locale,
            'copy'            => $this->copy($locale),
        ])->render();
    }

    /** @return array<string, string> */
    private function copy(string $locale): array
    {
        return $locale === 'en'
            ? [
                'tagline' => 'Transport & logistics', 'eyebrow' => 'Your freight, our priority', 'greeting' => 'Hello', 'why' => 'Why TCL Transport?',
                'closing_fallback' => 'Please contact us if you have any questions or need further information.', 'regards' => 'Kind regards,', 'team' => 'The TCL Transport team',
                'compact_address' => 'TCL — 353 Mohammed V Boulevard, 7th floor – Espace Idriss, 20300 Casablanca – Morocco', 'compact_compliance' => 'You are receiving this message as part of a professional communication.',
                'detailed_address' => '353 Mohammed V Boulevard, 7th floor – Espace Idriss, 20300 Casablanca – Morocco', 'detailed_compliance' => 'You are receiving this email as part of a professional communication.',
                'origin' => 'Origin', 'frequency' => 'Frequency', 'challenge' => 'Challenge', 'solution' => 'TCL response', 'result' => 'Result',
            ]
            : [
                'tagline' => 'Transport & logistique', 'eyebrow' => 'Votre fret, notre priorité', 'greeting' => 'Bonjour', 'why' => 'Pourquoi TCL Transport ?',
                'closing_fallback' => SectionCatalog::DEFAULT_CLOSING_LINE, 'regards' => 'Cordialement,', 'team' => "L'équipe TCL Transport",
                'compact_address' => 'TCL — 353 Bd Mohammed V, 7ème étage – Espace Idriss, 20300 Casablanca – Maroc', 'compact_compliance' => "Vous recevez ce message dans le cadre d'une prise de contact professionnelle.",
                'detailed_address' => '353 Bd Mohammed V, 7ème étage – Espace Idriss, 20300 Casablanca – Maroc', 'detailed_compliance' => "Vous recevez cet e-mail dans le cadre d'une communication professionnelle.",
                'origin' => 'Origine', 'frequency' => 'Fréquence', 'challenge' => 'Contrainte', 'solution' => 'Réponse TCL', 'result' => 'Résultat',
            ];
    }
}
