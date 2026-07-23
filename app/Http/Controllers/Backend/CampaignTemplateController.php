<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\CampaignTemplatesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\CampaignTemplate;
use App\Services\Campaign\TemplateBuilder\BuilderStateValidator;
use App\Services\Campaign\TemplateBuilder\GeminiTemplateSuggestionService;
use App\Services\Campaign\TemplateBuilder\SectionCatalog;
use App\Services\Campaign\TemplateBuilder\TemplateComposer;
use App\Services\Gemini\GeminiClient;
use App\Services\Translation\TemplateTranslationService;
use App\Services\Zoho\ZohoCampaignsTemplatesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class CampaignTemplateController extends BackendController
{
    /**
     * Cache key version for buildVariantPreviews(). Bump this whenever
     * SectionCatalog::defaultState()/HEADERS/FOOTERS or the underlying
     * resources/views/emails/builder/** partials change — the input is
     * otherwise invariant, so a stale cache would silently keep showing the
     * old variant card previews.
     */
    private const VARIANT_PREVIEWS_CACHE_VERSION = 3;

    // beforeSave() is overridden below (builder-mode composition). `parent::`
    // can't reach it because Crudable is a TRAIT flattened into this class,
    // not a superclass — traits aren't walked by `parent::`. Alias the
    // trait's original implementation so the override can still delegate to it.
    use Crudable, Datatableable {
        Crudable::beforeSave as protected crudableBeforeSave;
    }

    public function __construct(Request $request, CampaignTemplate $model, CampaignTemplatesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view campaign_templates')->only(['index', 'view']);
        $this->middleware('permission:create campaign_templates')->only(['create', 'store']);
        $this->middleware('permission:edit campaign_templates')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete campaign_templates')->only(['delete']);
        // Builder AI/preview endpoints are reachable from BOTH the create page
        // (create campaign_templates) and the edit page (edit campaign_templates) —
        // translate()'s edit-only gate would incorrectly 403 create-page users.
        $this->middleware('permission:create campaign_templates|edit campaign_templates')
            ->only(['builderSuggest', 'builderPreview']);

        $this->listTitle = "Modèles d'email";
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       CampaignTemplate::class,
            modelName:        'campaign_templates',
            dataTableClass:   CampaignTemplatesDataTable::class,
            permissionEntity: 'campaign_templates',
            prefixName:       'admin',
            titleField:       'name',
        ));

        // Wire ViewConfig — MUST be inside constructor body, never as a class property.
        // The Crudable trait declares $viewConfigClass = null; re-declaring it at class level
        // with a non-null default would be a PHP fatal (conflicting default).
        $this->viewConfigClass = \App\Crud\ViewConfigs\CampaignTemplateViewConfig::class;
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.campaign_templates.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    /**
     * AI-suggest builder copy from a free-text brief. Returns JSON — called via
     * axios from the create/edit builder card's "Générer avec l'IA" button.
     *
     * Permission gated in the constructor (create OR edit campaign_templates).
     */
    public function builderSuggest(Request $request)
    {
        $request->validate([
            'brief' => 'required|string|min:20|max:5000',
        ]);

        $suggestion = app(GeminiTemplateSuggestionService::class)->suggest($request->input('brief'));

        if ($suggestion === null) {
            return response()->json([
                'success' => false,
                'message' => app(GeminiClient::class)->hasApiKey()
                    ? "La suggestion IA n'a pas pu être générée (réponse invalide) — vous pouvez continuer manuellement."
                    : 'Clé API Gemini non configurée — vous pouvez continuer manuellement.',
            ], 422);
        }

        return response()->json([
            'success'    => true,
            'suggestion' => $suggestion,
        ]);
    }

    /**
     * Render builder_state into HTML for the live preview iframe, without
     * persisting anything. Returns JSON — called via axios from the builder card.
     *
     * Permission gated in the constructor (create OR edit campaign_templates).
     */
    public function builderPreview(Request $request)
    {
        $request->validate([
            'builder_state' => 'required|array',
        ]);

        $rawState = $request->input('builder_state');

        // Explicit pre-check on the preview endpoint (mirrors the check on the
        // raw JSON string in beforeSave()) — bails out BEFORE the recursive
        // BuilderStateValidator scan runs on an oversized payload. Laravel has
        // already decoded the JSON request body into an array by this point
        // (Accept: application/json), so this re-encodes to measure the same
        // byte size beforeSave() checks pre-decode on the stored string.
        $encodedSize = strlen((string) json_encode($rawState));
        if ($encodedSize > BuilderStateValidator::MAX_ENCODED_BYTES) {
            return response()->json([
                'success' => false,
                'message' => 'État du générateur invalide.',
                'errors'  => ['builder_state' => ['État du générateur trop volumineux (max ' . BuilderStateValidator::MAX_ENCODED_BYTES . ' octets encodés).']],
            ], 422);
        }

        try {
            $validated = app(BuilderStateValidator::class)->validate($rawState);
            $html      = app(TemplateComposer::class)->compose($validated);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'État du générateur invalide.',
                'errors'  => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'html'    => $html,
        ]);
    }

    /**
     * Crudable hook — called before validation on both store() and update().
     *
     * editor_mode === 'builder': decode + validate the posted builder_state
     * JSON string, compose it into html_content (the send-time source of
     * truth — never re-parsed back out of HTML), and persist the NORMALIZED
     * array back into builder_state (not the raw client payload — avoids the
     * `array` model rule rejecting a JSON string, and avoids the array cast
     * double-encoding an already-JSON string).
     *
     * editor_mode absent → classic (the intended safe default for the
     * pre-existing classic flow). editor_mode present but neither 'builder'
     * nor 'classic' → REJECTED with a ValidationException rather than
     * silently coerced to classic — editor_mode is client-controlled (a
     * hidden input toggled by JS), and a builder-mode edit page still submits
     * a stale/tampered html_content textarea alongside it; silently coercing
     * an unrecognized value to classic would silently downgrade the template
     * and drop its builder_state with no error surfaced to the user.
     *
     * editor_mode === 'classic': builder_state is cleared — classic (raw
     * HTML / Zoho-imported) templates carry no builder metadata.
     *
     * @param  int|null  $id
     * @return array
     */
    protected function beforeSave($id = null)
    {
        $attributes = $this->crudableBeforeSave($id);

        $editorMode = $attributes['editor_mode'] ?? null;
        unset($attributes['editor_mode']);

        if ($editorMode !== null && (! is_string($editorMode) || ! in_array($editorMode, ['builder', 'classic'], true))) {
            throw ValidationException::withMessages([
                'editor_mode' => ["Mode d'édition invalide — valeurs autorisées : builder, classic."],
            ]);
        }

        if ($editorMode !== 'builder') {
            $attributes['builder_state'] = null;

            return $attributes;
        }

        $rawState = $attributes['builder_state'] ?? null;

        if (! is_string($rawState) || trim($rawState) === '') {
            throw ValidationException::withMessages([
                'builder_state' => ["L'état du générateur est requis en mode générateur."],
            ]);
        }

        // Reject an oversized payload BEFORE json_decode() — cheaper than
        // decoding first, and mirrors the same MAX_ENCODED_BYTES check
        // builder/preview applies to its already-decoded array (see
        // builderPreview()). See plan item 5.
        if (strlen($rawState) > BuilderStateValidator::MAX_ENCODED_BYTES) {
            throw ValidationException::withMessages([
                'builder_state' => ['État du générateur trop volumineux (max ' . BuilderStateValidator::MAX_ENCODED_BYTES . ' octets encodés).'],
            ]);
        }

        try {
            $decoded = json_decode($rawState, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'builder_state' => ["État du générateur invalide (JSON malformé)."],
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'builder_state' => ["État du générateur invalide (structure inattendue)."],
            ]);
        }

        $validated = app(BuilderStateValidator::class)->validate($decoded);

        $attributes['html_content']  = app(TemplateComposer::class)->compose($validated);
        $attributes['builder_state'] = $validated;
        // Single source of truth (plan item 7): the preview_text COLUMN must
        // never diverge from builder_state.preview_text. The form's visible
        // preview_text field and the JS-mirrored state should already agree
        // (campaign-template-builder.js keeps them in sync client-side), but
        // this makes the server the final authority regardless of client state.
        $attributes['preview_text']  = $validated['preview_text'];

        return $attributes;
    }

    /**
     * View vars merged into create/edit form pages (Crudable::getView()).
     */
    protected function getViewVars()
    {
        return [
            'builderCatalog' => [
                'headers'    => SectionCatalog::HEADERS,
                'heroes'     => SectionCatalog::HEROES,
                'middles'    => SectionCatalog::MIDDLES,
                'footers'    => SectionCatalog::FOOTERS,
                'slotSchema' => SectionCatalog::slotSchema(),
                // Explicit header→hero mapping (keyed, not positional) — the
                // client used to derive hero from
                // HEADERS.indexOf(value)→HEROES[idx], which only matched the
                // server's SectionCatalog::heroForHeader() by coincidence of
                // array order. Sourced from the same method here so the two
                // can never drift.
                'heroForHeader' => array_combine(
                    SectionCatalog::HEADERS,
                    array_map(
                        static fn (string $header) => SectionCatalog::heroForHeader($header),
                        SectionCatalog::HEADERS
                    )
                ),
            ],
            'builderCtaIntents'      => SectionCatalog::ctaIntents(),
            'builderHasAiKey'        => filled(config('services.gemini.api_key')),
            'builderVariantPreviews' => $this->buildVariantPreviews(),
            'builderDefaultState'    => SectionCatalog::defaultState(),
        ];
    }

    /**
     * Sample composed HTML per header/footer variant, for the builder's
     * selection cards (scaled iframe srcdoc previews — Phase 3 frontend).
     * Built off SectionCatalog::defaultState() so every card composes cleanly
     * regardless of which variant it is showcasing.
     *
     * Input is invariant (always the static defaultState()), so the output is
     * cached forever under a version-bumped key — otherwise this ran 4 full
     * compose() cycles (validate + Blade render) on every single create/edit
     * page load, including classic-mode loads where the cards are never shown.
     *
     * @return array{headers: array<string, string>, footers: array<string, string>}
     */
    private function buildVariantPreviews(): array
    {
        return Cache::rememberForever(
            'builder.variant_previews.v' . self::VARIANT_PREVIEWS_CACHE_VERSION,
            function () {
                $composer = app(TemplateComposer::class);
                $base     = SectionCatalog::defaultState();

                $previews = ['headers' => [], 'footers' => []];

                foreach (SectionCatalog::HEADERS as $header) {
                    $state                   = $base;
                    $state['header_variant'] = $header;
                    $state['hero_variant']   = SectionCatalog::heroForHeader($header);

                    $previews['headers'][$header] = $composer->compose($state);
                }

                foreach (SectionCatalog::FOOTERS as $footer) {
                    $state                   = $base;
                    $state['footer_variant'] = $footer;

                    $previews['footers'][$footer] = $composer->compose($state);
                }

                return $previews;
            }
        );
    }

    /**
     * Import stored templates from Zoho Campaigns Email API into campaign_templates.
     *
     * Controller-side permission enforcement (belt + suspenders — Blade @can is
     * presentational only; the gate must be enforced here).
     */
    public function importFromZoho(Request $request)
    {
        abort_unless($request->user()->can('create campaign_templates'), 403);

        try {
            $result = app(ZohoCampaignsTemplatesService::class)->import();

            session()->flash(
                'success',
                "Modèles importés : {$result['imported']} créés, {$result['updated']} mis à jour, {$result['skipped']} ignorés."
            );
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 200);
            session()->flash('error', "Erreur lors de l'import des modèles : {$message}");
        }

        return redirect()->route('admin.campaign_templates.index');
    }

    /**
     * AI-translate the template into all configured target languages.
     *
     * Returns JSON — called via axios from the Traductions tab.
     */
    public function translate(Request $request, $id)
    {
        abort_unless($request->user()->can('edit campaign_templates'), 403);

        $template = CampaignTemplate::findOrFail($id);

        $baseLang = config('translation.base_language', 'fr');
        $sourceLang = $request->input('source_language', $baseLang);
        $targetLang = $request->input('target_language', config('translation.target_languages')[0] ?? 'en');

        $request->validate([
            'source_language' => 'nullable|string|max:8',
            'target_language' => 'nullable|string|max:8|different:source_language',
            'overwrite'       => 'nullable|boolean',
        ]);

        // ── Server-side overwrite guard against cross-tab clobber ─────────────
        // Manual non-base translations must not be overwritten silently.
        if ($targetLang !== $baseLang) {
            $existing = $template->translationFor($targetLang);
            if ($existing && ! $existing->is_ai_generated && ! $request->boolean('overwrite')) {
                return response()->json([
                    'success'               => false,
                    'requires_confirmation' => true,
                    'message'               => 'Cette version a été modifiée manuellement. Confirmer le remplacement par une traduction IA ?',
                ], 409);
            }
        }

        // The base language has no translation row, so protect existing FR content
        // explicitly when generating FR from EN.
        if ($targetLang === $baseLang
            && (filled($template->subject) || filled($template->html_content))
            && ! $request->boolean('overwrite')) {
            return response()->json([
                'success'               => false,
                'requires_confirmation' => true,
                'message'               => 'La version française existe déjà. Confirmer son remplacement par une traduction IA ?',
            ], 409);
        }

        $success = app(TemplateTranslationService::class)->translateOne($template, $sourceLang, $targetLang);

        // Refresh the relation so payloads see the newly-upserted rows/base fields.
        $template->refresh()->load('translations');

        $translations = array_values(array_filter(
            array_map(
                fn ($lang) => $this->translationPayload($template, $lang),
                config('translation.target_languages', [])
            )
        ));

        $message = $success
            ? ($targetLang === $baseLang ? 'Version FR générée depuis EN.' : 'Version EN générée depuis FR.')
            : "Échec de la traduction : vérifiez la version source et la configuration de la traduction automatique.";

        return response()->json([
            'success'         => $success,
            'message'         => $message,
            'source_language' => $sourceLang,
            'target_language' => $targetLang,
            'base'            => $this->basePayload($template),
            'translations'    => $translations,
        ]);
    }

    /**
     * Persist a manually-edited translation row.
     *
     * Returns JSON — called via axios from the Traductions tab.
     */
    public function saveTranslation(Request $request, $id)
    {
        abort_unless($request->user()->can('edit campaign_templates'), 403);

        $template = CampaignTemplate::findOrFail($id);

        $request->validate([
            'language'     => 'required|string|max:8',
            'subject'      => 'required|string|max:255',
            'html_content' => 'required|string',
            'preview_text' => 'nullable|string|max:255',
        ]);

        abort_if(
            $request->input('language') === config('translation.base_language'),
            422,
            'La langue de base ne se traduit pas.'
        );

        app(TemplateTranslationService::class)->saveManual(
            $template,
            $request->input('language'),
            $request->only(['subject', 'html_content', 'preview_text'])
        );

        $template->load('translations');

        return response()->json([
            'success'      => true,
            'message'      => 'Traduction enregistrée.',
            'translations' => [$this->translationPayload($template, $request->input('language'))],
        ]);
    }

    /**
     * Toggle the reviewed_at marker on a translation row.
     *
     * Returns JSON — called via axios from the Traductions tab.
     */
    public function markReviewed(Request $request, $id)
    {
        abort_unless($request->user()->can('edit campaign_templates'), 403);

        $template = CampaignTemplate::findOrFail($id);

        $request->validate([
            'language' => 'required|string|max:8',
            'reviewed' => 'required|boolean',
        ]);

        $tr = app(TemplateTranslationService::class)->setReviewed(
            $template,
            $request->input('language'),
            $request->boolean('reviewed')
        );

        abort_if($tr === null, 404, 'Aucune traduction à relire.');

        return response()->json([
            'success'     => true,
            'reviewed'    => $tr->reviewed_at !== null,
            'reviewed_at' => optional($tr->reviewed_at)->diffForHumans(),
        ]);
    }

    /**
     * Serialize base FR fields to the JSON shape expected by the frontend.
     */
    private function basePayload(CampaignTemplate $template): array
    {
        return [
            'language'     => config('translation.base_language', 'fr'),
            'subject'      => $template->subject,
            'preview_text' => $template->preview_text,
            'html_content' => $template->html_content,
            'updated_at'   => optional($template->updated_at)->diffForHumans(),
        ];
    }

    /**
     * Serialize one translation row to the JSON shape expected by the frontend.
     */
    private function translationPayload(CampaignTemplate $template, string $lang): ?array
    {
        $tr = $template->translationFor($lang);

        if (! $tr) {
            return null;
        }

        return [
            'language'        => $tr->language,
            'subject'         => $tr->subject,
            'preview_text'    => $tr->preview_text,
            'html_content'    => $tr->html_content,
            'updated_at'      => optional($tr->updated_at)->diffForHumans(),
            'is_ai_generated' => (bool) $tr->is_ai_generated,
            'reviewed'        => $tr->reviewed_at !== null,
            'reviewed_at'     => optional($tr->reviewed_at)->diffForHumans(),
            'stale_fields'    => $template->staleFieldsFor($tr),
        ];
    }
}
