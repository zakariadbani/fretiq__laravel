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
}
