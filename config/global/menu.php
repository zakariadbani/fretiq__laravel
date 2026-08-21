<?php

return [

    // Main sidebar menu
    'main' => [
        [
            'content' => 'Prospection & campagnes',
            'permission' => ['backend.access', 'view campaigns', 'view prospect_criteria', 'view prospect_batches', 'view inbox'],
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
            'title' => 'Planning',
            'permission' => 'view campaigns',
            'icon' => [
                'svg' => 'calendar',
                'font' => '<i class="bi bi-calendar-week fs-2"></i>',
            ],
            'path' => 'admin/planner',
            'active_prefix' => 'admin/planner',
        ],
        [
            'title' => 'Campagnes',
            'permission' => 'view campaigns',
            'icon' => [
                'svg' => 'flash-circle',
                'font' => '<i class="bi bi-rocket fs-2"></i>',
            ],
            'path' => 'admin/campaigns',
            'active_prefix' => 'admin/campaigns',
        ],
        [
            'title' => 'Critères de découverte',
            'permission' => 'view prospect_criteria',
            'icon' => [
                'svg' => 'filter',
                'font' => '<i class="bi bi-funnel fs-2"></i>',
            ],
            'path' => 'admin/prospect_criteria',
            'active_prefix' => 'admin/prospect_criteria',
        ],
        [
            'title' => 'Lots découverte',
            'permission' => 'view prospect_batches',
            'icon' => [
                'svg' => 'parcel',
                'font' => '<i class="bi bi-box-seam fs-2"></i>',
            ],
            'path' => 'admin/prospect_batches',
            'active_prefix' => 'admin/prospect_batches',
        ],
        [
            'title' => 'Boîte de réception',
            'permission' => 'view inbox',
            'icon' => [
                'svg' => 'sms',
                'font' => '<i class="bi bi-inbox fs-2"></i>',
            ],
            'path' => 'admin/inbox',
            'active_prefix' => 'admin/inbox',
        ],
        [
            'content' => 'Répertoire',
            'permission' => ['view companies', 'view contacts'],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],
        [
            'title' => 'Entreprises',
            'permission' => 'view companies',
            'icon' => ['svg' => 'bank', 'font' => '<i class="bi bi-building fs-2"></i>'],
            'path' => 'admin/companies',
            'active_prefix' => 'admin/companies',
        ],
        [
            'title' => 'Contacts',
            'permission' => 'view contacts',
            'icon' => ['svg' => 'profile-user', 'font' => '<i class="bi bi-person-lines-fill fs-2"></i>'],
            'path' => 'admin/contacts',
            'active_prefix' => 'admin/contacts',
        ],
        [
            'content' => 'Préparation des campagnes',
            'permission' => ['view segments', 'view campaign_templates', 'view sequences', 'view sender_identities', 'view sectors'],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],
        [
            'title' => 'Segments',
            'permission' => 'view segments',
            'icon' => ['svg' => 'category', 'font' => '<i class="bi bi-diagram-3 fs-2"></i>'],
            'path' => 'admin/segments',
            'active_prefix' => 'admin/segments',
        ],
        [
            'title' => 'Modèles d’email',
            'permission' => 'view campaign_templates',
            'icon' => ['svg' => 'sms', 'font' => '<i class="bi bi-envelope-paper fs-2"></i>'],
            'path' => 'admin/campaign_templates',
            'active_prefix' => 'admin/campaign_templates',
        ],
        [
            'title' => 'Séquences',
            'permission' => 'view sequences',
            'icon' => ['svg' => 'abstract-14', 'font' => '<i class="bi bi-list-ol fs-2"></i>'],
            'path' => 'admin/sequences',
            'active_prefix' => 'admin/sequences',
        ],
        [
            'title' => 'Identités d’expéditeur',
            'permission' => 'view sender_identities',
            'icon' => ['svg' => 'user-tick', 'font' => '<i class="bi bi-person-badge fs-2"></i>'],
            'path' => 'admin/sender_identities',
            'active_prefix' => 'admin/sender_identities',
        ],
        [
            'title' => 'Secteurs',
            'permission' => 'view sectors',
            'icon' => ['svg' => 'category', 'font' => '<i class="bi bi-tags fs-2"></i>'],
            'path' => 'admin/sectors',
            'active_prefix' => 'admin/sectors',
        ],
        [
            'content' => 'Conformité & consommation',
            'permission' => ['view demandes', 'view suppressions', 'view consumption'],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],
        [
            'title' => 'Demandes',
            'permission' => 'view demandes',
            'icon' => ['svg' => 'document', 'font' => '<i class="bi bi-file-earmark-text fs-2"></i>'],
            'path' => 'admin/demandes',
            'active_prefix' => 'admin/demandes',
        ],
        [
            'title' => 'Suppressions',
            'permission' => 'view suppressions',
            'icon' => ['svg' => 'shield-cross', 'font' => '<i class="bi bi-slash-circle fs-2"></i>'],
            'path' => 'admin/suppressions',
            'active_prefix' => 'admin/suppressions',
        ],
        [
            'title' => 'Consommation',
            'permission' => 'view consumption',
            'icon' => ['svg' => 'chart-simple', 'font' => '<i class="bi bi-graph-up fs-2"></i>'],
            'path' => 'admin/consumption',
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
