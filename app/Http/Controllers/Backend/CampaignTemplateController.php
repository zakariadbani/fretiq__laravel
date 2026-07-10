<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\CampaignTemplatesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\CampaignTemplate;
use App\Services\Translation\TemplateTranslationService;
use App\Services\Zoho\ZohoCampaignsTemplatesService;
use Illuminate\Http\Request;

class CampaignTemplateController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, CampaignTemplate $model, CampaignTemplatesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view campaign_templates')->only(['index', 'view']);
        $this->middleware('permission:create campaign_templates')->only(['create', 'store']);
        $this->middleware('permission:edit campaign_templates')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete campaign_templates')->only(['delete']);

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
            : "Échec de la traduction : vérifiez la version source et la configuration de l'API Gemini.";

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
