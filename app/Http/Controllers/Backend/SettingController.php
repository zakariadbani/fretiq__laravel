<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Discovery\DiscoveryEngineRegistry;
use App\Services\Discovery\ContactVerificationBatchService;
use App\Services\Discovery\EmailVerificationSettings;
use App\Services\Scheduling\BusinessCalendarService;
use App\Services\Settings\SettingService;
use App\Support\DomainBlocklist;
use App\Support\ZohoSyncFrequency;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        protected BusinessCalendarService $calendarService,
    ) {
        $this->middleware('permission:view settings')->only('index');
        $this->middleware('permission:edit settings')->only(['save', 'estimateEmailVerification', 'runEmailVerification']);
        $this->middleware('permission:verify contacts')->only(['estimateEmailVerification', 'runEmailVerification']);
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
            'label' => 'Découverte',
            'enabled' => true,
            'description' => 'Paramètres du moteur de découverte : scoring IA, enrichissement des contacts et seuils de qualification.',
            'fields' => [
                'discovery_engines' => [
                    'type' => 'multiselect',
                    'label' => 'Moteurs de découverte',
                    'default' => ['google', 'google_maps'],
                    'options' => [],
                    'help' => 'Chaque requête active est exécutée sur tous les moteurs sélectionnés. Une tentative sur un moteur consomme une requête du quota.',
                ],
                'auto_scoring' => [
                    'type' => 'boolean',
                    'label' => 'Scoring automatique (IA)',
                    'default' => true,
                    'help' => 'Attribue un score 0–100 à chaque entreprise découverte avant la récupération des contacts.',
                ],
                'auto_enrich' => [
                    'type' => 'boolean',
                    'label' => 'Enrichissement automatique des contacts (valeur héritée)',
                    'default' => false,
                    'help' => 'Valeur de repli utilisée UNIQUEMENT par les critères de prospection réglés sur « Hérité ». La configuration recommandée est de laisser cette option désactivée et d\'activer l\'enrichissement critère par critère. Récupère les contacts des entreprises dont le score atteint le seuil — 1 crédit par entreprise traitée.',
                ],
                'min_score_enrich' => [
                    'type' => 'number',
                    'label' => 'Score minimal pour enrichir',
                    'default' => 50,
                    'min' => 0,
                    'max' => 100,
                    'help' => 'Seuil ignoré si le scoring automatique est désactivé.',
                ],
                'blocked_domains' => [
                    'type' => 'textarea',
                    'label' => 'Domaines exclus de la découverte',
                    'rows' => 10,
                    'help' => 'Un domaine par ligne. Les sous-domaines sont couverts automatiquement (ex. « gouv.fr » bloque « www.douane.gouv.fr »). Les lignes commençant par « # » sont des commentaires. Laissez vide pour utiliser la liste par défaut intégrée.',
                ],
                'blocked_url_extensions' => [
                    'type' => 'text',
                    'label' => 'Extensions de fichiers exclues',
                    'help' => 'Résultats dont l\'URL pointe vers ces fichiers (PDF, documents Word/Excel…) — séparés par des virgules.',
                ],
                'fetch_homepage' => [
                    'type' => 'boolean',
                    'label' => 'Analyser la page d\'accueil avant scoring',
                    'default' => true,
                    'help' => 'Récupère le texte de la page d\'accueil du site et le transmet à l\'IA en plus du titre et de la description issus des résultats de recherche. Améliore fortement la précision des scores (un article de presse sur le fret ne sera plus confondu avec un chargeur). Coût : une requête HTTP par nouveau domaine, mise en cache ensuite.',
                ],
                'homepage_excerpt_chars' => [
                    'type' => 'number',
                    'label' => 'Longueur de l\'extrait de page d\'accueil (caractères)',
                    'default' => 2000,
                    'min' => 500,
                    'max' => 8000,
                    'help' => 'Nombre de caractères de la page d\'accueil transmis à l\'IA. Plus long = plus précis mais plus coûteux en jetons.',
                ],
                'homepage_cache_days' => [
                    'type' => 'number',
                    'label' => 'Durée du cache des pages d\'accueil (jours)',
                    'default' => 7,
                    'min' => 1,
                    'max' => 90,
                    'help' => 'Un domaine déjà analysé n\'est pas re-téléchargé pendant cette durée, y compris lorsque la récupération a échoué.',
                ],
                'homepage_timeout' => [
                    'type' => 'number',
                    'label' => 'Délai d\'attente de la page d\'accueil (secondes)',
                    'default' => 3,
                    'min' => 1,
                    'max' => 30,
                    'help' => 'Au-delà de ce délai, le site est ignoré et le scoring se base uniquement sur les résultats de recherche. Chaque seconde ajoutée ici est payée sur chaque domaine injoignable d\'une exécution.',
                ],
                'homepage_http_fallback' => [
                    'type' => 'boolean',
                    'label' => 'Réessayer en http:// si la connexion https échoue',
                    'default' => false,
                    'help' => 'Désactivé par défaut. Quelques rares sites ne répondent qu\'en http://, mais activer cette option DOUBLE le temps d\'attente maximal sur chaque domaine mort (un délai complet en https, puis un second en http). Ne l\'activez que si vous constatez des sites http-only ignorés à tort.',
                ],
                'run_time_budget' => [
                    'type' => 'number',
                    'label' => 'Budget de temps par tentative d\'exécution (secondes)',
                    'default' => 240,
                    'min' => 30,
                    'max' => 240,
                    'help' => 'Durée maximale de toute une tentative (collecte, préchargement et traitement). Dix secondes sont réservées à la sauvegarde et à la reprise propre du job.',
                ],
                'scoring_provider' => [
                    'type' => 'static',
                    'label' => 'Fournisseur IA',
                ],
                'timezone' => [
                    'type' => 'select',
                    'label' => 'Fuseau horaire (quota quotidien)',
                    'default' => 'Europe/Paris',
                    'options' => ['Europe/Paris' => 'Europe/Paris', 'UTC' => 'UTC/GMT'],
                    'help' => 'Détermine le jour « quotidien » pour les quotas de découverte et l\'heure de lancement automatique. Sert aussi de fuseau horaire par défaut pour le calendrier de planification (jours ouvrés, jours fériés) lorsqu\'une campagne n\'a pas son propre fuseau.',
                ],
            ],
        ],
        'automatisation' => [
            'label' => 'Automatisations',
            'enabled' => true,
            'description' => 'Activez ou suspendez les commandes lancées automatiquement par le planificateur Laravel.',
            'fields' => [
                'cron_enabled' => [
                    'type' => 'boolean',
                    'label' => 'Activer le Cron',
                    'default' => true,
                    'help' => 'Interrupteur global : lorsqu\'il est désactivé, aucune commande planifiée ci-dessous ne démarre. Les commandes manuelles et les jobs déjà en file continuent.',
                ],
                'campaigns_dispatch_due' => [
                    'type' => 'boolean',
                    'label' => 'Envoyer les campagnes dues',
                    'default' => true,
                    'help' => 'campaigns:dispatch-due - toutes les minutes.',
                ],
                'campaigns_generate_runs' => [
                    'type' => 'boolean',
                    'label' => 'Générer les exécutions de campagnes',
                    'default' => true,
                    'help' => 'campaigns:generate-runs - toutes les minutes.',
                ],
                'sequences_process' => [
                    'type' => 'boolean',
                    'label' => 'Traiter les séquences',
                    'default' => true,
                    'help' => 'sequences:process - toutes les minutes.',
                ],
                'campaigns_sync_sequence_enrollments' => [
                    'type' => 'boolean',
                    'label' => 'Synchroniser les inscriptions aux séquences',
                    'default' => true,
                    'help' => 'campaigns:sync-sequence-enrollments - toutes les minutes.',
                ],
                'campaign_sync_stats' => [
                    'type' => 'boolean',
                    'label' => 'Synchroniser les statistiques des campagnes',
                    'default' => true,
                    'help' => 'campaign:sync-stats - toutes les 15 minutes.',
                ],
                'discovery_terminalize_stale' => [
                    'type' => 'boolean',
                    'label' => 'Clôturer les découvertes bloquées',
                    'default' => true,
                    'help' => 'discovery:terminalize-stale - toutes les minutes.',
                ],
                'prospect_auto_discover' => [
                    'type' => 'boolean',
                    'label' => 'Lancer la découverte automatique',
                    'default' => true,
                    'help' => 'prospect:auto-discover - toutes les heures.',
                ],
                'inbox_poll' => [
                    'type' => 'boolean',
                    'label' => 'Relever les réponses IMAP',
                    'default' => true,
                    'help' => 'inbox:poll - toutes les 5 minutes.',
                ],
            ],
        ],
        'planification' => [
            'label' => 'Planification',
            'enabled' => true,
            'description' => 'Jours ouvrés et dates d\'exclusion pour l\'envoi automatique des campagnes, séquences et découvertes.',
            'fields' => [
                'skip_weekends' => [
                    'type' => 'boolean',
                    'label' => 'Ne rien envoyer le samedi et le dimanche',
                    'default' => true,
                    'help' => 'Décale automatiquement au jour ouvré suivant tout envoi planifié tombant un week-end : campagnes récurrentes, lots progressifs, relances de séquences et découverte automatique. « Envoyer maintenant » et les campagnes ponctuelles restent autorisés tous les jours.',
                ],
                'blackout_dates' => [
                    'type' => 'textarea',
                    'label' => 'Jours fériés et dates exclues',
                    'rows' => 10,
                    'default' => '',
                    'placeholder' => "2026-12-25\n2026-12-26\n01-01 # Jour de l'an, chaque année",
                    'help' => 'Une date par ligne. Format AAAA-MM-JJ pour une date ponctuelle, ou MM-JJ pour une date qui se répète chaque année (ex. « 12-25 » pour Noël). Les lignes commençant par « # » sont des commentaires. Laissez vide pour n\'exclure aucune date.',
                ],
                'blackout_preview' => [
                    'type' => 'static',
                    'label' => 'Prochains jours exclus',
                ],
                'daily_send_cap' => [
                    'type' => 'number',
                    'label' => 'Plafond d\'envois par jour (séquences)',
                    'default' => 0,
                    'min' => 0,
                    'max' => 100000,
                    'help' => '0 = désactivé. Sinon nombre maximal d\'emails de séquence par jour ; surplus reporté au jour ouvré suivant.',
                ],
            ],
        ],

        // ── Placeholder tabs (enabled=false) ─────────────────────────────────────
        'zoho' => [
            'label' => 'Zoho',
            'enabled' => true,
            'description' => 'Contrôlez les synchronisations automatiques du miroir Zoho CRM.',
            'fields' => [
                'auto_sync_enabled' => [
                    'type' => 'boolean', 'label' => 'Synchronisation automatique', 'default' => false,
                    'help' => 'Lance les synchronisations incrémentales planifiées.',
                ],
                'sync_frequency' => [
                    'type' => 'select', 'label' => 'Fréquence de synchronisation', 'default' => 'hourly', 'options' => [],
                ],
                'nightly_reconciliation_enabled' => [
                    'type' => 'boolean', 'label' => 'Réconciliation nocturne', 'default' => false,
                    'help' => 'Vérifie quotidiennement les écarts du miroir à 02:30.',
                ],
            ],
        ],

        'envoi_identites' => [
            'label' => 'Envoi & Identités',
            'enabled' => false,
            'description' => 'Configuration des identités d\'expéditeur et des paramètres d\'envoi par défaut.',
        ],

        'envoi_permissions' => [
            'label' => 'Envoi & permissions',
            'enabled' => false,
            'description' => 'Contrôle fin des permissions d\'envoi : quotas journaliers, fenêtres horaires et fuseau horaire.',
        ],

        'conformite' => [
            'label' => 'Conformité',
            'enabled' => false,
            'description' => 'Règles RGPD et conformité : activation de l\'envoi cold, durée de rétention des données et base légale.',
        ],

        'delivrabilite' => [
            'label' => 'Délivrabilité',
            'enabled' => true,
            'description' => 'Vérification des adresses et politique appliquée par défaut aux nouvelles campagnes.',
            'fields' => [
                'email_verification_enabled' => [
                    'type' => 'boolean',
                    'label' => 'Activer la vérification des adresses email',
                    'default' => true,
                    'help' => 'Lorsqu\'elle est désactivée, aucun nouvel appel de vérification ne part, y compris depuis les actions individuelles et groupées.',
                ],
                'default_email_verification_policy' => [
                    'type' => 'select',
                    'label' => 'Politique par défaut des nouvelles campagnes',
                    'default' => 'verified_only',
                    'options' => [
                        'verified_only' => 'Adresses vérifiées uniquement',
                        'all_sendable' => 'Toutes les adresses envoyables',
                    ],
                    'help' => 'Chaque campagne conserve ensuite son propre choix.',
                ],
            ],
        ],

        'api_keys' => [
            'label' => 'Clés API',
            'enabled' => true,
            'description' => 'Clés des fournisseurs externes utilisées par la découverte et le scoring. Laissez un champ vide pour conserver la clé actuelle ; cochez « Réinitialiser » pour revenir à la valeur définie sur le serveur (.env).',
            'fields' => [
                'serpapi' => [
                    'type' => 'password',
                    'label' => 'Clé — Fournisseur de recherche',
                    'help' => 'Utilisée par le moteur de découverte pour interroger les résultats de recherche.',
                ],
                'hunter' => [
                    'type' => 'password',
                    'label' => 'Clé — Recherche d\'emails',
                    'help' => 'Utilisée pour retrouver et vérifier les adresses email des contacts découverts.',
                ],
                'gemini' => [
                    'type' => 'password',
                    'label' => 'Clé — Assistant IA',
                    'help' => 'Utilisée pour le scoring automatique des entreprises découvertes.',
                ],
            ],
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
        $tabs['zoho']['fields']['sync_frequency']['options'] = ZohoSyncFrequency::options();

        // Compute static scoring_provider display for the Découverte tab
        $driver = config('services.scoring.driver', 'heuristic');
        $driverLabel = $driver === 'gemini' ? 'Assistant IA' : 'Heuristique (règles)';

        $apiKey = config('services.gemini.api_key');
        $badgeText = $apiKey ? 'Clé configurée' : 'Clé non configurée';
        $badgeClass = $apiKey ? 'badge-light-success' : 'badge-light-warning';

        $tabs['decouverte']['fields']['scoring_provider']['static_value'] = $driverLabel;
        $tabs['decouverte']['fields']['scoring_provider']['badge_text'] = $badgeText;
        $tabs['decouverte']['fields']['scoring_provider']['badge_class'] = $badgeClass;

        // Blocklist defaults live in DomainBlocklist, not in the $tabs declaration —
        // this makes the textarea render the effective list when nothing is stored.
        $tabs['decouverte']['fields']['blocked_domains']['default'] = DomainBlocklist::defaultDomainsText();
        $tabs['decouverte']['fields']['blocked_url_extensions']['default'] = DomainBlocklist::defaultExtensionsText();

        // Planification tab — read-only preview of the next few blackout-list
        // entries (NOT plain weekends — blockedDatesFor() would be drowned out
        // by the next Saturdays/Sundays and never show the admin's own entries),
        // the feedback a plain textarea otherwise lacks.
        $calendarTz = $this->calendarService->resolveTimezone(null);
        $previewDates = array_slice(
            $this->calendarService->blackoutDatesFor(Carbon::now($calendarTz), 120),
            0,
            5
        );
        $tabs['planification']['fields']['blackout_preview']['static_value'] = $previewDates === []
            ? 'Aucune date exclue configurée dans les 120 prochains jours.'
            : implode(', ', array_map(
                fn (string $date): string => $this->formatFrenchDayLabel(Carbon::parse($date, $calendarTz)),
                $previewDates
            ));

        // API-keys tab — never pass the raw secret into the view. Only an
        // is_set flag + a masked last-4-chars hint, computed from the live
        // config (which already reflects any DB override — see boot() bridge
        // in AppServiceProvider).
        foreach (array_keys($this->tabs['api_keys']['fields']) as $provider) {
            $current = (string) config("services.{$provider}.api_key", '');
            $tabs['api_keys']['fields'][$provider]['is_set'] = $current !== '';
            $tabs['api_keys']['fields'][$provider]['masked'] = $current !== ''
                ? '••••'.substr($current, -4)
                : null;
        }

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
        if ($request->has('settings.decouverte')) {
            $request->validate(
                [
                    'settings.decouverte.min_score_enrich' => 'required|integer|between:0,100',
                    'settings.decouverte.timezone' => 'required|string|in:Europe/Paris,UTC',
                    'settings.decouverte.blocked_domains' => 'nullable|string|max:20000',
                    'settings.decouverte.blocked_url_extensions' => 'nullable|string|max:500',
                    'settings.decouverte.homepage_excerpt_chars' => 'nullable|integer|between:500,8000',
                    'settings.decouverte.homepage_cache_days' => 'nullable|integer|between:1,90',
                    'settings.decouverte.homepage_timeout' => 'nullable|integer|between:1,30',
                    'settings.decouverte.run_time_budget' => 'nullable|integer|between:30,240',
                    'settings.decouverte.discovery_engines' => 'required|array|min:1',
                    'settings.decouverte.discovery_engines.*' => [
                        'required',
                        'string',
                        'distinct',
                        Rule::in($this->engineRegistry->ids()),
                    ],
                ],
                [
                    'settings.decouverte.min_score_enrich.required' => 'Le score minimal est obligatoire.',
                    'settings.decouverte.min_score_enrich.integer' => 'Le score minimal doit être un entier.',
                    'settings.decouverte.min_score_enrich.between' => 'Le score minimal doit être compris entre 0 et 100.',
                    'settings.decouverte.timezone.required' => 'Le fuseau horaire est obligatoire.',
                    'settings.decouverte.timezone.in' => 'Le fuseau horaire doit être Europe/Paris ou UTC.',
                    'settings.decouverte.blocked_domains.string' => 'La liste des domaines exclus doit être du texte.',
                    'settings.decouverte.blocked_domains.max' => 'La liste des domaines exclus ne peut pas dépasser 20000 caractères.',
                    'settings.decouverte.blocked_url_extensions.string' => 'La liste des extensions exclues doit être du texte.',
                    'settings.decouverte.blocked_url_extensions.max' => 'La liste des extensions exclues ne peut pas dépasser 500 caractères.',
                    'settings.decouverte.homepage_excerpt_chars.integer' => 'La longueur de l\'extrait doit être un entier.',
                    'settings.decouverte.homepage_excerpt_chars.between' => 'La longueur de l\'extrait doit être comprise entre 500 et 8000 caractères.',
                    'settings.decouverte.homepage_cache_days.integer' => 'La durée du cache doit être un entier.',
                    'settings.decouverte.homepage_cache_days.between' => 'La durée du cache doit être comprise entre 1 et 90 jours.',
                    'settings.decouverte.homepage_timeout.integer' => 'Le délai d\'attente doit être un entier.',
                    'settings.decouverte.homepage_timeout.between' => 'Le délai d\'attente doit être compris entre 1 et 30 secondes.',
                ]
            );
        }

        if ($request->has('settings.planification')) {
            $request->validate(
                [
                    'settings.planification.blackout_dates' => [
                        'nullable',
                        'string',
                        'max:5000',
                        function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! is_string($value) || trim($value) === '') {
                                return;
                            }

                            $invalid = BusinessCalendarService::invalidLines($value);

                            if ($invalid === []) {
                                return;
                            }

                            $shown = array_slice($invalid, 0, 5);
                            $suffix = count($invalid) > 5 ? ', …' : '';

                            $fail(
                                'Lignes invalides dans les jours fériés et dates exclues (formats attendus : '
                                .'AAAA-MM-JJ pour une date ponctuelle, MM-JJ pour une date annuelle) : '
                                .implode(', ', $shown).$suffix
                            );
                        },
                    ],
                    'settings.planification.daily_send_cap' => 'nullable|integer|min:0|max:100000',
                ],
                [
                    'settings.planification.blackout_dates.string' => 'La liste des jours exclus doit être du texte.',
                    'settings.planification.blackout_dates.max' => 'La liste des jours exclus ne peut pas dépasser 5000 caractères.',
                ]
            );
        }

        // ── Persist within a transaction ────────────────────────────────────────
        if ($request->has('settings.zoho')) {
            $request->validate([
                'settings.zoho.sync_frequency' => ['required', 'string', Rule::in(ZohoSyncFrequency::values())],
            ]);
        }

        if ($request->has('settings.delivrabilite')) {
            $request->validate([
                'settings.delivrabilite.default_email_verification_policy' => [
                    'required',
                    Rule::in(['verified_only', 'all_sendable']),
                ],
            ]);
        }

        if ($request->has('settings.api_keys')) {
            $request->validate([
                'settings.api_keys.serpapi' => 'nullable|string|max:255',
                'settings.api_keys.hunter' => 'nullable|string|max:255',
                'settings.api_keys.gemini' => 'nullable|string|max:255',
            ]);
        }

        DB::transaction(function () use ($request) {
            foreach ($this->tabs as $group => $tabConfig) {
                if (! ($tabConfig['enabled'] ?? false) || ! $request->has("settings.{$group}")) {
                    continue;
                }

                $posted = $request->input("settings.{$group}", []);
                $resets = $request->input("reset.{$group}", []);

                foreach ($tabConfig['fields'] as $fieldKey => $fieldDef) {
                    // Static fields are display-only, never saved
                    if (($fieldDef['type'] ?? '') === 'static') {
                        continue;
                    }

                    $type = $fieldDef['type'] ?? 'text';

                    if ($type === 'password') {
                        // Reset takes precedence: drop the DB override entirely
                        // so the boot-time bridge falls back to .env.
                        if (! empty($resets[$fieldKey])) {
                            Setting::where('group_name', $group)->where('setting_key', $fieldKey)->delete();

                            continue;
                        }

                        // Leave-blank-to-keep: an empty submit does NOT overwrite
                        // the stored override (and does NOT fall back to .env —
                        // that's what the reset checkbox above is for).
                        $posted[$fieldKey] = trim((string) ($posted[$fieldKey] ?? ''));
                        if ($posted[$fieldKey] === '') {
                            continue;
                        }

                        $this->settingService->set("{$group}.{$fieldKey}", $posted[$fieldKey]);

                        continue;
                    }

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
            ->to(route('admin.settings.index').'#kt_tab_'.$activeTab)
            ->with('success', 'Les paramètres ont été enregistrés avec succès.');
    }

    public function estimateEmailVerification(ContactVerificationBatchService $batch): \Illuminate\Http\JsonResponse
    {
        return response()->json($batch->estimate());
    }

    public function runEmailVerification(
        Request $request,
        ContactVerificationBatchService $batch,
        EmailVerificationSettings $settings,
    ): \Illuminate\Http\JsonResponse {
        $validated = $request->validate([
            'confirm' => ['accepted'],
            'expected_count' => ['required', 'integer', 'min:0'],
        ]);

        if (! $settings->enabled()) {
            return response()->json(['message' => 'La vérification email est désactivée.'], 409);
        }

        $estimate = $batch->estimate();
        if ((int) $validated['expected_count'] !== $estimate['eligible']) {
            return response()->json([
                'message' => 'L\'estimation a changé. Confirmez le nouveau nombre de contacts.',
                'estimate' => $estimate,
            ], 409);
        }

        return response()->json(['queued' => $batch->enqueue(), 'estimate' => $estimate]);
    }

    /**
     * Format a date as "lun. 25 déc. 2026" — hardcoded French, no __()/trans()
     * per this module's convention. Used only for the read-only blackout_preview.
     */
    private function formatFrenchDayLabel(Carbon $date): string
    {
        $weekdays = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
        $months = [
            1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
            7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
        ];

        return sprintf(
            '%s %d %s %d',
            $weekdays[$date->dayOfWeek],
            $date->day,
            $months[$date->month],
            $date->year
        );
    }
}
