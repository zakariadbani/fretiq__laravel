<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingService;
use App\Services\Discovery\DiscoveryEngineRegistry;
use App\Support\DomainBlocklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

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
        protected SettingService $settingService,
        protected DiscoveryEngineRegistry $engineRegistry,
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
            'description' => 'Paramètres du moteur de découverte : scoring IA, enrichissement des contacts et seuils de qualification.',
            'fields'      => [
                'discovery_engines' => [
                    'type'    => 'multiselect',
                    'label'   => 'Moteurs de découverte',
                    'default' => ['google', 'google_maps'],
                    'options' => [],
                    'help'    => 'Chaque requête active est exécutée sur tous les moteurs sélectionnés. Une tentative sur un moteur consomme une requête du quota.',
                ],
                'auto_scoring' => [
                    'type'    => 'boolean',
                    'label'   => 'Scoring automatique (IA)',
                    'default' => true,
                    'help'    => 'Attribue un score 0–100 à chaque entreprise découverte avant la récupération des contacts.',
                ],
                'auto_enrich' => [
                    'type'    => 'boolean',
                    'label'   => 'Enrichissement automatique des contacts (valeur héritée)',
                    'default' => false,
                    'help'    => 'Valeur de repli utilisée UNIQUEMENT par les critères de prospection réglés sur « Hérité ». La configuration recommandée est de laisser cette option désactivée et d\'activer l\'enrichissement critère par critère. Récupère les contacts des entreprises dont le score atteint le seuil — 1 crédit par entreprise traitée.',
                ],
                'min_score_enrich' => [
                    'type'    => 'number',
                    'label'   => 'Score minimal pour enrichir',
                    'default' => 50,
                    'min'     => 0,
                    'max'     => 100,
                    'help'    => 'Seuil ignoré si le scoring automatique est désactivé.',
                ],
                'blocked_domains' => [
                    'type'  => 'textarea',
                    'label' => 'Domaines exclus de la découverte',
                    'rows'  => 10,
                    'help'  => 'Un domaine par ligne. Les sous-domaines sont couverts automatiquement (ex. « gouv.fr » bloque « www.douane.gouv.fr »). Les lignes commençant par « # » sont des commentaires. Laissez vide pour utiliser la liste par défaut intégrée.',
                ],
                'blocked_url_extensions' => [
                    'type'  => 'text',
                    'label' => 'Extensions de fichiers exclues',
                    'help'  => 'Résultats dont l\'URL pointe vers ces fichiers (PDF, documents Word/Excel…) — séparés par des virgules.',
                ],
                'fetch_homepage' => [
                    'type'    => 'boolean',
                    'label'   => 'Analyser la page d\'accueil avant scoring',
                    'default' => true,
                    'help'    => 'Récupère le texte de la page d\'accueil du site et le transmet à l\'IA en plus du titre et de la description issus des résultats de recherche. Améliore fortement la précision des scores (un article de presse sur le fret ne sera plus confondu avec un chargeur). Coût : une requête HTTP par nouveau domaine, mise en cache ensuite.',
                ],
                'homepage_excerpt_chars' => [
                    'type'    => 'number',
                    'label'   => 'Longueur de l\'extrait de page d\'accueil (caractères)',
                    'default' => 2000,
                    'min'     => 500,
                    'max'     => 8000,
                    'help'    => 'Nombre de caractères de la page d\'accueil transmis à l\'IA. Plus long = plus précis mais plus coûteux en jetons.',
                ],
                'homepage_cache_days' => [
                    'type'    => 'number',
                    'label'   => 'Durée du cache des pages d\'accueil (jours)',
                    'default' => 7,
                    'min'     => 1,
                    'max'     => 90,
                    'help'    => 'Un domaine déjà analysé n\'est pas re-téléchargé pendant cette durée, y compris lorsque la récupération a échoué.',
                ],
                'homepage_timeout' => [
                    'type'    => 'number',
                    'label'   => 'Délai d\'attente de la page d\'accueil (secondes)',
                    'default' => 3,
                    'min'     => 1,
                    'max'     => 30,
                    'help'    => 'Au-delà de ce délai, le site est ignoré et le scoring se base uniquement sur les résultats de recherche. Chaque seconde ajoutée ici est payée sur chaque domaine injoignable d\'une exécution.',
                ],
                'homepage_http_fallback' => [
                    'type'    => 'boolean',
                    'label'   => 'Réessayer en http:// si la connexion https échoue',
                    'default' => false,
                    'help'    => 'Désactivé par défaut. Quelques rares sites ne répondent qu\'en http://, mais activer cette option DOUBLE le temps d\'attente maximal sur chaque domaine mort (un délai complet en https, puis un second en http). Ne l\'activez que si vous constatez des sites http-only ignorés à tort.',
                ],
                'run_time_budget' => [
                    'type'    => 'number',
                    'label'   => 'Budget de temps par tentative d\'exécution (secondes)',
                    'default' => 240,
                    'min'     => 30,
                    'max'     => 3600,
                    'help'    => 'Durée maximale passée à traiter des entreprises lors d\'une même tentative. Au-delà, l\'exécution s\'arrête proprement et reprend exactement là où elle s\'est arrêtée à la tentative suivante — aucune entreprise n\'est perdue. Cette valeur DOIT rester inférieure au délai d\'expiration de la tâche en file d\'attente (300 secondes) : sinon la tâche est interrompue brutalement en plein traitement au lieu de se terminer proprement.',
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
        $tabs['decouverte']['fields']['discovery_engines']['options'] = $this->engineRegistry->options();

        // Compute static scoring_provider display for the Découverte tab
        $driver = config('services.scoring.driver', 'heuristic');
        $driverLabel = $driver === 'gemini' ? 'Assistant IA' : 'Heuristique (règles)';

        $apiKey = config('services.gemini.api_key');
        $badgeText = $apiKey ? 'Clé configurée' : 'Clé non configurée';
        $badgeClass = $apiKey ? 'badge-light-success' : 'badge-light-warning';

        $tabs['decouverte']['fields']['scoring_provider']['static_value'] = $driverLabel;
        $tabs['decouverte']['fields']['scoring_provider']['badge_text']   = $badgeText;
        $tabs['decouverte']['fields']['scoring_provider']['badge_class']  = $badgeClass;

        // Blocklist defaults live in DomainBlocklist, not in the $tabs declaration —
        // this makes the textarea render the effective list when nothing is stored.
        $tabs['decouverte']['fields']['blocked_domains']['default']        = DomainBlocklist::defaultDomainsText();
        $tabs['decouverte']['fields']['blocked_url_extensions']['default'] = DomainBlocklist::defaultExtensionsText();

        $settings = $this->settingService->all();
        if (array_key_exists('decouverte.discovery_engines', $settings)) {
            $settings['decouverte.discovery_engines'] = $this->engineRegistry->sanitize(
                $settings['decouverte.discovery_engines']
            );
        }

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
                'settings.decouverte.blocked_domains'  => 'nullable|string|max:20000',
                'settings.decouverte.blocked_url_extensions' => 'nullable|string|max:500',
                'settings.decouverte.homepage_excerpt_chars' => 'nullable|integer|between:500,8000',
                'settings.decouverte.homepage_cache_days'    => 'nullable|integer|between:1,90',
                'settings.decouverte.homepage_timeout'       => 'nullable|integer|between:1,30',
                'settings.decouverte.run_time_budget'        => 'nullable|integer|between:30,3600',
                'settings.decouverte.discovery_engines'      => 'required|array|min:1',
                'settings.decouverte.discovery_engines.*'    => [
                    'required',
                    'string',
                    'distinct',
                    Rule::in($this->engineRegistry->ids()),
                ],
            ],
            [
                'settings.decouverte.min_score_enrich.required'  => 'Le score minimal est obligatoire.',
                'settings.decouverte.min_score_enrich.integer'   => 'Le score minimal doit être un entier.',
                'settings.decouverte.min_score_enrich.between'   => 'Le score minimal doit être compris entre 0 et 100.',
                'settings.decouverte.timezone.required'          => 'Le fuseau horaire est obligatoire.',
                'settings.decouverte.timezone.in'                => 'Le fuseau horaire doit être Europe/Paris ou UTC.',
                'settings.decouverte.blocked_domains.string'     => 'La liste des domaines exclus doit être du texte.',
                'settings.decouverte.blocked_domains.max'        => 'La liste des domaines exclus ne peut pas dépasser 20000 caractères.',
                'settings.decouverte.blocked_url_extensions.string' => 'La liste des extensions exclues doit être du texte.',
                'settings.decouverte.blocked_url_extensions.max'    => 'La liste des extensions exclues ne peut pas dépasser 500 caractères.',
                'settings.decouverte.homepage_excerpt_chars.integer' => 'La longueur de l\'extrait doit être un entier.',
                'settings.decouverte.homepage_excerpt_chars.between' => 'La longueur de l\'extrait doit être comprise entre 500 et 8000 caractères.',
                'settings.decouverte.homepage_cache_days.integer'    => 'La durée du cache doit être un entier.',
                'settings.decouverte.homepage_cache_days.between'    => 'La durée du cache doit être comprise entre 1 et 90 jours.',
                'settings.decouverte.homepage_timeout.integer'       => 'Le délai d\'attente doit être un entier.',
                'settings.decouverte.homepage_timeout.between'       => 'Le délai d\'attente doit être compris entre 1 et 30 secondes.',
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
                    } elseif ($type === 'multiselect') {
                        $value = $this->engineRegistry->sanitize($posted[$fieldKey] ?? ($fieldDef['default'] ?? []));
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
