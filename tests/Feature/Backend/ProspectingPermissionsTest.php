<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Services\Prospecting\ProspectBatchService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProspectingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_can_create_run_and_review_but_not_delete_or_view_provider_activity(): void
    {
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        Queue::fake();
        $commercial = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $commercial->assignRole('commercial');
        $batch = app(ProspectBatchService::class)->createListBatch($commercial, [[
            'row_number' => 1,
            'original_input' => 'ACME',
            'company_name' => 'ACME',
            'country' => 'FR',
        ]]);
        app(ProspectBatchService::class)->estimate($batch);

        $this->actingAs($commercial)->get(route('admin.prospect_batches.create'))->assertOk();
        $this->actingAs($commercial)->postJson(route('admin.prospect_batches.confirm', $batch), ['confirm_cost' => true])->assertOk();
        $this->actingAs($commercial)->get(route('admin.prospect_review.index'))->assertOk();
        $this->actingAs($commercial)->delete(route('admin.prospect_batches.delete', $batch))->assertForbidden();
        $this->actingAs($commercial)->get(route('admin.provider_activity.index'))->assertForbidden();

        $this->assertTrue($commercial->can('verify contacts'));
        $this->assertFalse($commercial->can('view provider activity'));
        $this->assertFalse($commercial->can('view provider quota'));

        $admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole('admin');
        $this->assertTrue($admin->can('view provider activity'));
        $this->assertFalse($admin->can('view provider quota'));
        $this->actingAs($admin)->get(route('admin.provider_activity.index'))->assertOk();
    }
}
