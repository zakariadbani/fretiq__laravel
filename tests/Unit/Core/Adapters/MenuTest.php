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
        $this->assertSame([
            'Prospection & campagnes',
            'Zoho CRM — Lecture seule',
            'Administration',
        ], $main->pluck('content')->filter()->values()->all());
        $this->assertSame([
            'Prospection & campagnes',
            'Vue d’ensemble',
            'Campagnes',
            'Planning',
            'Découverte',
            'Répertoire',
            'Préparation des campagnes',
            'Réponses & demandes',
            'Conformité & consommation',
            'Zoho CRM — Lecture seule',
            'Tableau de bord Zoho',
            'Données CRM',
            'Synchronisation Zoho',
            'Administration',
            'Utilisateurs & accès',
            'Configuration',
            'Supervision',
        ], $main->map(fn (array $item): string => $item['content'] ?? $item['title'])->all());

        $expectedAccordions = [
            'Découverte' => [
                ['Critères de découverte', 'view prospect_criteria', 'admin/prospect_criteria'],
                ['Lots', 'view prospect_batches', 'admin/prospect_batches'],
            ],
            'Répertoire' => [
                ['Entreprises', 'view companies', 'admin/companies'],
                ['Contacts', 'view contacts', 'admin/contacts'],
            ],
            'Préparation des campagnes' => [
                ['Segments', 'view segments', 'admin/segments'],
                ['Modèles d’email', 'view campaign_templates', 'admin/campaign_templates'],
                ['Séquences', 'view sequences', 'admin/sequences'],
                ['Identités d’expéditeur', 'view sender_identities', 'admin/sender_identities'],
            ],
            'Réponses & demandes' => [
                ['Boîte de réception', 'view inbox', 'admin/inbox'],
                ['Demandes', 'view demandes', 'admin/demandes'],
            ],
            'Conformité & consommation' => [
                ['Suppressions', 'view suppressions', 'admin/suppressions'],
                ['Consommation', 'view consumption', 'admin/consumption'],
            ],
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
        $prospects = $main->firstWhere('title', 'Répertoire');

        $descendantHtml = $this->renderMenuAt('/admin/companies/1/edit', [$prospects]);
        $this->assertStringContainsString('menu-item here show menu-accordion', $descendantHtml);

        $nearPrefixHtml = $this->renderMenuAt('/admin/companies-archive/1/edit', [$prospects]);
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
