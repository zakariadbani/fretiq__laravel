<?php

namespace App\Services\Campaign\TemplateBuilder;

/**
 * SectionCatalog — the static registry of everything the campaign template
 * "builder" composes from: which header/hero/middle/footer variants exist,
 * the CDN image URLs (verbatim from
 * `docs/mail templates/standard/README.md` — never invent image URLs), the
 * CTA intents (resolved from config('prospecting.site')), the slot bounds
 * BuilderStateValidator enforces, and the merge-tag whitelist.
 *
 * Pure registry — no state, no DB, no HTTP. Every method is static.
 */
class SectionCatalog
{
    // ── Image URLs (verbatim — see docs/mail templates/standard/README.md) ───

    public const LOGO_WHITE_URL = 'https://stratus.campaign-image.com/images/17383084625551_t%C3%A9l%C3%A9chargement-removebg-p_zc_v1_1_962996000020175003.png';

    public const LINKEDIN_ICON_URL = 'https://stratus.campaign-image.com/images/17383084636946_linkedin@2x_zc_v1_6_962996000020175003.png';

    // ── Variant registries ─────────────────────────────────────────────────────

    public const HEADERS = ['logo_center', 'logo_tagline'];

    public const HEROES = ['white', 'navy'];

    public const MIDDLES = ['departures', 'kpi', 'benefits'];

    public const FOOTERS = ['detailed', 'compact'];

    // ── Slot bounds (single source of truth — BuilderStateValidator reads these) ─

    public const HERO_TITLE_MAX = 120;

    public const INTRO_MIN = 1;

    public const INTRO_MAX = 3;

    public const INTRO_ITEM_MAX = 500;

    public const BULLETS_MIN = 2;

    public const BULLETS_MAX = 4;

    public const BULLET_ITEM_MAX = 200;

    public const KPI_COUNT = 3;

    public const KPI_VALUE_MAX = 12;

    public const KPI_LABEL_MAX = 40;

    public const BENEFIT_COUNT = 3;

    public const BENEFIT_TITLE_MAX = 40;

    public const BENEFIT_TEXT_MAX = 160;

    public const DEPARTURES_MIN = 2;

    public const DEPARTURES_MAX = 5;

    public const DEPARTURE_FIELD_MAX = 120;

    public const CTA_LABEL_MAX = 60;

    public const PREVIEW_TEXT_MAX = 255;

    public const CLOSING_LINE_MAX = 300;

    /**
     * Default closing-ask sentence — used both as the defaultState() seed and
     * as the fallback rendered by closing-signature.blade.php when a stored
     * builder_state predates this (optional) slot.
     */
    public const DEFAULT_CLOSING_LINE = "N'hésitez pas à revenir vers nous pour toute question ou précision.";

    /**
     * Merge tags the builder allows inside slot copy. {{unsubscribe_url}} is
     * deliberately absent — Zoho Campaigns owns unsubscribe for builder-authored
     * templates (see plan "Key design decisions").
     *
     * 'company.sector' is deliberately absent: ZohoCampaignsDriver::MERGE_TAG_MAP
     * has no live-verified Zoho equivalent for it, so it would pass through
     * unchanged and reach the recipient as the literal string "{{company.sector}}"
     * (the same class of failure fixed in commit bb07997 for {{company.name}}).
     * Re-add it here ONLY together with a verified MERGE_TAG_MAP entry — see
     * CLAUDE.md §1 (Zoho empirical-verification rule).
     */
    public const ALLOWED_MERGE_TAGS = [
        'contact.name',
        'contact.first_name',
        'contact.email',
        'company.name',
    ];

    /**
     * Explicitly forbidden even though it would otherwise match the generic
     * {{...}} pattern — called out separately so validator error messages can
     * name it specifically.
     */
    public const FORBIDDEN_MERGE_TAG = 'unsubscribe_url';

    /**
     * The hero variant is derived from — and must match — the header variant.
     * UI only ever exposes 2 choices (the header), the hero follows.
     */
    public static function heroForHeader(string $headerVariant): string
    {
        return match ($headerVariant) {
            'logo_center'  => 'white',
            'logo_tagline' => 'navy',
            default        => throw new \InvalidArgumentException("Unknown header variant [{$headerVariant}]."),
        };
    }

    /**
     * Resolve the configured CTA intents to the fixed TCL Transport site root.
     *
     * @return array<string, array{label: string, url: string}>
     */
    public static function ctaIntents(): array
    {
        $base = rtrim((string) config('prospecting.site.base_url'), '/') . '/';

        $intents = [];

        foreach ((array) config('prospecting.site.cta_intents', []) as $key => $intent) {
            $intents[$key] = [
                'label' => (string) ($intent['label'] ?? $key),
                'url'   => $base,
            ];
        }

        return $intents;
    }

    /**
     * A fully valid builder_state — used to seed a fresh create-page session
     * and as the base fixture for variant preview cards.
     *
     * @return array<string, mixed>
     */
    public static function defaultState(): array
    {
        $headerVariant = self::HEADERS[0];

        return [
            'header_variant' => $headerVariant,
            'hero_variant'   => self::heroForHeader($headerVariant),
            'middle_variant' => self::MIDDLES[0],
            'footer_variant' => self::FOOTERS[0],
            'preview_text'   => 'Découvrez comment TCL Transport optimise vos flux.',
            'cta' => [
                'intent' => 'quote',
                'label'  => 'Demander une cotation',
            ],
            'slots' => [
                'hero_title' => 'Optimisez vos flux de transport',
                'intro' => [
                    "De nombreuses entreprises paient plus cher qu'elles ne le devraient sur leurs flux de transport.",
                    'TCL Transport accompagne déjà des entreprises comme {{company.name}} en optimisant le ratio volume/coût.',
                ],
                'bullets' => [
                    'délais maîtrisés et fréquence régulière',
                    "visibilité complète sur chaque étape de l'expédition",
                    'optimisation du ratio volume/coût',
                ],
                'closing_line' => self::DEFAULT_CLOSING_LINE,
                'departures' => [
                    ['origin' => 'Goussainville (France)', 'frequency' => 'Départs par semaine'],
                    ['origin' => 'Barcelone (Espagne)', 'frequency' => 'Départs par semaine'],
                    ['origin' => 'Porto (Portugal)', 'frequency' => 'Départ par semaine'],
                ],
            ],
        ];
    }

    /**
     * Declarative shape of the `slots` object, keyed by field name — describes
     * cardinality + per-item bounds for the frontend form builder (Phase 3) and
     * for the AI prompt. Not consumed by BuilderStateValidator (which enforces
     * the bounds directly via Laravel validation rules against the constants
     * above) — this is documentation-as-data, kept in sync by sharing the same
     * constants.
     *
     * @return array<string, mixed>
     */
    public static function slotSchema(): array
    {
        return [
            'hero_title'   => ['type' => 'string', 'max' => self::HERO_TITLE_MAX],
            'intro'        => ['type' => 'string[]', 'min' => self::INTRO_MIN, 'max' => self::INTRO_MAX, 'item_max' => self::INTRO_ITEM_MAX],
            'bullets'      => ['type' => 'string[]', 'min' => self::BULLETS_MIN, 'max' => self::BULLETS_MAX, 'item_max' => self::BULLET_ITEM_MAX],
            // Optional — falls back to DEFAULT_CLOSING_LINE (closing-signature.blade.php) when absent/blank.
            'closing_line' => ['type' => 'string', 'required' => false, 'max' => self::CLOSING_LINE_MAX],
            'departures' => [
                'type'      => 'object[]',
                'applies_to' => 'departures',
                'min'       => self::DEPARTURES_MIN,
                'max'       => self::DEPARTURES_MAX,
                'fields'    => [
                    'origin'    => ['type' => 'string', 'max' => self::DEPARTURE_FIELD_MAX],
                    'frequency' => ['type' => 'string', 'max' => self::DEPARTURE_FIELD_MAX],
                ],
            ],
            'kpis' => [
                'type'      => 'object[]',
                'applies_to' => 'kpi',
                'count'     => self::KPI_COUNT,
                'fields'    => [
                    'value' => ['type' => 'string', 'max' => self::KPI_VALUE_MAX],
                    'label' => ['type' => 'string', 'max' => self::KPI_LABEL_MAX],
                ],
            ],
            'benefits' => [
                'type'      => 'object[]',
                'applies_to' => 'benefits',
                'count'     => self::BENEFIT_COUNT,
                'fields'    => [
                    'title' => ['type' => 'string', 'max' => self::BENEFIT_TITLE_MAX],
                    'text'  => ['type' => 'string', 'max' => self::BENEFIT_TEXT_MAX],
                ],
            ],
        ];
    }

    /**
     * Human-readable catalog description injected into the Gemini prompt
     * (GeminiTemplateSuggestionService) so the model knows the exact variant
     * ids, bounds, and CTA intents it may choose from.
     */
    public static function describeForPrompt(): string
    {
        $middles = implode(', ', self::MIDDLES);
        $intents = implode(', ', array_keys(self::ctaIntents()));
        $tags    = implode(', ', array_map(fn (string $t) => '{{' . $t . '}}', self::ALLOWED_MERGE_TAGS));

        $heroTitleMax     = self::HERO_TITLE_MAX;
        $introMin         = self::INTRO_MIN;
        $introMax         = self::INTRO_MAX;
        $introItemMax     = self::INTRO_ITEM_MAX;
        $bulletsMin       = self::BULLETS_MIN;
        $bulletsMax       = self::BULLETS_MAX;
        $bulletItemMax    = self::BULLET_ITEM_MAX;
        $departuresMin    = self::DEPARTURES_MIN;
        $departuresMax    = self::DEPARTURES_MAX;
        $kpiCount         = self::KPI_COUNT;
        $kpiValueMax      = self::KPI_VALUE_MAX;
        $kpiLabelMax      = self::KPI_LABEL_MAX;
        $benefitCount     = self::BENEFIT_COUNT;
        $benefitTitleMax  = self::BENEFIT_TITLE_MAX;
        $benefitTextMax   = self::BENEFIT_TEXT_MAX;
        $ctaLabelMax      = self::CTA_LABEL_MAX;

        return <<<TXT
Middle block variants available (pick exactly one): {$middles}.
CTA intents available (pick exactly one): {$intents}.
Allowed merge tags (use only these, never invent others): {$tags}.

Slot bounds:
- hero_title: plain text, max {$heroTitleMax} characters.
- intro: array of {$introMin} to {$introMax} paragraphs, each max {$introItemMax} characters.
- bullets: array of {$bulletsMin} to {$bulletsMax} short items, each max {$bulletItemMax} characters.
- departures (only when middle_variant=departures): array of {$departuresMin} to {$departuresMax} rows, each {"origin": string, "frequency": string}.
- kpis (only when middle_variant=kpi): array of exactly {$kpiCount} items, each {"value": string max {$kpiValueMax} chars, "label": string max {$kpiLabelMax} chars}.
- benefits (only when middle_variant=benefits): array of exactly {$benefitCount} items, each {"title": string max {$benefitTitleMax} chars, "text": string max {$benefitTextMax} chars}.
- cta_label: max {$ctaLabelMax} characters.
TXT;
    }
}
