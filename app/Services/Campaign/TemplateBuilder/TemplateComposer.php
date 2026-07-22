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
    public function compose(array $state): string
    {
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
            'slots'           => $validated['slots'],
            'ctaLabel'        => $validated['cta']['label'],
            'ctaUrl'          => $ctaIntents[$intentKey]['url'],
            'previewText'     => $validated['preview_text'],
            'logoWhiteUrl'    => SectionCatalog::LOGO_WHITE_URL,
            'linkedinIconUrl' => SectionCatalog::LINKEDIN_ICON_URL,
        ])->render();
    }
}
