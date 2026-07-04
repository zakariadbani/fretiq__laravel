<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SettingController — application settings page.
 *
 * Gate:
 *   - GET  /admin/settings          → permission:view settings
 *   - POST /admin/settings/save     → permission:edit settings
 *
 * Pattern follows ObservabilityController (plain Controller, middleware in constructor).
 */
class SettingController extends Controller
{
    public function __construct(
        protected SettingService $settingService
    ) {
        $this->middleware('permission:view settings');
        $this->middleware('permission:edit settings')->only('save');
    }

    /**
     * Tabs configuration.
     *
     * Each tab has:
     *   'label'       string  — display name
     *   'enabled'     bool    — whether the tab is active (fields shown + saved)
     *   'description' string  — short French description
     *   'fields'      array   — field definitions (only present when enabled=true)
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $tabs = [

        // ── Enabled tab ─────────────────────────────────────────────────────────
        'decouverte' => [
            'label'       => 'Découverte',
            'enabled'     => true,
            'description' => 'Paramètres du moteur de découverte : scoring IA, enrichissement Hunter et seuils de qualification.',
            'fields'      => [
                'auto_scoring' => [
                    'type'    => 'boolean',
                    'label'   => 'Scoring automatique (IA)',
                    'default' => true,
                    'help'    => 'Attribue un score 0–100 à chaque entreprise découverte avant la récupération des contacts.',
                ],
                'auto_enrich' => [
                    'type'    => 'boolean',
                    'label'   => 'Enrichissement automatique des contacts',
                    'default' => true,
                    'help'    => 'Récupère automatiquement les contacts (Hunter) des entreprises dont le score atteint le seuil. 1 crédit par entreprise traitée.',
                ],
                'min_score_enrich' => [
                    'type'    => 'number',
                    'label'   => 'Score minimal pour enrichir',
                    'default' => 50,
                    'min'     => 0,
                    'max'     => 100,
                    'help'    => 'Seuil ignoré si le scoring automatique est désactivé.',
                ],
                'scoring_provider' => [
                    'type'  => 'static',
                    'label' => 'Fournisseur IA',
                ],
                'timezone' => [
                    'type'    => 'select',
                    'label'   => 'Fuseau horaire (quota quotidien)',
                    'default' => 'Europe/Paris',
                    'options' => ['Europe/Paris' => 'Europe/Paris', 'UTC' => 'UTC/GMT'],
                    'help'    => 'Détermine le jour « quotidien » pour les quotas de découverte et l\'heure de lancement automatique.',
                ],
            ],
        ],

        // ── Placeholder tabs (enabled=false) ─────────────────────────────────────
        'envoi_identites' => [
            'label'       => 'Envoi & Identités',
            'enabled'     => false,
            'description' => 'Configuration des identités d\'expéditeur et des paramètres d\'envoi par défaut.',
        ],

        'envoi_permissions' => [
            'label'       => 'Envoi & permissions',
            'enabled'     => false,
            'description' => 'Contrôle fin des permissions d\'envoi : quotas journaliers, fenêtres horaires et fuseau horaire.',
        ],

        'conformite' => [
            'label'       => 'Conformité',
            'enabled'     => false,
            'description' => 'Règles RGPD et conformité : activation de l\'envoi cold, durée de rétention des données et base légale.',
        ],

        'delivrabilite' => [
            'label'       => 'Délivrabilité',
            'enabled'     => false,
            'description' => 'Paramètres de délivrabilité : SPF / DKIM / DMARC, gestion des rebonds et warm-up.',
        ],

        'zoho_integrations' => [
            'label'       => 'Zoho & Intégrations',
            'enabled'     => false,
            'description' => 'Configuration des intégrations Zoho CRM et Zoho Campaigns : OAuth, drivers et synchronisation.',
        ],
    ];

    // ── Actions ──────────────────────────────────────────────────────────────────

    /**
     * Display the settings page.
     */
    public function index(): \Illuminate\View\View
    {
        $tabs = $this->tabs;

        // Compute static scoring_provider display for the Découverte tab
        $driver = config('services.scoring.driver', 'heuristic');
        $driverLabel = $driver === 'gemini' ? 'Gemini (IA)' : 'Heuristique (règles)';

        $apiKey = config('services.gemini.api_key');
        $badgeText = $apiKey ? 'Clé configurée' : 'Clé non configurée';
        $badgeClass = $apiKey ? 'badge-light-success' : 'badge-light-warning';

        $tabs['decouverte']['fields']['scoring_provider']['static_value'] = $driverLabel;
        $tabs['decouverte']['fields']['scoring_provider']['badge_text']   = $badgeText;
        $tabs['decouverte']['fields']['scoring_provider']['badge_class']  = $badgeClass;

        $settings = $this->settingService->all();

        return view('backend.contents.settings.index', compact('tabs', 'settings'));
    }

    /**
     * Save enabled-tab settings.
     *
     * Server-side whitelist: only fields from enabled tabs are processed.
     * Unknown POST keys are silently ignored.
     */
    public function save(Request $request): RedirectResponse
    {
        $activeTab = $request->input('active_tab', 'decouverte');

        // ── Validation ──────────────────────────────────────────────────────────
        $request->validate(
            [
                'settings.decouverte.min_score_enrich' => 'required|integer|between:0,100',
                'settings.decouverte.timezone'         => 'required|string|in:Europe/Paris,UTC',
            ],
            [
                'settings.decouverte.min_score_enrich.required'  => 'Le score minimal est obligatoire.',
                'settings.decouverte.min_score_enrich.integer'   => 'Le score minimal doit être un entier.',
                'settings.decouverte.min_score_enrich.between'   => 'Le score minimal doit être compris entre 0 et 100.',
                'settings.decouverte.timezone.required'          => 'Le fuseau horaire est obligatoire.',
                'settings.decouverte.timezone.in'                => 'Le fuseau horaire doit être Europe/Paris ou UTC.',
            ]
        );

        // ── Persist within a transaction ────────────────────────────────────────
        DB::transaction(function () use ($request) {
            foreach ($this->tabs as $group => $tabConfig) {
                if (! ($tabConfig['enabled'] ?? false)) {
                    continue;
                }

                $posted = $request->input("settings.{$group}", []);

                foreach ($tabConfig['fields'] as $fieldKey => $fieldDef) {
                    // Static fields are display-only, never saved
                    if (($fieldDef['type'] ?? '') === 'static') {
                        continue;
                    }

                    $type = $fieldDef['type'] ?? 'text';

                    if ($type === 'boolean') {
                        // Unchecked checkbox posts nothing; treat as false
                        $value = isset($posted[$fieldKey]) && $posted[$fieldKey] ? true : false;
                    } elseif ($type === 'number') {
                        $value = isset($posted[$fieldKey]) ? (int) $posted[$fieldKey] : ($fieldDef['default'] ?? 0);
                    } else {
                        $value = $posted[$fieldKey] ?? ($fieldDef['default'] ?? null);
                    }

                    $this->settingService->set("{$group}.{$fieldKey}", $value);
                }
            }
        });

        // Stale-repopulation race guard: clear after the transaction commits
        $this->settingService->clearCache();

        return redirect()
            ->to(route('admin.settings.index') . '#kt_tab_' . $activeTab)
            ->with('success', 'Les paramètres ont été enregistrés avec succès.');
    }
}
