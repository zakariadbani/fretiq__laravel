<?php

namespace Tests\Unit\Core\Adapters;

use App\Core\Adapters\Menu;
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

    public function test_zoho_navigation_is_a_permission_filtered_administration_group(): void
    {
        $zoho = collect(config('global.menu.main'))
            ->firstWhere('title', 'Zoho');

        $this->assertSame(['view zoho', 'view marketing dashboard', 'view zoho records'], $zoho['permission']);
        $this->assertSame('menu-accordion', $zoho['classes']['item']);
        $this->assertSame('click', $zoho['attributes']['item']['data-kt-menu-trigger']);
        $this->assertSame([
            ['Synchronisation', 'view zoho', 'admin/zoho'],
            ['Marketing & commercial', 'view marketing dashboard', 'admin/dashboard/marketing'],
            ['Explorateur CRM', 'view zoho records', 'admin/zoho/records/leads'],
        ], collect($zoho['sub'])->map(fn (array $item): array => [$item['title'], $item['permission'], $item['path']])->all());
        $this->assertFalse(collect(config('global.menu.main'))->contains('title', 'Marketing & commercial'));
    }
}
