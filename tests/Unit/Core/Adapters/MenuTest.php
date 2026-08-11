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
        $this->assertSame([
            'Prospection & campagnes',
            'Zoho CRM — Lecture seule',
        ], $main->pluck('content')->filter()->values()->take(2)->all());

        $expectedAccordions = [
            'Centre de prospection' => [
                ['Vue d’ensemble', 'view prospect_batches', 'admin/prospecting'],
                ['Lots', 'view prospect_batches', 'admin/prospect_batches'],
                ['À revoir', 'review prospect matches', 'admin/prospect-review'],
                ['Entreprises', 'view companies', 'admin/companies'],
                ['Contacts', 'view contacts', 'admin/contacts'],
                ['Critères de découverte', 'view prospect_criteria', 'admin/prospect_criteria'],
            ],
            'Campagnes' => [
                ['Campagnes', 'view campaigns', 'admin/campaigns'],
                ['Planning', 'view campaigns', 'admin/planner'],
                ['Séquences', 'view sequences', 'admin/sequences'],
                ['Segments', 'view segments', 'admin/segments'],
                ['Modèles d’email', 'view campaign_templates', 'admin/campaign_templates'],
                ['Identités d’expéditeur', 'view sender_identities', 'admin/sender_identities'],
            ],
            'Suivi' => [
                ['Demandes', 'view demandes', 'admin/demandes'],
                ['Boîte de réception', 'view inbox', 'admin/inbox'],
                ['Suppressions', 'view suppressions', 'admin/suppressions'],
                ['Consommation', 'view consumption', 'admin/consumption'],
            ],
            'Administration' => [
                ['Utilisateurs', 'view users', 'admin/users'],
                ['Rôles', 'manage roles', 'admin/user-management/roles'],
                ['Permissions', 'manage permissions', 'admin/user-management/permissions'],
                ['Paramètres', 'view settings', 'admin/settings'],
                ['Observabilité', 'manage roles', 'admin/observability'],
                ['Packs', 'manage packages', 'admin/packages'],
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
    }

    public function test_active_prefix_opens_descendant_accordions_without_matching_nearby_paths(): void
    {
        $main = collect(config('global.menu.main'));
        $prospects = $main->firstWhere('title', 'Centre de prospection');

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
