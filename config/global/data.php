<?php

/**
 * Shared enum / status lists for fretiq.
 *
 * Shape: 'key' => ['label' => '...', 'color' => '...']
 * color maps to Bootstrap badge variants: primary, secondary, success, danger, warning, info, dark
 *
 * Consumption patterns:
 *   Validation : 'required|in:'.implode(',', array_keys(config('global.data.company_relationships')))
 *   Accessor   : $s = config('global.data.company_relationships')[$this->relationship] ?? [];
 *                return 'badge-light-'.($s['color'] ?? 'secondary');
 *   DataTable  : ['type' => 'select_enum', 'configKey' => 'company_relationships', ...]
 */

return [

    //---------------------------------------------------------------------------
    // Entreprises — relation commerciale
    //---------------------------------------------------------------------------
    'company_relationships' => [
        'prospect' => ['label' => 'Prospect',  'color' => 'info'],
        'client' => ['label' => 'Client',    'color' => 'success'],
    ],

    //---------------------------------------------------------------------------
    // Entreprises — source d'origine
    //---------------------------------------------------------------------------
    'company_sources' => [
        'discovered' => ['label' => 'Découverte auto', 'color' => 'info'],
        'zoho' => ['label' => 'Import Zoho',     'color' => 'primary'],
        'manual' => ['label' => 'Saisie manuelle', 'color' => 'secondary'],
    ],

    //---------------------------------------------------------------------------
    // Entreprises — statut de qualification
    //---------------------------------------------------------------------------
    'company_qualification_statuses' => [
        'pending' => ['label' => 'En attente',   'color' => 'warning'],
        'qualified' => ['label' => 'Qualifiée',    'color' => 'success'],
        'rejected' => ['label' => 'Rejetée',      'color' => 'danger'],
    ],

    //---------------------------------------------------------------------------
    // Entreprises — statut d'enrichissement (pourquoi 0 contact ?)
    // Keys map 1:1 to App\Models\Company::ENRICHMENT_* constants.
    // A NULL column value means « Non tenté » — see company_enrichment_status_null.
    //---------------------------------------------------------------------------
    'company_enrichment_statuses' => [
        'enriched' => ['label' => 'Enrichi',                         'color' => 'success'],
        'hunter_empty' => ['label' => 'Aucun email trouvé',              'color' => 'warning'],
        'hunter_failed' => ['label' => 'Échec de la recherche de contacts',      'color' => 'danger'],
        'enriching' => ['label' => 'Recherche de contacts en cours',        'color' => 'primary'],
        'skipped_low_score' => ['label' => 'Sous le seuil de contacts',               'color' => 'warning'],
        'skipped_enrich_off' => ['label' => 'Enrichissement désactivé',        'color' => 'secondary'],
        'skipped_budget' => ['label' => 'Quota contacts atteint',          'color' => 'warning'],
        'skipped_provider_unavailable' => ['label' => 'Recherche de contacts indisponible',   'color' => 'warning'],
        'skipped_excluded' => ['label' => 'Exclue de l’enrichissement',      'color' => 'secondary'],
    ],

    // Rendered when companies.enrichment_status IS NULL (never attempted).
    'company_enrichment_status_null' => ['label' => 'Recherche de contacts non effectuée', 'color' => 'secondary'],

    //---------------------------------------------------------------------------
    // Contacts — état calculé (ordre de priorité métier)
    //---------------------------------------------------------------------------
    'contact_lifecycle_states' => [
        'unsubscribed' => ['label' => 'Désinscrit', 'color' => 'dark'],
        'blocked' => ['label' => 'Bloqué', 'color' => 'danger'],
        'bounced' => ['label' => 'Rebondi', 'color' => 'danger'],
        'invalid_email' => ['label' => 'Email incorrect', 'color' => 'danger'],
        'replied' => ['label' => 'Répondu', 'color' => 'success'],
        'contacted' => ['label' => 'Contacté', 'color' => 'primary'],
        'verified' => ['label' => 'Vérifié', 'color' => 'info'],
        'verification_pending' => ['label' => 'Vérification en cours', 'color' => 'warning'],
        'needs_verification' => ['label' => 'À vérifier', 'color' => 'secondary'],
    ],

    //---------------------------------------------------------------------------
    // Contacts — type d'e-mail
    //---------------------------------------------------------------------------
    'contact_email_kinds' => [
        'role' => ['label' => 'Professionnel', 'color' => 'warning'],
        'personal' => ['label' => 'Personnel',   'color' => 'info'],
    ],

    //---------------------------------------------------------------------------
    // Contacts — qualité de l'adresse e-mail
    //---------------------------------------------------------------------------
    'contact_email_verification_statuses' => [
        'valid' => ['label' => 'Valide', 'color' => 'success', 'risk' => false],
        'accept_all' => ['label' => 'Accept-all', 'color' => 'warning', 'risk' => true],
        'pending' => ['label' => 'Vérification en cours', 'color' => 'secondary', 'risk' => true],
        'unknown' => ['label' => 'Résultat inconnu', 'color' => 'secondary', 'risk' => true],
        'missing' => ['label' => 'Non vérifié', 'color' => 'secondary', 'risk' => true],
        'webmail' => ['label' => 'Adresse personnelle', 'color' => 'warning', 'risk' => true],
        'invalid' => ['label' => 'Invalide', 'color' => 'danger', 'risk' => true],
        'disposable' => ['label' => 'Jetable', 'color' => 'danger', 'risk' => true],
    ],

    'contact_email_verification_sources' => [
        'hunter' => 'Hunter Verifier',
        'bounce' => 'Retour de livraison',
        'recovery' => 'Historique récupéré',
    ],

    //---------------------------------------------------------------------------
    // Contacts — source d'origine
    //---------------------------------------------------------------------------
    'contact_sources' => [
        'discovered' => ['label' => 'Découverte auto', 'color' => 'info'],
        'zoho' => ['label' => 'Import Zoho',     'color' => 'primary'],
        'manual' => ['label' => 'Saisie manuelle', 'color' => 'secondary'],
    ],

    //---------------------------------------------------------------------------
    // Campagnes — type de planification
    //---------------------------------------------------------------------------
    'schedule_types' => [
        'one_shot' => ['label' => 'Ponctuel',   'color' => 'secondary'],
        'recurring' => ['label' => 'Récurrent',  'color' => 'info'],
        'paced' => ['label' => 'Envoi progressif', 'color' => 'warning'],
        'sequence' => ['label' => 'Séquence',   'color' => 'primary'],
    ],

    'campaign_email_verification_policies' => [
        'verified_only' => ['label' => 'Adresses vérifiées uniquement', 'color' => 'success'],
        'all_sendable' => ['label' => 'Toutes les adresses envoyables', 'color' => 'warning'],
    ],

    //---------------------------------------------------------------------------
    // Campagnes progressives — état d'une société
    //---------------------------------------------------------------------------
    'campaign_company_dispatch_statuses' => [
        'claimed' => ['label' => 'Réclamée', 'color' => 'info'],
        'processed' => ['label' => 'Traitée', 'color' => 'success'],
        'failed' => ['label' => 'Échec', 'color' => 'danger'],
    ],

    //---------------------------------------------------------------------------
    // Exécutions de campagne — statut
    //---------------------------------------------------------------------------
    'campaign_run_statuses' => [
        'prepared' => ['label' => 'Audience préparée', 'color' => 'warning'],
        'scheduled' => ['label' => 'Planifiée',   'color' => 'info'],
        'sending' => ['label' => 'En cours',    'color' => 'primary'],
        'sent' => ['label' => 'Envoyée',     'color' => 'success'],
        'failed' => ['label' => 'Échec',       'color' => 'danger'],
        'canceled' => ['label' => 'Annulée',     'color' => 'secondary'],
    ],

    //---------------------------------------------------------------------------
    // Découvertes — statut d'exécution
    //---------------------------------------------------------------------------
    'discovery_run_statuses' => [
        'pending' => ['label' => 'En attente', 'color' => 'secondary'],
        'running' => ['label' => 'En cours',   'color' => 'primary'],
        'completed' => ['label' => 'Terminée',   'color' => 'success'],
        'failed' => ['label' => 'Échouée',    'color' => 'danger'],
    ],

    //---------------------------------------------------------------------------
    // Destinataires de campagne — statut
    //---------------------------------------------------------------------------
    'campaign_recipient_statuses' => [
        'queued' => ['label' => 'En file',       'color' => 'secondary'],
        'sent' => ['label' => 'Envoyé',        'color' => 'info'],
        'delivered' => ['label' => 'Délivré',       'color' => 'primary'],
        'opened' => ['label' => 'Ouvert',        'color' => 'success'],
        'clicked' => ['label' => 'Cliqué',        'color' => 'success'],
        'bounced' => ['label' => 'Rejeté',        'color' => 'danger'],
        'replied' => ['label' => 'Répondu',       'color' => 'success'],
        'unsubscribed' => ['label' => 'Désinscrit',    'color' => 'warning'],
        'skipped' => ['label' => 'Ignoré',        'color' => 'dark'],
    ],

    'campaign_recipient_skip_reasons' => [
        'suppressed' => 'Contact supprimé',
        'cold_send_disabled' => 'Envoi à froid désactivé',
        'personal_email' => 'Adresse e-mail personnelle',
        'enrollment_ineligible' => 'Étape de séquence non éligible',
        'invalid_email' => 'Adresse e-mail invalide',
        'disposable_email' => 'Adresse e-mail jetable',
        'verification_required' => 'Vérification de l’adresse e-mail requise',
        'accept_all_feedback_required' => 'Retour de rebond requis pour cette adresse',
        'bounce_feedback_unhealthy' => 'Retour de rebond indisponible',
        'zoho_rejected' => 'Refus Zoho (code non vérifié)',
        'zoho_unsent' => "Non envoy\u{00E9} par Zoho \u{2014} adresse invalide",
        'run_canceled' => 'Lot annulé par l’opérateur',
    ],

    //---------------------------------------------------------------------------
    // Suppressions — motif
    //---------------------------------------------------------------------------
    'suppression_reasons' => [
        'hard_bounce' => ['label' => 'Rebond permanent', 'color' => 'danger'],
        'unsubscribe' => ['label' => 'Désinscription',   'color' => 'warning'],
        'manual' => ['label' => 'Manuel',           'color' => 'secondary'],
        'spam' => ['label' => 'Spam',             'color' => 'danger'],
        'complaint' => ['label' => 'Plainte',          'color' => 'danger'],
        'invalid_email' => ['label' => 'Adresse e-mail invalide', 'color' => 'danger'],
        'soft_bounce' => ['label' => 'Rebonds temporaires répétés', 'color' => 'danger'],
        'claimed' => ['label' => 'Adresse revendiquée', 'color' => 'danger'],
    ],

    //---------------------------------------------------------------------------
    // Suppressions — source
    //---------------------------------------------------------------------------
    'suppression_sources' => [
        'campaign' => ['label' => 'Campagne', 'color' => 'primary'],
        'sequence' => ['label' => 'Séquence', 'color' => 'info'],
        'import' => ['label' => 'Import',   'color' => 'secondary'],
        'manual' => ['label' => 'Manuel',   'color' => 'secondary'],
        'zoho' => ['label' => 'Zoho Campaigns', 'color' => 'info'],
        'dsn' => ['label' => 'Notification DSN', 'color' => 'info'],
        'hunter' => ['label' => 'Hunter', 'color' => 'info'],
    ],

    //---------------------------------------------------------------------------
    // Demandes — statut
    //---------------------------------------------------------------------------
    'demande_statuses' => [
        'pending' => ['label' => 'En attente', 'color' => 'warning'],
        'in_review' => ['label' => 'En revue',   'color' => 'primary'],
        'accepted' => ['label' => 'Acceptée',   'color' => 'success'],
        'rejected' => ['label' => 'Rejetée',    'color' => 'danger'],
    ],

    //---------------------------------------------------------------------------
    // Séquences — statut d'inscription
    //---------------------------------------------------------------------------
    'sequence_enrollment_statuses' => [
        'active' => ['label' => 'Active',     'color' => 'success'],
        'paused' => ['label' => 'En pause',   'color' => 'warning'],
        'completed' => ['label' => 'Terminée',   'color' => 'dark'],
        'stopped' => ['label' => 'Stoppée',    'color' => 'danger'],
    ],

    //---------------------------------------------------------------------------
    // Séquences — statut d'envoi par étape
    //---------------------------------------------------------------------------
    'sequence_step_statuses' => [
        'queued' => ['label' => 'En file',  'color' => 'secondary'],
        'sent' => ['label' => 'Envoyé',   'color' => 'info'],
        'opened' => ['label' => 'Ouvert',   'color' => 'success'],
        'replied' => ['label' => 'Répondu', 'color' => 'primary'],
        'bounced' => ['label' => 'Rejeté',   'color' => 'danger'],
        'skipped' => ['label' => 'Ignoré',   'color' => 'dark'],
    ],

    //---------------------------------------------------------------------------
    // Campagnes récurrentes — fréquences
    //---------------------------------------------------------------------------
    'recurrence_frequencies' => [
        'daily' => 'Quotidien',
        'weekly' => 'Hebdomadaire',
        'monthly' => 'Mensuel',
    ],

    //---------------------------------------------------------------------------
    // Segments — portée (scope)
    //---------------------------------------------------------------------------
    'segment_scopes' => [
        'prospect' => ['label' => 'Prospects', 'color' => 'info'],
        'client' => ['label' => 'Clients',   'color' => 'success'],
        'mixed' => ['label' => 'Mixte',     'color' => 'primary'],
    ],

    //---------------------------------------------------------------------------
    // Entreprises — pays (ISO-3166-1 alpha-2 => libellé français)
    // COMPLETE list so stored codes (e.g. PT, DE, US) never render blank.
    // Keys are UPPERCASE ISO-2; values are French country names.
    // Storage stays char(2) — do NOT add an `in:` validation rule tied to this list.
    //---------------------------------------------------------------------------
    'company_countries' => [
        'AF' => 'Afghanistan',
        'ZA' => 'Afrique du Sud',
        'AL' => 'Albanie',
        'DZ' => 'Algérie',
        'DE' => 'Allemagne',
        'AD' => 'Andorre',
        'AO' => 'Angola',
        'AG' => 'Antigua-et-Barbuda',
        'SA' => 'Arabie saoudite',
        'AR' => 'Argentine',
        'AM' => 'Arménie',
        'AU' => 'Australie',
        'AT' => 'Autriche',
        'AZ' => 'Azerbaïdjan',
        'BS' => 'Bahamas',
        'BH' => 'Bahreïn',
        'BD' => 'Bangladesh',
        'BB' => 'Barbade',
        'BY' => 'Biélorussie',
        'BE' => 'Belgique',
        'BZ' => 'Belize',
        'BJ' => 'Bénin',
        'BT' => 'Bhoutan',
        'BO' => 'Bolivie',
        'BA' => 'Bosnie-Herzégovine',
        'BW' => 'Botswana',
        'BR' => 'Brésil',
        'BN' => 'Brunéi',
        'BG' => 'Bulgarie',
        'BF' => 'Burkina Faso',
        'BI' => 'Burundi',
        'CV' => 'Cap-Vert',
        'KH' => 'Cambodge',
        'CM' => 'Cameroun',
        'CA' => 'Canada',
        'QA' => 'Qatar',
        'CF' => 'République centrafricaine',
        'CL' => 'Chili',
        'CN' => 'Chine',
        'CY' => 'Chypre',
        'CO' => 'Colombie',
        'KM' => 'Comores',
        'CG' => 'Congo',
        'CD' => 'Congo (RDC)',
        'KP' => 'Corée du Nord',
        'KR' => 'Corée du Sud',
        'CR' => 'Costa Rica',
        'CI' => "Côte d'Ivoire",
        'HR' => 'Croatie',
        'CU' => 'Cuba',
        'DK' => 'Danemark',
        'DJ' => 'Djibouti',
        'DM' => 'Dominique',
        'EG' => 'Égypte',
        'AE' => 'Émirats arabes unis',
        'EC' => 'Équateur',
        'ER' => 'Érythrée',
        'ES' => 'Espagne',
        'EE' => 'Estonie',
        'SZ' => 'Eswatini',
        'ET' => 'Éthiopie',
        'FJ' => 'Fidji',
        'FI' => 'Finlande',
        'FR' => 'France',
        'GA' => 'Gabon',
        'GM' => 'Gambie',
        'GE' => 'Géorgie',
        'GH' => 'Ghana',
        'GD' => 'Grenade',
        'GT' => 'Guatemala',
        'GN' => 'Guinée',
        'GW' => 'Guinée-Bissau',
        'GQ' => 'Guinée équatoriale',
        'GY' => 'Guyana',
        'HT' => 'Haïti',
        'HN' => 'Honduras',
        'HU' => 'Hongrie',
        'IN' => 'Inde',
        'ID' => 'Indonésie',
        'IQ' => 'Irak',
        'IR' => 'Iran',
        'IE' => 'Irlande',
        'IS' => 'Islande',
        'IL' => 'Israël',
        'IT' => 'Italie',
        'JM' => 'Jamaïque',
        'JP' => 'Japon',
        'JO' => 'Jordanie',
        'KZ' => 'Kazakhstan',
        'KE' => 'Kenya',
        'KG' => 'Kirghizistan',
        'KI' => 'Kiribati',
        'KW' => 'Koweït',
        'LA' => 'Laos',
        'LS' => 'Lesotho',
        'LV' => 'Lettonie',
        'LB' => 'Liban',
        'LR' => 'Libéria',
        'LY' => 'Libye',
        'LI' => 'Liechtenstein',
        'LT' => 'Lituanie',
        'LU' => 'Luxembourg',
        'MK' => 'Macédoine du Nord',
        'MG' => 'Madagascar',
        'MY' => 'Malaisie',
        'MW' => 'Malawi',
        'MV' => 'Maldives',
        'ML' => 'Mali',
        'MT' => 'Malte',
        'MA' => 'Maroc',
        'MH' => 'Îles Marshall',
        'MU' => 'Maurice',
        'MR' => 'Mauritanie',
        'MX' => 'Mexique',
        'FM' => 'Micronésie',
        'MD' => 'Moldavie',
        'MC' => 'Monaco',
        'MN' => 'Mongolie',
        'ME' => 'Monténégro',
        'MZ' => 'Mozambique',
        'MM' => 'Myanmar',
        'NA' => 'Namibie',
        'NR' => 'Nauru',
        'NP' => 'Népal',
        'NI' => 'Nicaragua',
        'NE' => 'Niger',
        'NG' => 'Nigeria',
        'NO' => 'Norvège',
        'NZ' => 'Nouvelle-Zélande',
        'OM' => 'Oman',
        'UG' => 'Ouganda',
        'UZ' => 'Ouzbékistan',
        'PK' => 'Pakistan',
        'PW' => 'Palaos',
        'PA' => 'Panama',
        'PG' => 'Papouasie-Nouvelle-Guinée',
        'PY' => 'Paraguay',
        'NL' => 'Pays-Bas',
        'PE' => 'Pérou',
        'PH' => 'Philippines',
        'PL' => 'Pologne',
        'PT' => 'Portugal',
        'DO' => 'République dominicaine',
        'CZ' => 'République tchèque',
        'RO' => 'Roumanie',
        'GB' => 'Royaume-Uni',
        'RU' => 'Russie',
        'RW' => 'Rwanda',
        'KN' => 'Saint-Kitts-et-Nevis',
        'LC' => 'Sainte-Lucie',
        'VC' => 'Saint-Vincent-et-les-Grenadines',
        'SB' => 'Îles Salomon',
        'WS' => 'Samoa',
        'SM' => 'Saint-Marin',
        'ST' => 'Sao Tomé-et-Principe',
        'SN' => 'Sénégal',
        'RS' => 'Serbie',
        'SC' => 'Seychelles',
        'SL' => 'Sierra Leone',
        'SG' => 'Singapour',
        'SK' => 'Slovaquie',
        'SI' => 'Slovénie',
        'SO' => 'Somalie',
        'SD' => 'Soudan',
        'SS' => 'Soudan du Sud',
        'LK' => 'Sri Lanka',
        'SE' => 'Suède',
        'CH' => 'Suisse',
        'SR' => 'Suriname',
        'SY' => 'Syrie',
        'TJ' => 'Tadjikistan',
        'TZ' => 'Tanzanie',
        'TD' => 'Tchad',
        'TH' => 'Thaïlande',
        'TL' => 'Timor-Leste',
        'TG' => 'Togo',
        'TO' => 'Tonga',
        'TT' => 'Trinité-et-Tobago',
        'TN' => 'Tunisie',
        'TM' => 'Turkménistan',
        'TR' => 'Turquie',
        'TV' => 'Tuvalu',
        'UA' => 'Ukraine',
        'UY' => 'Uruguay',
        'VU' => 'Vanuatu',
        'VE' => 'Venezuela',
        'VN' => 'Vietnam',
        'YE' => 'Yémen',
        'ZM' => 'Zambie',
        'ZW' => 'Zimbabwe',
    ],

    //---------------------------------------------------------------------------
    // Prospection — secteurs cibles (vocabulaire requêtes SerpAPI)
    // Valeurs libres utilisées directement dans les requêtes Google — ne pas modifier
    // sans vérifier l'impact sur la découverte existante.
    //---------------------------------------------------------------------------
    'prospect_sectors' => [
        'Transport & Logistique',
        'Agroalimentaire',
        'Industrie manufacturière',
        'Chimie & Pharmaceutique',
        'Automobile',
        'Aéronautique',
        'Textile & Habillement',
        'E-commerce',
        'Grande distribution',
        'BTP & Matériaux',
        'Énergie',
        'Électronique & High-tech',
        'Cosmétique & Parfumerie',
        'Vins & Spiritueux',
        'Machines & Équipements industriels',
        'Maritime & Portuaire',
        'Informatique',
        'Matériel médical',
        'Laboratoires pharmaceutiques',
        'Équipementiers aéronautique',
        'Instruments de mesure',
        'Dentaire',
        'Optique',
        'Matériel industriel',
        'Isolation thermique & panneaux sandwich',
        'Équipementiers automobiles',
        'Climatisation',
        'Mobilier',
        'Électroménager',
        'Lubrifiants & pétrole',
        'Traitement des eaux',
        "Matériel d'hôtellerie",
    ],

    //---------------------------------------------------------------------------
    // Prospection — postes cibles (groupés par fonction)
    // Structure group => [postes] — miroir volontaire du schéma DB futur.
    //---------------------------------------------------------------------------
    'prospect_positions' => [
        'Direction Générale' => [
            'Directeur Général',
            'PDG',
            'Gérant',
            'Directeur des Opérations',
        ],
        'Logistique & Supply Chain' => [
            'Directeur Logistique',
            'Responsable Logistique',
            'Directeur Supply Chain',
            'Responsable Supply Chain',
            'Responsable Transport',
            'Responsable Entrepôt',
        ],
        'Achats' => [
            'Directeur Achats',
            'Responsable Achats',
            'Acheteur Transport',
        ],
        'Import / Export' => [
            'Responsable Import/Export',
            'Responsable ADV',
            'Responsable Douane',
            'Commercial Export',
        ],
    ],

    //---------------------------------------------------------------------------
    // Groupes "postes recommandés" — preset quick-fill du formulaire critères.
    // Décideurs fret : exclut volontairement « Direction Générale ».
    //---------------------------------------------------------------------------
    'prospect_positions_recommended_groups' => [
        'Logistique & Supply Chain',
        'Achats',
        'Import / Export',
    ],

    //---------------------------------------------------------------------------
    // Prospection — codes pays UE-27 (ISO-3166-1 alpha-2)
    // MA délibérément absent — la sélection UE utilise une union (MA survit).
    //---------------------------------------------------------------------------
    'eu_country_codes' => [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ],

    //---------------------------------------------------------------------------
    // Prospection — tailles d'entreprise (buckets discovery)
    //---------------------------------------------------------------------------
    'company_size_buckets' => [
        '1-10' => '1–10',
        '11-50' => '11–50',
        '51-200' => '51–200',
        '201-500' => '201–500',
        '500+' => '500+',
    ],

    'prospect_batch_sources' => [
        'company_list' => ['label' => 'Liste', 'color' => 'primary'],
        'discover' => ['label' => 'Discover IA', 'color' => 'info'],
        'recovery' => ['label' => 'Récupération', 'color' => 'warning'],
    ],

    'prospect_batch_statuses' => [
        'draft' => ['label' => 'Brouillon', 'color' => 'secondary'],
        'queued' => ['label' => 'En attente', 'color' => 'info'],
        'running' => ['label' => 'En cours', 'color' => 'primary'],
        'review' => ['label' => 'À revoir', 'color' => 'warning'],
        'completed' => ['label' => 'Terminé', 'color' => 'success'],
        'failed' => ['label' => 'Échec', 'color' => 'danger'],
        'cancelled' => ['label' => 'Annulé', 'color' => 'secondary'],
    ],

    'prospect_review_reasons' => [
        'ambiguous_domain' => ['label' => 'Plusieurs domaines possibles', 'description' => 'Plusieurs sites peuvent correspondre à cette entreprise. Choisissez seulement celui dont l’identité est certaine.', 'color' => 'warning'],
        'domain_identity_conflict' => ['label' => 'Identité du domaine à confirmer', 'description' => 'Le domaine trouvé peut appartenir à une autre entreprise portant un nom proche.', 'color' => 'warning'],
        'platform_domain' => ['label' => 'Site de plateforme détecté', 'description' => 'Le résultat pointe vers un réseau social, un annuaire ou une plateforme et non vers le site officiel.', 'color' => 'danger'],
        'registrable_domain_collision' => ['label' => 'Domaine déjà associé', 'description' => 'Ce domaine ou un domaine parent est déjà rattaché à une autre entreprise dans Fretiq.', 'color' => 'warning'],
        'missing_domain' => ['label' => 'Aucun domaine fiable', 'description' => 'L’analyse automatique n’a pas trouvé de site officiel suffisamment fiable.', 'color' => 'secondary'],
        'provider_outcome_uncertain' => ['label' => 'Résultat fournisseur incertain', 'description' => 'Le fournisseur a peut-être traité la demande sans confirmer le résultat. Une relance exige votre confirmation.', 'color' => 'danger'],
        'rate_limit' => ['label' => 'Fournisseur temporairement limité', 'description' => 'Le fournisseur a demandé de ralentir. Vous pouvez relancer uniquement cette entreprise.', 'color' => 'warning'],
        'usage_limit' => ['label' => 'Limite d’utilisation du fournisseur atteinte', 'description' => 'Vérifiez le quota avant de relancer manuellement cette entreprise.', 'color' => 'warning'],
        'pagination_error' => ['label' => 'Recherche de contacts interrompue', 'description' => 'Le domaine a bien été enregistré, mais la recherche de contacts s’est arrêtée avant la fin. Vous pouvez relancer uniquement cette entreprise.', 'color' => 'warning'],
        'provider_call_not_replayable' => ['label' => 'Recherche de contacts à relancer', 'description' => 'La réponse précédente ne peut pas être reprise automatiquement. Une relance recommencera uniquement cette entreprise.', 'color' => 'warning'],
        'hunter_perfect_match' => ['label' => 'Domaine trouvé, traitement interrompu', 'description' => 'Le domaine semble cohérent, mais une étape suivante n’a pas pu se terminer.', 'color' => 'info'],
        'recovered_failed_snapshot' => ['label' => 'Domaine récupéré à confirmer', 'description' => 'Ce domaine provient d’un ancien traitement interrompu et doit être confirmé avant promotion.', 'color' => 'warning'],
        'recovered_registrable_collision' => ['label' => 'Collision récupérée à vérifier', 'description' => 'Une donnée locale récupérée partage un domaine avec une autre entreprise.', 'color' => 'warning'],
        'recovered_local_payload' => ['label' => 'Donnée locale récupérée', 'description' => 'Cette proposition vient des données locales existantes et nécessite une confirmation.', 'color' => 'info'],
    ],

    'provider_call_statuses' => [
        'reserved' => ['label' => 'Réservé', 'color' => 'secondary'],
        'running' => ['label' => 'En cours', 'color' => 'primary'],
        'pending' => ['label' => 'En attente', 'color' => 'warning'],
        'retryable' => ['label' => 'À réessayer', 'color' => 'warning'],
        'succeeded' => ['label' => 'Réussi', 'color' => 'success'],
        'failed' => ['label' => 'Échec', 'color' => 'danger'],
    ],

    //---------------------------------------------------------------------------
    // Zoho sync — statut de synchronisation
    //---------------------------------------------------------------------------
    'zoho_sync_statuses' => [
        'idle' => ['label' => 'En attente', 'color' => 'secondary'],
        'running' => ['label' => 'En cours',   'color' => 'info'],
        'success' => ['label' => 'Succès',     'color' => 'success'],
        'partial' => ['label' => 'Partiel',    'color' => 'warning'],
        'error' => ['label' => 'Erreur',     'color' => 'danger'],
    ],

    //---------------------------------------------------------------------------
    // Zoho — libellés de modules
    //---------------------------------------------------------------------------
    'zoho_module_labels' => [
        'Accounts' => 'Comptes',
        'Contacts' => 'Contacts',
        'CampaignsSentTemplates' => 'Modèles d’email envoyés',
    ],

    'zoho_readiness_statuses' => [
        'non_configure' => ['label' => 'Non configuré', 'color' => 'secondary'],
        'incomplete' => ['label' => 'Configuration incomplète', 'color' => 'warning'],
        'test_ready' => ['label' => 'Prêt pour test', 'color' => 'info'],
        'verified' => ['label' => 'Opérationnel vérifié', 'color' => 'success'],
    ],

    'zoho_driver_labels' => [
        'local' => 'Mode local',
        'zoho' => 'Zoho sélectionné',
    ],

    'zoho_token_statuses' => [
        'ok' => ['label' => 'Valide', 'color' => 'success'],
        'soon' => ['label' => 'Bientôt expiré', 'color' => 'warning'],
        'expired' => ['label' => 'Expiré', 'color' => 'danger'],
        'absent' => ['label' => 'Absent', 'color' => 'secondary'],
    ],

    //---------------------------------------------------------------------------
    // Boîte de réception
    //---------------------------------------------------------------------------
    'inbox_statuses' => [
        'nouveau' => ['label' => 'Nouveau', 'color' => 'primary'],
        'traite' => ['label' => 'Traité', 'color' => 'success'],
        'ignore' => ['label' => 'Ignoré', 'color' => 'secondary'],
    ],

];
