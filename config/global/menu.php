<?php

return [

    // Main sidebar menu
    'main' => [

        //---------------------------------------------------------------------------
        // Tableau de bord
        //---------------------------------------------------------------------------
        [
            'title' => 'Tableau de bord',
            'permission' => 'backend.access',
            'icon' => [
                'svg' => 'element-11',
                'font' => '<i class="bi bi-speedometer2 fs-2"></i>',
            ],
            'path' => 'admin/dashboard',
        ],

        //---------------------------------------------------------------------------
        // Section : Prospection
        //---------------------------------------------------------------------------
        [
            'content' => 'Prospection',
            'permission' => ['view companies', 'view contacts'],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],

        [
            'title' => 'Entreprises',
            'permission' => 'view companies',
            'icon' => [
                'svg' => 'briefcase',
                'font' => '<i class="bi bi-building fs-2"></i>',
            ],
            'path' => 'admin/companies',
        ],

        [
            'title' => 'Contacts',
            'permission' => 'view contacts',
            'icon' => [
                'svg' => 'address-book',
                'font' => '<i class="bi bi-people fs-2"></i>',
            ],
            'path' => 'admin/contacts',
        ],

        [
            'title' => 'Critères de découverte',
            'permission' => 'view prospect_criteria',
            'icon' => [
                'svg' => 'filter-search',
                'font' => '<i class="bi bi-funnel-fill fs-2"></i>',
            ],
            'path' => 'admin/prospect_criteria',
        ],

        //---------------------------------------------------------------------------
        // Section : Campagnes
        //---------------------------------------------------------------------------
        [
            'content' => 'Campagnes',
            'permission' => ['view campaigns', 'view campaign_templates', 'view segments'],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],

        [
            'title' => 'Segments',
            'permission' => 'view segments',
            'icon' => [
                'svg' => 'filter',
                'font' => '<i class="bi bi-funnel fs-2"></i>',
            ],
            'path' => 'admin/segments',
        ],

        [
            'title' => "Modèles d'email",
            'permission' => 'view campaign_templates',
            'icon' => [
                'svg' => 'message-text',
                'font' => '<i class="bi bi-envelope-paper fs-2"></i>',
            ],
            'path' => 'admin/campaign_templates',
        ],

        [
            'title' => 'Séquences',
            'permission' => 'view sequences',
            'icon' => [
                'svg' => 'abstract-14',
                'font' => '<i class="bi bi-list-ol fs-2"></i>',
            ],
            'path' => 'admin/sequences',
        ],

        [
            'title' => 'Campagnes',
            'permission' => 'view campaigns',
            'icon' => [
                'svg' => 'flash-circle',
                'font' => '<i class="bi bi-rocket fs-2"></i>',
            ],
            'path' => 'admin/campaigns',
        ],

        [
            'title' => 'Planning',
            'permission' => 'view campaigns',
            'icon' => [
                'svg' => 'calendar',
                'font' => '<i class="bi bi-calendar3 fs-2"></i>',
            ],
            'path' => 'admin/planner',
        ],

        //---------------------------------------------------------------------------
        // Section : Suivi
        //---------------------------------------------------------------------------
        [
            'content' => 'Suivi',
            'permission' => ['view demandes', 'view suppressions'],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],

        [
            'title' => 'Demandes',
            'permission' => 'view demandes',
            'icon' => [
                'svg' => 'document',
                'font' => '<i class="bi bi-file-earmark-text fs-2"></i>',
            ],
            'path' => 'admin/demandes',
        ],

        [
            'title' => 'Suppressions',
            'permission' => 'view suppressions',
            'icon' => [
                'svg' => 'cross-circle',
                'font' => '<i class="bi bi-shield-x fs-2"></i>',
            ],
            'path' => 'admin/suppressions',
        ],

        //---------------------------------------------------------------------------
        // Section : Administration
        //---------------------------------------------------------------------------
        [
            'content' => 'Administration',
            'permission' => ['view users', 'manage roles', 'manage permissions', 'view sender_identities', 'manage packages', 'view settings'],
            'classes' => ['content' => 'pt-8 pb-2'],
        ],

        [
            'title' => 'Packs',
            'permission' => 'manage packages',
            'icon' => [
                'svg' => 'abstract-26',
                'font' => '<i class="bi bi-box-seam fs-2"></i>',
            ],
            'path' => 'admin/packages',
        ],

        [
            'title' => 'Utilisateurs',
            'permission' => 'view users',
            'icon' => [
                'svg' => 'profile-user',
                'font' => '<i class="bi bi-person-gear fs-2"></i>',
            ],
            'path' => 'admin/users',
        ],

        [
            'title' => 'Rôles',
            'permission' => 'manage roles',
            'icon' => [
                'svg' => 'shield-tick',
                'font' => '<i class="bi bi-shield-check fs-2"></i>',
            ],
            'path' => 'admin/user-management/roles',
        ],

        [
            'title' => 'Permissions',
            'permission' => 'manage permissions',
            'icon' => [
                'svg' => 'lock',
                'font' => '<i class="bi bi-key fs-2"></i>',
            ],
            'path' => 'admin/user-management/permissions',
        ],

        [
            'title' => "Identités d'expéditeur",
            'permission' => 'view sender_identities',
            'icon' => [
                'svg' => 'messages',
                'font' => '<i class="bi bi-person-lines-fill fs-2"></i>',
            ],
            'path' => 'admin/sender_identities',
        ],

        [
            'title' => 'Zoho',
            'permission' => 'view zoho',
            'icon' => [
                'svg' => 'cloud',
                'font' => '<i class="bi bi-cloud fs-2"></i>',
            ],
            'path' => 'admin/zoho',
        ],

        [
            'title' => 'Observabilité',
            'permission' => 'manage roles',
            'icon' => [
                'svg' => 'abstract-26',
                'font' => '<i class="bi bi-activity fs-2"></i>',
            ],
            'path' => 'admin/observability',
        ],

        [
            'title' => 'Paramètres',
            'permission' => 'view settings',
            'icon' => [
                'svg' => 'setting-2',
                'font' => '<i class="bi bi-gear fs-2"></i>',
            ],
            'path' => 'admin/settings',
        ],

    ],

    // Horizontal menu (unused for now)
    'horizontal' => [],

];
