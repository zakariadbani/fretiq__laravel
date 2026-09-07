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
    // ── Public email image URLs ───────────────────────────────────────────────

    public const LOGO_WHITE_URL = 'https://fretiq.digaevo.com/assets/media/email/tcl-logo-white.png';

    public const LINKEDIN_ICON_URL = 'https://fretiq.digaevo.com/assets/media/email/linkedin.png';

    public const LEGACY_LOGO_WHITE_URL = 'https://stratus.campaign-image.com/images/17383084625551_t%C3%A9l%C3%A9chargement-removebg-p_zc_v1_1_962996000020175003.png';

    public const LEGACY_LINKEDIN_ICON_URL = 'https://stratus.campaign-image.com/images/17383084636946_linkedin@2x_zc_v1_6_962996000020175003.png';

    public const LEGACY_IMAGE_URL_MAP = [
        self::LEGACY_LOGO_WHITE_URL => self::LOGO_WHITE_URL,
        self::LEGACY_LINKEDIN_ICON_URL => self::LINKEDIN_ICON_URL,
    ];

    // ── Variant registries ─────────────────────────────────────────────────────

    public const HEADERS = ['logo_center', 'logo_tagline'];

    public const HEROES = ['white', 'navy'];

    public const MIDDLES = ['process', 'departures', 'kpi', 'benefits', 'case_study', 'checklist', 'solutions', 'offer'];

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

    public const PROCESS_STEP_COUNT = 3;

    public const PROCESS_STEP_MAX = 60;

    public const PROCESS_HIGHLIGHT_MAX = 200;

    public const DEPARTURES_MIN = 2;

    public const DEPARTURES_MAX = 5;

    public const DEPARTURE_FIELD_MAX = 120;

    public const MIDDLE_TITLE_MAX = 80;
    public const CASE_STUDY_TEXT_MAX = 240;
    public const CHECKLIST_MIN = 3;
    public const CHECKLIST_MAX = 5;
    public const CHECKLIST_ITEM_MAX = 160;
    public const SOLUTIONS_MIN = 2;
    public const SOLUTIONS_MAX = 4;
    public const SOLUTION_TITLE_MAX = 40;
    public const SOLUTION_TEXT_MAX = 160;
    public const SOLUTION_URL_MAX = 255;
    public const SOLUTION_LINK_LABEL_MAX = 40;
    public const OFFER_DESCRIPTION_MAX = 240;
    public const OFFER_HIGHLIGHT_MAX = 100;

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
     * Resolve approved CTA intent paths against the fixed TCL Transport root.
     *
     * @return array<string, array{label: string, url: string}>
     */
    public static function ctaIntents(): array
    {
        $base = rtrim((string) config('prospecting.site.base_url'), '/') . '/';

        $intents = [];

        foreach ((array) config('prospecting.site.cta_intents', []) as $key => $intent) {
            $path = ltrim((string) ($intent['path'] ?? ''), '/');

            $intents[$key] = [
                'label' => (string) ($intent['label'] ?? $key),
                'url'   => $base . $path,
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
            'include_first_name' => true,
            'preview_text'   => 'Découvrez les solutions logistiques sur mesure de TCL Transport.',
            'cta' => [
                'intent' => 'services',
                'label'  => 'Découvrir nos services',
            ],
            'slots' => [
                'hero_title' => 'TCL Transport : Expertise logistique 3PL pour vos besoins en transport',
                'intro' => [
                    "TCL Transport apporte des solutions logistiques sur mesure, de la collecte des marchandises jusqu'au dégroupement dans ses propres magasins sous douane (MEAD).",
                ],
                'bullets' => [
                    'proximité avec chaque client',
                    'solutions logistiques adaptées à chaque besoin',
                    'transport routier, maritime, aérien et entreposage',
                ],
                'closing_line' => self::DEFAULT_CLOSING_LINE,
                ...self::middleDefaults()['process'],
            ],
        ];
    }

    /**
     * Valeurs par défaut réelles TCL du bloc central — source :
     * structure/business-rules/tcl-facts-pool.md (2026-09-06). Seedées par le
     * builder quand la variante est choisie et que le slot est absent.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function middleDefaults(): array
    {
        return [
            'process' => [
                'process_steps' => [
                    'Enlèvement EXW chez votre fournisseur',
                    'Transport et dédouanement en MEAD',
                    'Livraison au Maroc',
                ],
                'process_highlight' => 'Groupage routier depuis Goussainville, Barcelone et Porto ; dépotage dans nos magasins sous douane de Casablanca ou Tanger.',
            ],
            'departures' => [
                'departures' => [
                    ['origin' => 'Goussainville (France)', 'frequency' => '4 départs par semaine, transit 3 jours'],
                    ['origin' => 'Barcelone (Espagne)', 'frequency' => '2 à 3 départs par semaine, transit 2 jours'],
                    ['origin' => 'Porto (Portugal)', 'frequency' => 'Chaque vendredi, transit 3 à 4 jours'],
                ],
            ],
            'kpi' => [
                'kpis' => [
                    ['value' => '+ de 20 ans', 'label' => "d'expérience en transport international"],
                    ['value' => '180', 'label' => 'pays desservis'],
                    ['value' => '14 000 m²', 'label' => "d'entrepôts à Casablanca"],
                ],
            ],
            'benefits' => [
                'benefits' => [
                    ['title' => 'Agrément IATA, agent agréé CASS', 'text' => 'Fret aérien vers plus de 100 aéroports, avec RAM, Air France, Qatar Airways, Emirates et Turkish Airlines.'],
                    ['title' => '3 hubs routiers en Europe', 'text' => "Goussainville 4 départs par semaine, Barcelone 2 à 3, Porto 1 : transit de 2 à 4 jours, du colis de 1 kg au camion complet."],
                    ['title' => 'Magasins sous douane (MEAD)', 'text' => 'Dédouanement et dépotage à Casablanca ou Tanger ; 2 entrepôts à Casablanca, 14 000 m², WMS en temps réel.'],
                ],
            ],
            'case_study' => [
                'case_study' => [
                    'title' => 'Un flux Europe → Maroc, de bout en bout',
                    'challenge' => "Un flux régulier depuis l'Europe, avec un délai à tenir et des formalités douanières à sécuriser.",
                    'solution' => "Enlèvement EXW chez le fournisseur, groupage routier hebdomadaire (Goussainville, Barcelone et Porto), dédouanement dans nos magasins sous douane (MEAD) de Casablanca ou Tanger.",
                    'result' => "Transit de 2 à 4 jours selon l'origine ; un interlocuteur dédié du devis à la livraison.",
                ],
            ],
            'checklist' => [
                'checklist_title' => 'Votre flux Europe → Maroc en 4 points',
                'checklist_items' => [
                    'Enlèvement EXW chez votre fournisseur en Europe',
                    'Groupage routier : 4 départs par semaine depuis Goussainville, 2 à 3 depuis Barcelone, 1 depuis Porto',
                    'Dédouanement dans nos magasins sous douane (MEAD) de Casablanca ou Tanger',
                    'Stockage possible : 2 entrepôts à Casablanca, 14 000 m², WMS en temps réel',
                ],
            ],
            'solutions' => [
                'solutions' => [
                    ['title' => 'Routier — groupage hebdomadaire', 'text' => '4 départs/sem. depuis Goussainville (transit 3 j), Barcelone 2–3 (2 j), Porto chaque vendredi (3–4 j). Enlèvement EXW chez votre fournisseur.', 'url' => 'https://tcltransport.com/transport-routier/#cotationroutier', 'link_label' => 'Cotation routier'],
                    ['title' => 'Aérien — agent IATA & CASS', 'text' => 'Plus de 100 aéroports, avec RAM, Air France, Qatar Airways, Emirates, Turkish Airlines. Express, door-to-door, marchandises dangereuses.', 'url' => 'https://tcltransport.com/transport-aerien/#cotationaerien', 'link_label' => 'Cotation aérien'],
                    ['title' => 'Maritime — FCL / LCL', 'text' => 'Plus de 180 pays desservis. Partenaires CMA CGM, MSC, MAERSK et ARKAS. Conteneurs complets ou partagés, affrètement, projets industriels.', 'url' => 'https://tcltransport.com/transport-maritime/#cotationmaritime', 'link_label' => 'Cotation maritime'],
                    ['title' => 'Entreposage sous douane', 'text' => 'MEAD à Casablanca et Tanger ; 2 entrepôts à Casablanca (14 000 m²), WMS en temps réel.', 'url' => 'https://tcltransport.com/entreposage/#contationentreposage', 'link_label' => 'Cotation entreposage'],
                ],
            ],
            'offer' => [
                'offer' => [
                    'title' => 'Une cotation sous 24 h',
                    'description' => "Indiquez-nous votre ville de départ, votre ville d'arrivée et le type de marchandise : nous vous adressons une cotation sous 24 h.",
                    'highlight' => 'Sans engagement',
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
            'process_steps' => [
                'type'       => 'string[]',
                'applies_to' => 'process',
                'count'      => self::PROCESS_STEP_COUNT,
                'item_max'   => self::PROCESS_STEP_MAX,
            ],
            'process_highlight' => [
                'type'       => 'string',
                'applies_to' => 'process',
                'max'        => self::PROCESS_HIGHLIGHT_MAX,
            ],
            'case_study' => [
                'type' => 'object', 'applies_to' => 'case_study',
                'fields' => [
                    'title' => ['type' => 'string', 'max' => self::MIDDLE_TITLE_MAX],
                    'challenge' => ['type' => 'string', 'max' => self::CASE_STUDY_TEXT_MAX],
                    'solution' => ['type' => 'string', 'max' => self::CASE_STUDY_TEXT_MAX],
                    'result' => ['type' => 'string', 'max' => self::CASE_STUDY_TEXT_MAX],
                ],
            ],
            'checklist_title' => ['type' => 'string', 'applies_to' => 'checklist', 'max' => self::MIDDLE_TITLE_MAX],
            'checklist_items' => ['type' => 'string[]', 'applies_to' => 'checklist', 'min' => self::CHECKLIST_MIN, 'max' => self::CHECKLIST_MAX, 'item_max' => self::CHECKLIST_ITEM_MAX],
            'solutions' => [
                'type' => 'object[]', 'applies_to' => 'solutions', 'min' => self::SOLUTIONS_MIN, 'max' => self::SOLUTIONS_MAX,
                'fields' => [
                    'title' => ['type' => 'string', 'max' => self::SOLUTION_TITLE_MAX],
                    'text' => ['type' => 'string', 'max' => self::SOLUTION_TEXT_MAX],
                    'url' => ['type' => 'string', 'required' => false, 'max' => self::SOLUTION_URL_MAX],
                    'link_label' => ['type' => 'string', 'required' => false, 'max' => self::SOLUTION_LINK_LABEL_MAX],
                ],
            ],
            'offer' => [
                'type' => 'object', 'applies_to' => 'offer',
                'fields' => [
                    'title' => ['type' => 'string', 'max' => self::MIDDLE_TITLE_MAX],
                    'description' => ['type' => 'string', 'max' => self::OFFER_DESCRIPTION_MAX],
                    'highlight' => ['type' => 'string', 'max' => self::OFFER_HIGHLIGHT_MAX],
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
        $processStepCount = self::PROCESS_STEP_COUNT;
        $processStepMax   = self::PROCESS_STEP_MAX;
        $processHighlightMax = self::PROCESS_HIGHLIGHT_MAX;
        $middleTitleMax = self::MIDDLE_TITLE_MAX;
        $caseStudyTextMax = self::CASE_STUDY_TEXT_MAX;
        $checklistMin = self::CHECKLIST_MIN;
        $checklistMax = self::CHECKLIST_MAX;
        $checklistItemMax = self::CHECKLIST_ITEM_MAX;
        $solutionsMin = self::SOLUTIONS_MIN;
        $solutionsMax = self::SOLUTIONS_MAX;
        $solutionTitleMax = self::SOLUTION_TITLE_MAX;
        $solutionTextMax = self::SOLUTION_TEXT_MAX;
        $offerDescriptionMax = self::OFFER_DESCRIPTION_MAX;
        $offerHighlightMax = self::OFFER_HIGHLIGHT_MAX;
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
- process_steps (only when middle_variant=process): array of exactly {$processStepCount} strings, each max {$processStepMax} characters.
- process_highlight (only when middle_variant=process): string, max {$processHighlightMax} characters.
- case_study (only when middle_variant=case_study): {"title": string max {$middleTitleMax} chars, "challenge": string max {$caseStudyTextMax} chars, "solution": string max {$caseStudyTextMax} chars, "result": string max {$caseStudyTextMax} chars}.
- checklist_title + checklist_items (only when middle_variant=checklist): title max {$middleTitleMax} chars and array of {$checklistMin} to {$checklistMax} strings, each max {$checklistItemMax} chars.
- solutions (only when middle_variant=solutions): array of {$solutionsMin} to {$solutionsMax} items, each {"title": string max {$solutionTitleMax} chars, "text": string max {$solutionTextMax} chars}.
- offer (only when middle_variant=offer): {"title": string max {$middleTitleMax} chars, "description": string max {$offerDescriptionMax} chars, "highlight": string max {$offerHighlightMax} chars}.
- cta_label: max {$ctaLabelMax} characters.
TXT;
    }
}
