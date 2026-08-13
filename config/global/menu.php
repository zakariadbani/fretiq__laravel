<?php

return [

    // Main sidebar menu
    'main' => [
        [
            'content' => 'Prospection & campagnes',
            'permission' => [
                'backend.access', 'view companies', 'view contacts', 'view prospect_criteria',
                'view prospect_batches', 'review prospect matches',
                'view campaigns', 'view sequences', 'view segments', 'view campaign_templates',
                'view sender_identities', 'view demandes', 'view inbox', 'view suppressions', 'view consumption',
            ],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],
        [
            'title' => 'Vue d’ensemble',
            'permission' => 'backend.access',
            'icon' => [
                'svg' => 'element-11',
                'font' => '<i class="bi bi-speedometer2 fs-2"></i>',
            ],
            'path' => 'admin/dashboard',
        ],
        [
            'title' => 'Découverte',
            'permission' => ['view prospect_batches', 'review prospect matches', 'view prospect_criteria'],
            'classes' => ['item' => 'menu-accordion'],
            'attributes' => ['item' => ['data-kt-menu-trigger' => 'click']],
            'icon' => [
                'svg' => 'briefcase',
                'font' => '<i class="bi bi-building fs-2"></i>',
            ],
            'sub' => [
                ['title' => 'Vue d’ensemble', 'permission' => 'view prospect_batches', 'path' => 'admin/prospecting'],
                ['title' => 'Lots', 'permission' => 'view prospect_batches', 'path' => 'admin/prospect_batches', 'active_prefix' => 'admin/prospect_batches'],
                ['title' => 'À revoir', 'permission' => 'review prospect matches', 'path' => 'admin/prospect-review', 'active_prefix' => 'admin/prospect-review'],
                ['title' => 'Critères de découverte', 'permission' => 'view prospect_criteria', 'path' => 'admin/prospect_criteria', 'active_prefix' => 'admin/prospect_criteria'],
            ],
        ],
        [
            'title' => 'Répertoire',
            'permission' => ['view companies', 'view contacts'],
            'classes' => ['item' => 'menu-accordion'],
            'attributes' => ['item' => ['data-kt-menu-trigger' => 'click']],
            'icon' => [
                'svg' => 'address-book',
                'font' => '<i class="bi bi-people fs-2"></i>',
            ],
            'sub' => [
                ['title' => 'Entreprises', 'permission' => 'view companies', 'path' => 'admin/companies', 'active_prefix' => 'admin/companies'],
                ['title' => 'Contacts', 'permission' => 'view contacts', 'path' => 'admin/contacts', 'active_prefix' => 'admin/contacts'],
            ],
        ],
        [
            'title' => 'Campagnes & planning',
            'permission' => ['view campaigns'],
            'classes' => ['item' => 'menu-accordion'],
            'attributes' => ['item' => ['data-kt-menu-trigger' => 'click']],
            'icon' => [
                'svg' => 'flash-circle',
                'font' => '<i class="bi bi-rocket fs-2"></i>',
            ],
            'sub' => [
                ['title' => 'Campagnes', 'permission' => 'view campaigns', 'path' => 'admin/campaigns', 'active_prefix' => 'admin/campaigns'],
                ['title' => 'Planning', 'permission' => 'view campaigns', 'path' => 'admin/planner'],
            ],
        ],
        [
            'title' => 'Préparation des campagnes',
            'permission' => ['view sequences', 'view segments', 'view campaign_templates', 'view sender_identities'],
            'classes' => ['item' => 'menu-accordion'],
            'attributes' => ['item' => ['data-kt-menu-trigger' => 'click']],
            'icon' => [
                'svg' => 'abstract-14',
                'font' => '<i class="bi bi-list-ol fs-2"></i>',
            ],
            'sub' => [
                ['title' => 'Séquences', 'permission' => 'view sequences', 'path' => 'admin/sequences', 'active_prefix' => 'admin/sequences'],
                ['title' => 'Segments', 'permission' => 'view segments', 'path' => 'admin/segments', 'active_prefix' => 'admin/segments'],
                ['title' => 'Modèles d’email', 'permission' => 'view campaign_templates', 'path' => 'admin/campaign_templates', 'active_prefix' => 'admin/campaign_templates'],
                ['title' => 'Identités d’expéditeur', 'permission' => 'view sender_identities', 'path' => 'admin/sender_identities', 'active_prefix' => 'admin/sender_identities'],
            ],
        ],
        [
            'title' => 'Réponses & demandes',
            'permission' => ['view demandes', 'view inbox'],
            'classes' => ['item' => 'menu-accordion'],
            'attributes' => ['item' => ['data-kt-menu-trigger' => 'click']],
            'icon' => [
                'svg' => 'document',
                'font' => '<i class="bi bi-file-earmark-text fs-2"></i>',
            ],
            'sub' => [
                ['title' => 'Demandes', 'permission' => 'view demandes', 'path' => 'admin/demandes', 'active_prefix' => 'admin/demandes'],
                ['title' => 'Boîte de réception', 'permission' => 'view inbox', 'path' => 'admin/inbox', 'active_prefix' => 'admin/inbox'],
            ],
        ],
        [
            'title' => 'Conformité & consommation',
            'permission' => ['view suppressions', 'view consumption'],
            'classes' => ['item' => 'menu-accordion'],
            'attributes' => ['item' => ['data-kt-menu-trigger' => 'click']],
            'icon' => [
                'svg' => 'shield-tick',
                'font' => '<i class="bi bi-shield-check fs-2"></i>',
            ],
            'sub' => [
                ['title' => 'Suppressions', 'permission' => 'view suppressions', 'path' => 'admin/suppressions', 'active_prefix' => 'admin/suppressions'],
                ['title' => 'Consommation', 'permission' => 'view consumption', 'path' => 'admin/consumption'],
            ],
        ],

        [
            'content' => 'Zoho CRM — Lecture seule',
            'permission' => ['view marketing dashboard', 'view zoho records', 'view zoho'],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],
        [
            'title' => 'Tableau de bord Zoho',
            'permission' => 'view marketing dashboard',
            'icon' => [
                'svg' => 'element-11',
                'font' => '<i class="bi bi-speedometer2 fs-2"></i>',
            ],
            'path' => 'admin/dashboard/marketing',
        ],
        [
            'title' => 'Données CRM',
            'permission' => 'view zoho records',
            'icon' => [
                'svg' => 'address-book',
                'font' => '<i class="bi bi-people fs-2"></i>',
            ],
            'path' => 'admin/zoho/records/leads',
            'active_prefix' => 'admin/zoho/records',
        ],
        [
            'title' => 'Synchronisation Zoho',
            'permission' => 'view zoho',
            'icon' => [
                'svg' => 'cloud',
                'font' => '<i class="bi bi-cloud fs-2"></i>',
            ],
            'path' => 'admin/zoho',
        ],

        [
            'content' => 'Administration',
            'permission' => [
                'view users', 'manage roles', 'manage permissions', 'view settings',
                'manage packages', 'view provider quota', 'view provider activity',
            ],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],
        [
            'title' => 'Utilisateurs & accès',
            'permission' => ['view users', 'manage roles', 'manage permissions'],
            'classes' => ['item' => 'menu-accordion'],
            'attributes' => ['item' => ['data-kt-menu-trigger' => 'click']],
            'icon' => [
                'svg' => 'profile-user',
                'font' => '<i class="bi bi-person-gear fs-2"></i>',
            ],
            'sub' => [
                ['title' => 'Utilisateurs', 'permission' => 'view users', 'path' => 'admin/users', 'active_prefix' => 'admin/users'],
                ['title' => 'Rôles', 'permission' => 'manage roles', 'path' => 'admin/user-management/roles', 'active_prefix' => 'admin/user-management/roles'],
                ['title' => 'Permissions', 'permission' => 'manage permissions', 'path' => 'admin/user-management/permissions', 'active_prefix' => 'admin/user-management/permissions'],
            ],
        ],
        [
            'title' => 'Configuration',
            'permission' => ['view settings', 'manage packages'],
            'classes' => ['item' => 'menu-accordion'],
            'attributes' => ['item' => ['data-kt-menu-trigger' => 'click']],
            'icon' => [
                'svg' => 'setting-2',
                'font' => '<i class="bi bi-gear fs-2"></i>',
            ],
            'sub' => [
                ['title' => 'Paramètres', 'permission' => 'view settings', 'path' => 'admin/settings'],
                ['title' => 'Packs', 'permission' => 'manage packages', 'path' => 'admin/packages', 'active_prefix' => 'admin/packages'],
            ],
        ],
        [
            'title' => 'Supervision',
            'permission' => ['manage roles', 'view provider quota', 'view provider activity'],
            'classes' => ['item' => 'menu-accordion'],
            'attributes' => ['item' => ['data-kt-menu-trigger' => 'click']],
            'icon' => [
                'svg' => 'abstract-26',
                'font' => '<i class="bi bi-activity fs-2"></i>',
            ],
            'sub' => [
                ['title' => 'Observabilité', 'permission' => 'manage roles', 'path' => 'admin/observability'],
                ['title' => 'Quota fournisseurs', 'permission' => 'view provider quota', 'path' => 'admin/provider-quota'],
                ['title' => 'Activité fournisseurs', 'permission' => 'view provider activity', 'path' => 'admin/provider-activity'],
            ],
        ],
    ],

    // Horizontal menu (unused for now)
    'horizontal' => [],

];
