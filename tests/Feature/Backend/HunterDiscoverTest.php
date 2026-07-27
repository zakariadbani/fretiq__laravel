<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\ProspectCriteria;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class HunterDiscoverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->user->assignRole('superadmin');
        config(['services.hunter.driver' => 'local']);
    }

    public function test_preview_validates_permission_input_and_active_state(): void
    {
        $criteria = $this->criteria();
        $withoutPermission = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $withoutPermission->givePermissionTo(['backend.access', 'view prospect_criteria']);

        $this->actingAs($withoutPermission)->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => 'transport'])->assertForbidden();
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => ' ', 'exclude' => ' '])->assertStatus(422);
        $lock = Cache::lock('hunter-discover:'.sha1($this->user->id.':'.$criteria->id), 30);
        $lock->get();
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => 'transport'])->assertStatus(423);
        $lock->release();
        $criteria->update(['is_active' => false]);
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => 'transport'])->assertStatus(422);
    }

    public function test_preview_rejects_array_input_before_calling_provider(): void
    {
        $criteria = $this->criteria();
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key']);
        Http::fake();

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => ['not', 'a', 'string']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        Http::assertNothingSent();
    }

    public function test_view_prefills_exact_ai_target_and_exclude_escaped(): void
    {
        $criteria = $this->criteria(['ai_target' => 'Target <script>', 'ai_exclude' => 'Exclude & rivals']);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_criteria.view', $criteria));

        $response->assertOk();
        $response->assertSee('Target &lt;script&gt;', false);
        $response->assertSee('Exclude &amp; rivals', false);
    }

    public function test_import_lock_prevents_duplicate_entry(): void
    {
        $criteria = $this->criteria();
        $previewId = $this->preview($criteria, [[
            'domain' => 'locked.test',
            'organization' => 'Locked',
            'emails_count' => ['personal' => 0, 'generic' => 0, 'total' => 0],
        ]]);
        $lock = Cache::lock('hunter-discover-import:'.$previewId, 30);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->user)
                ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['preview_id' => $previewId, 'domains' => ['locked.test']])
                ->assertStatus(423);
            $this->assertDatabaseMissing('companies', ['domain' => 'locked.test']);
        } finally {
            $lock->release();
        }

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['preview_id' => $previewId, 'domains' => ['locked.test']])
            ->assertOk()
            ->assertJson(['imported' => 1]);
        $this->assertSame(1, Company::withRejected()->where('domain', 'locked.test')->count());
    }
    public function test_preview_annotates_new_current_rejected_and_other_companies(): void
    {
        $criteria = $this->criteria();
        $other = $this->criteria();
        Company::create(['criteria_id' => $criteria->id, 'domain' => 'geodis.com', 'name' => 'Keep', 'qualification_status' => 'qualified']);
        Company::create(['criteria_id' => $criteria->id, 'domain' => 'clasquin.com', 'name' => 'Rejected', 'qualification_status' => 'rejected']);
        Company::create(['criteria_id' => $other->id, 'domain' => 'bolloretransport.com', 'name' => 'Other']);

        $companies = $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => 'transport'])->assertOk()->json('companies');
        $statuses = collect($companies)->pluck('status', 'domain');

        $this->assertSame('existing_current', $statuses['geodis.com']);
        $this->assertSame('rejected_current', $statuses['clasquin.com']);
        $this->assertSame('existing_other', $statuses['bolloretransport.com']);
    }

    public function test_import_creates_reactivates_skips_and_preserves_existing_profiles(): void
    {
        $criteria = $this->criteria();
        $other = $this->criteria();
        $rejected = Company::create(['criteria_id' => $criteria->id, 'domain' => 'rejected.test', 'name' => 'Preserve', 'sector' => 'Special', 'qualification_status' => 'rejected']);
        Company::create(['criteria_id' => $other->id, 'domain' => 'other.test', 'name' => 'Other']);
        $previewId = $this->preview($criteria, [
            ['domain' => 'new.test', 'organization' => 'New Co', 'emails_count' => ['personal' => 1, 'generic' => 0, 'total' => 1]],
            ['domain' => 'rejected.test', 'organization' => 'Overwrite', 'emails_count' => ['personal' => 0, 'generic' => 0, 'total' => 0]],
            ['domain' => 'other.test', 'organization' => 'Other overwrite', 'emails_count' => ['personal' => 0, 'generic' => 0, 'total' => 0]],
        ]);

        $response = $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['preview_id' => $previewId, 'domains' => ['new.test', 'rejected.test', 'other.test']]);

        $response->assertOk()->assertJson(['imported' => 1, 'reactivated' => 1, 'skipped' => 1]);
        $this->assertStringEndsWith('#criteria_resultats', $response->json('redirect_url'));
        $this->assertDatabaseHas('companies', ['domain' => 'new.test', 'criteria_id' => $criteria->id, 'name' => 'New Co', 'relationship' => 'prospect', 'source' => 'discovered', 'qualification_status' => 'pending', 'is_active' => 1, 'discovery_query' => 'Prompt provenance']);
        $this->assertSame('Preserve', $rejected->fresh()->name);
        $this->assertSame('Special', $rejected->fresh()->sector);
        $this->assertSame('pending', $rejected->fresh()->qualification_status);
        $this->assertNull(Cache::get('hunter-discover-preview:'.$previewId));
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['preview_id' => $previewId, 'domains' => ['new.test']])->assertStatus(410);
    }

    public function test_import_rejects_expired_wrong_owner_criteria_and_non_preview_subset(): void
    {
        $criteria = $this->criteria();
        $other = $this->criteria();
        $missing = (string) Str::uuid();
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['preview_id' => $missing, 'domains' => ['x.test']])->assertStatus(410);

        $id = $this->preview($criteria, [['domain' => 'x.test', 'organization' => null, 'emails_count' => ['personal' => 0, 'generic' => 0, 'total' => 0]]]);
        $second = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]); $second->assignRole('superadmin');
        $this->actingAs($second)->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['preview_id' => $id, 'domains' => ['x.test']])->assertForbidden();
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_import', $other), ['preview_id' => $id, 'domains' => ['x.test']])->assertForbidden();
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['preview_id' => $id, 'domains' => ['not-preview.test']])->assertStatus(422);
    }

    private function criteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge(['name' => 'Hunter '.Str::random(8), 'sectors' => ['Transport'], 'countries' => ['FR'], 'company_sizes' => ['11-50'], 'daily_limit' => 10, 'is_active' => true], $overrides));
    }

    private function preview(ProspectCriteria $criteria, array $companies): string
    {
        $id = (string) Str::uuid();
        Cache::put('hunter-discover-preview:'.$id, ['user_id' => $this->user->id, 'criteria_id' => $criteria->id, 'prompt' => 'Prompt provenance', 'companies' => $companies], now()->addMinutes(15));
        return $id;
    }
}
