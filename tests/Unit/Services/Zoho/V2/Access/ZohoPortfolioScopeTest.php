<?php

namespace Tests\Unit\Services\Zoho\V2\Access;

use App\Models\User;
use App\Models\Zoho\ZohoAccount;
use App\Models\Zoho\ZohoUserMapping;
use App\Services\Zoho\V2\Access\ZohoPortfolioScope;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoPortfolioScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        foreach (['owner-a', 'owner-b'] as $id) {
            ZohoAccount::query()->create(['zoho_id' => 'account-'.$id, 'owner_zoho_id' => $id, 'raw_payload' => [], 'payload_hash' => hash('sha256', $id)]);
        }
    }

    public function test_commercial_is_limited_to_its_single_confirmed_owner_mapping(): void
    {
        $commercial = User::factory()->create();
        $commercial->assignRole('commercial');
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $commercial->id, 'is_confirmed' => true]);

        $result = app(ZohoPortfolioScope::class)->apply(ZohoAccount::query(), $commercial);

        $this->assertFalse($result->mappingRequired);
        $this->assertSame(['account-owner-a'], $result->query->pluck('zoho_id')->all());
    }

    public function test_unmapped_commercial_receives_an_empty_mapping_required_scope(): void
    {
        $commercial = User::factory()->create();
        $commercial->assignRole('commercial');

        $result = app(ZohoPortfolioScope::class)->apply(ZohoAccount::query(), $commercial);

        $this->assertTrue($result->mappingRequired);
        $this->assertSame(0, $result->query->count());
    }

    public function test_admin_has_organization_scope(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $result = app(ZohoPortfolioScope::class)->apply(ZohoAccount::query(), $admin);

        $this->assertFalse($result->mappingRequired);
        $this->assertSame(2, $result->query->count());
    }

    public function test_multiple_confirmed_mappings_fail_closed(): void
    {
        $commercial = User::factory()->create();
        $commercial->assignRole('commercial');
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $commercial->id, 'is_confirmed' => true]);
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-b', 'fretiq_user_id' => $commercial->id, 'is_confirmed' => true]);

        $result = app(ZohoPortfolioScope::class)->apply(ZohoAccount::query(), $commercial);

        $this->assertTrue($result->mappingRequired);
        $this->assertSame(0, $result->query->count());
    }
}
