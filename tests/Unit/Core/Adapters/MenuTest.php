<?php

namespace Tests\Unit\Core\Adapters;

use App\Core\Adapters\Menu;
use Illuminate\Http\Request;
use Tests\TestCase;

class MenuTest extends TestCase
{
    public function test_feature_gated_items_and_empty_parents_are_removed_until_enabled(): void
    {
        config()->set('test-menu.marketing', false);
        $disabled = [[
            'title' => 'Intelligence',
            'sub' => [[
                'title' => 'Marketing',
                'feature' => 'test-menu.marketing',
                'path' => 'admin/dashboard/marketing',
            ]],
        ]];

        Menu::filterMenuPermissions($disabled);

        $this->assertSame([], $disabled);

        config()->set('test-menu.marketing', true);
        $enabled = [[
            'title' => 'Intelligence',
            'sub' => [[
                'title' => 'Marketing',
                'feature' => 'test-menu.marketing',
                'path' => 'admin/dashboard/marketing',
            ]],
        ]];

        Menu::filterMenuPermissions($enabled);

        $this->assertSame('Marketing', $enabled[0]['sub'][0]['title']);
    }

    public function test_sidebar_is_grouped_by_product_area_and_administration(): void
    {
        $main = collect(config('global.menu.main'));

        $this->assertSame('admin/dashboard', $main->firstWhere('title', 'Vue d’ensemble')['path']);
        $this->assertSame('backend.access', $main->firstWhere('title', 'Vue d’ensemble')['permission']);
        $this->assertSame('admin/campaigns', $main->firstWhere('title', 'Campagnes')['path']);
        $this->assertSame('view campaigns', $main->firstWhere('title', 'Campagnes')['permission']);
        $this->assertSame('admin/campaigns', $main->firstWhere('title', 'Campagnes')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Campagnes'));
        $this->assertSame('admin/planner', $main->firstWhere('title', 'Planning')['path']);
        $this->assertSame('view campaigns', $main->firstWhere('title', 'Planning')['permission']);
        $this->assertSame('admin/planner', $main->firstWhere('title', 'Planning')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Planning'));
        $this->assertSame('admin/prospect_criteria', $main->firstWhere('title', 'Critères de découverte')['path']);
        $this->assertSame('view prospect_criteria', $main->firstWhere('title', 'Critères de découverte')['permission']);
        $this->assertSame('admin/prospect_criteria', $main->firstWhere('title', 'Critères de découverte')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Critères de découverte'));
        $this->assertSame('admin/prospect_batches', $main->firstWhere('title', 'Lots découverte')['path']);
        $this->assertSame('view prospect_batches', $main->firstWhere('title', 'Lots découverte')['permission']);
        $this->assertSame('admin/prospect_batches', $main->firstWhere('title', 'Lots découverte')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Lots découverte'));
        $this->assertSame([
            'Prospection & campagnes',
            'Répertoire',
            'Préparation des campagnes',
            'Conformité & consommation',
            'Zoho CRM — Lecture seule',
            'Administration',
        ], $main->pluck('content')->filter()->values()->all());
        $this->assertSame([
            'Prospection & campagnes',
            'Vue d’ensemble',
            'Planning',
            'Campagnes',
            'Critères de découverte',
            'Lots découverte',
            'Boîte de réception',
            'Répertoire',
            'Entreprises',
            'Contacts',
            'Préparation des campagnes',
            'Segments',
            'Modèles d’email',
            'Séquences',
            'Identités d’expéditeur',
            'Secteurs',
            'Conformité & consommation',
            'Demandes',
            'Suppressions',
            'Consommation',
            'Zoho CRM — Lecture seule',
            'Tableau de bord Zoho',
            'Données CRM',
            'Synchronisation Zoho',
            'Administration',
            'Utilisateurs & accès',
            'Configuration',
            'Supervision',
        ], $main->map(fn (array $item): string => $item['content'] ?? $item['title'])->all());

        $this->assertSame('admin/inbox', $main->firstWhere('title', 'Boîte de réception')['path']);
        $this->assertSame('view inbox', $main->firstWhere('title', 'Boîte de réception')['permission']);
        $this->assertSame('admin/inbox', $main->firstWhere('title', 'Boîte de réception')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Boîte de réception'));

        $this->assertSame('admin/companies', $main->firstWhere('title', 'Entreprises')['path']);
        $this->assertSame('view companies', $main->firstWhere('title', 'Entreprises')['permission']);
        $this->assertSame('admin/companies', $main->firstWhere('title', 'Entreprises')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Entreprises'));

        $this->assertSame('admin/contacts', $main->firstWhere('title', 'Contacts')['path']);
        $this->assertSame('view contacts', $main->firstWhere('title', 'Contacts')['permission']);
        $this->assertSame('admin/contacts', $main->firstWhere('title', 'Contacts')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Contacts'));

        $this->assertSame('admin/segments', $main->firstWhere('title', 'Segments')['path']);
        $this->assertSame('view segments', $main->firstWhere('title', 'Segments')['permission']);
        $this->assertSame('admin/segments', $main->firstWhere('title', 'Segments')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Segments'));

        $this->assertSame('admin/campaign_templates', $main->firstWhere('title', 'Modèles d’email')['path']);
        $this->assertSame('view campaign_templates', $main->firstWhere('title', 'Modèles d’email')['permission']);
        $this->assertSame('admin/campaign_templates', $main->firstWhere('title', 'Modèles d’email')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Modèles d’email'));

        $this->assertSame('admin/sequences', $main->firstWhere('title', 'Séquences')['path']);
        $this->assertSame('view sequences', $main->firstWhere('title', 'Séquences')['permission']);
        $this->assertSame('admin/sequences', $main->firstWhere('title', 'Séquences')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Séquences'));

        $this->assertSame('admin/sender_identities', $main->firstWhere('title', 'Identités d’expéditeur')['path']);
        $this->assertSame('view sender_identities', $main->firstWhere('title', 'Identités d’expéditeur')['permission']);
        $this->assertSame('admin/sender_identities', $main->firstWhere('title', 'Identités d’expéditeur')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Identités d’expéditeur'));

        $this->assertSame('admin/sectors', $main->firstWhere('title', 'Secteurs')['path']);
        $this->assertSame('view sectors', $main->firstWhere('title', 'Secteurs')['permission']);
        $this->assertSame('admin/sectors', $main->firstWhere('title', 'Secteurs')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Secteurs'));

        $this->assertSame('admin/demandes', $main->firstWhere('title', 'Demandes')['path']);
        $this->assertSame('view demandes', $main->firstWhere('title', 'Demandes')['permission']);
        $this->assertSame('admin/demandes', $main->firstWhere('title', 'Demandes')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Demandes'));

        $this->assertSame('admin/suppressions', $main->firstWhere('title', 'Suppressions')['path']);
        $this->assertSame('view suppressions', $main->firstWhere('title', 'Suppressions')['permission']);
        $this->assertSame('admin/suppressions', $main->firstWhere('title', 'Suppressions')['active_prefix']);
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Suppressions'));

        $this->assertSame('admin/consumption', $main->firstWhere('title', 'Consommation')['path']);
        $this->assertSame('view consumption', $main->firstWhere('title', 'Consommation')['permission']);
        $this->assertArrayNotHasKey('active_prefix', $main->firstWhere('title', 'Consommation'));
        $this->assertArrayNotHasKey('sub', $main->firstWhere('title', 'Consommation'));

        $expectedAccordions = [
            'Utilisateurs & accès' => [
                ['Utilisateurs', 'view users', 'admin/users'],
                ['Rôles', 'manage roles', 'admin/user-management/roles'],
                ['Permissions', 'manage permissions', 'admin/user-management/permissions'],
            ],
            'Configuration' => [
                ['Paramètres', 'view settings', 'admin/settings'],
                ['Packs', 'manage packages', 'admin/packages'],
            ],
            'Supervision' => [
                ['Observabilité', 'manage roles', 'admin/observability'],
                ['Quota fournisseurs', 'view provider quota', 'admin/provider-quota'],
                ['Activité fournisseurs', 'view provider activity', 'admin/provider-activity'],
            ],
        ];

        foreach ($expectedAccordions as $title => $expectedItems) {
            $accordion = $main->firstWhere('title', $title);

            $this->assertSame('menu-accordion', $accordion['classes']['item']);
            $this->assertSame('click', $accordion['attributes']['item']['data-kt-menu-trigger']);
            $this->assertSame(array_values(array_unique(collect($expectedItems)->pluck(1)->all())), $accordion['permission']);
            $this->assertSame(
                $expectedItems,
                collect($accordion['sub'])->map(fn (array $item): array => [$item['title'], $item['permission'], $item['path']])->all()
            );
        }

        $this->assertSame([
            ['Tableau de bord Zoho', 'view marketing dashboard', 'admin/dashboard/marketing'],
            ['Données CRM', 'view zoho records', 'admin/zoho/records/leads'],
            ['Synchronisation Zoho', 'view zoho', 'admin/zoho'],
        ], $main->filter(fn (array $item): bool => isset($item['title']) && in_array($item['title'], ['Tableau de bord Zoho', 'Données CRM', 'Synchronisation Zoho'], true))
            ->map(fn (array $item): array => [$item['title'], $item['permission'], $item['path']])
            ->values()
            ->all());
        $this->assertFalse($main->contains('title', 'Zoho'));
        $this->assertFalse($main->contains('title', 'Administration'));
    }

    public function test_active_prefix_opens_descendant_accordions_without_matching_nearby_paths(): void
    {
        $main = collect(config('global.menu.main'));
        $accessGroup = $main->firstWhere('title', 'Utilisateurs & accès');

        $descendantHtml = $this->renderMenuAt('/admin/users/1/edit', [$accessGroup]);
        $this->assertStringContainsString('menu-item here show menu-accordion', $descendantHtml);

        $nearPrefixHtml = $this->renderMenuAt('/admin/users-archive/1/edit', [$accessGroup]);
        $this->assertStringNotContainsString('menu-item here show menu-accordion', $nearPrefixHtml);

        $dashboardItems = $main->whereIn('title', ['Vue d’ensemble', 'Tableau de bord Zoho'])->values()->all();
        $marketingHtml = $this->renderMenuAt('/admin/dashboard/marketing', $dashboardItems);
        $this->assertMatchesRegularExpression(
            '/class="menu-link active"\s+href="'.preg_quote(url('admin/dashboard/marketing'), '/').'"/',
            $marketingHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="menu-link active"\s+href="'.preg_quote(url('admin/dashboard'), '/').'"/',
            $marketingHtml
        );
    }

    private function renderMenuAt(string $path, array $items): string
    {
        $this->app->instance('request', Request::create($path));

        return (new Menu($items))->build();
    }
}
