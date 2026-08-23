<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SegmentPinEndpointTest — HTTP surface for pin/unpin/search endpoints.
 *
 * Routes under test:
 *   POST   /admin/segments/{id}/contacts         → pinContact
 *   DELETE /admin/segments/{id}/contacts/{cid}   → unpinContact
 *   GET    /admin/segments/{id}/contacts/search   → contactsSearch
 *
 * Tests:
 *  - Pin include then exclude same contact → exactly 1 row, mode flipped
 *  - Invalid contact_id → 422
 *  - Bad mode value → 422
 *  - Missing contact_id → 422
 *  - Unpin a non-pinned contact → 200 (no-op)
 *  - Search excludes already-pinned contacts
 *  - Search excludes soft-deleted contacts
 *  - Search caps at 20 results
 *  - Search matches by name and email
 *  - Permission gating: view-only → 403 on pin and unpin; 200 on search
 *  - Permission gating: superadmin → 200 on pin and unpin
 */
class SegmentPinEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $viewOnly;

    private Segment $segment;
    private Company $clientCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        // User with backend.access + view segments ONLY (no edit segments)
        $this->viewOnly = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->viewOnly->givePermissionTo('backend.access');
        $this->viewOnly->givePermissionTo('view segments');

        $this->clientCompany = Company::create([
            'name'                 => 'Test Corp',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        $this->segment = Segment::create([
            'name'  => 'Pin Test Segment',
            'scope' => 'client',
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeContact(string $email = 'contact@test.test', string $name = 'Test Contact'): Contact
    {
        return Contact::create([
            'company_id'  => $this->clientCompany->id,
            'email'       => $email,
            'name'        => $name,
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
            'email_verification_status' => 'valid',
        ]);
    }

    private function pinUrl(int $segmentId = 0): string
    {
        $id = $segmentId ?: $this->segment->id;
        return "/admin/segments/{$id}/contacts";
    }

    private function unpinUrl(int $contactId, int $segmentId = 0): string
    {
        $id = $segmentId ?: $this->segment->id;
        return "/admin/segments/{$id}/contacts/{$contactId}";
    }

    private function searchUrl(int $segmentId = 0, string $q = ''): string
    {
        $id = $segmentId ?: $this->segment->id;
        return "/admin/segments/{$id}/contacts/search" . ($q !== '' ? "?q={$q}" : '');
    }

    // ── Pin upsert ─────────────────────────────────────────────────────────────

    /**
     * POST include then POST exclude same contact → exactly ONE contact_segment row,
     * mode must be 'exclude' (the flip).
     */
    public function test_pin_include_then_exclude_flips_mode_to_single_row(): void
    {
        $contact = $this->makeContact('flip@test.test');

        // First pin: include
        $r1 = $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), [
                'contact_id' => $contact->id,
                'mode'       => 'include',
            ]);

        $r1->assertStatus(200);
        $r1->assertJsonPath('success', true);

        $this->assertDatabaseHas('contact_segment', [
            'segment_id' => $this->segment->id,
            'contact_id' => $contact->id,
            'mode'       => 'include',
        ]);

        $this->assertDatabaseCount('contact_segment', 1);

        // Second pin: flip to exclude
        $r2 = $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), [
                'contact_id' => $contact->id,
                'mode'       => 'exclude',
            ]);

        $r2->assertStatus(200);
        $r2->assertJsonPath('success', true);

        // Still exactly ONE row, mode is now 'exclude'
        $this->assertDatabaseCount('contact_segment', 1);
        $this->assertDatabaseHas('contact_segment', [
            'segment_id' => $this->segment->id,
            'contact_id' => $contact->id,
            'mode'       => 'exclude',
        ]);
    }

    /**
     * Pinning the same contact with the same mode twice is idempotent (1 row).
     */
    public function test_pin_same_mode_twice_is_idempotent(): void
    {
        $contact = $this->makeContact('idem@test.test');

        $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), ['contact_id' => $contact->id, 'mode' => 'include']);

        $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), ['contact_id' => $contact->id, 'mode' => 'include']);

        $this->assertDatabaseCount('contact_segment', 1);
    }

    /**
     * Pin response includes counts structure.
     */
    public function test_pin_response_contains_counts(): void
    {
        $contact = $this->makeContact('counts@test.test');

        $r = $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), ['contact_id' => $contact->id, 'mode' => 'include']);

        $r->assertStatus(200);
        $r->assertJsonStructure(['success', 'counts' => ['contacts_count', 'pinned_in', 'pinned_out']]);
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    public function test_pin_invalid_contact_id_returns_422(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), [
                'contact_id' => 999999,
                'mode'       => 'include',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_id']);
    }

    public function test_pin_bad_mode_returns_422(): void
    {
        $contact = $this->makeContact('badmode@test.test');

        $response = $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), [
                'contact_id' => $contact->id,
                'mode'       => 'neither',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mode']);
    }

    public function test_pin_missing_contact_id_returns_422(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), ['mode' => 'include']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_id']);
    }

    public function test_pin_missing_mode_returns_422(): void
    {
        $contact = $this->makeContact('nomode@test.test');

        $response = $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), ['contact_id' => $contact->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mode']);
    }

    // ── Unpin ──────────────────────────────────────────────────────────────────

    /**
     * DELETE on a non-pinned contact returns 200 (no-op — detach of non-existing).
     */
    public function test_unpin_non_pinned_contact_returns_200(): void
    {
        $contact = $this->makeContact('notpinned@test.test');

        $response = $this->actingAs($this->superadmin)
            ->deleteJson($this->unpinUrl($contact->id));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    /**
     * DELETE removes an existing pin row.
     */
    public function test_unpin_removes_existing_pin(): void
    {
        $contact = $this->makeContact('unpin@test.test');

        $this->segment->pinnedContacts()->syncWithoutDetaching([
            $contact->id => ['mode' => 'include'],
        ]);

        $this->assertDatabaseCount('contact_segment', 1);

        $response = $this->actingAs($this->superadmin)
            ->deleteJson($this->unpinUrl($contact->id));

        $response->assertStatus(200);
        $this->assertDatabaseCount('contact_segment', 0);
    }

    // ── Search ─────────────────────────────────────────────────────────────────

    /**
     * Search results exclude already-pinned contacts.
     */
    public function test_search_excludes_pinned_contacts(): void
    {
        $pinned    = $this->makeContact('pinned@test.test', 'Pinned Contact');
        $available = $this->makeContact('available@test.test', 'Available Contact');

        $this->segment->pinnedContacts()->syncWithoutDetaching([
            $pinned->id => ['mode' => 'include'],
        ]);

        $response = $this->actingAs($this->superadmin)
            ->getJson($this->searchUrl());

        $response->assertStatus(200);

        $ids = array_column($response->json('results'), 'id');
        $this->assertNotContains($pinned->id, $ids, 'Pinned contact must be excluded from search results');
        $this->assertContains($available->id, $ids, 'Non-pinned contact must appear in search results');
    }

    /**
     * Search results exclude soft-deleted contacts.
     */
    public function test_search_excludes_soft_deleted_contacts(): void
    {
        $deleted   = $this->makeContact('deleted@test.test', 'Deleted Contact');
        $available = $this->makeContact('active@test.test', 'Active Contact');

        $deleted->delete();

        $response = $this->actingAs($this->superadmin)
            ->getJson($this->searchUrl());

        $response->assertStatus(200);

        $ids = array_column($response->json('results'), 'id');
        $this->assertNotContains($deleted->id, $ids, 'Soft-deleted contact must be excluded from search');
        $this->assertContains($available->id, $ids, 'Active contact must appear in search');
    }

    /**
     * Search caps results at 20 contacts.
     */
    public function test_search_caps_at_20_results(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeContact("bulk{$i}@test.test", "Bulk {$i}");
        }

        $response = $this->actingAs($this->superadmin)
            ->getJson($this->searchUrl());

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(20, count($response->json('results')), 'Search must cap at 20 results');
    }

    /**
     * Search matches by name.
     */
    public function test_search_matches_by_name(): void
    {
        $match  = $this->makeContact('n1@test.test', 'UniqueNameXYZ');
        $nomatch = $this->makeContact('n2@test.test', 'OtherPerson');

        $response = $this->actingAs($this->superadmin)
            ->getJson($this->searchUrl(0, 'UniqueNameXYZ'));

        $response->assertStatus(200);

        $ids = array_column($response->json('results'), 'id');
        $this->assertContains($match->id, $ids, 'Search must match by name');
        $this->assertNotContains($nomatch->id, $ids, 'Non-matching contact must not appear');
    }

    /**
     * Search matches by email.
     */
    public function test_search_matches_by_email(): void
    {
        $match   = $this->makeContact('uniquetoken@test.test', 'Someone');
        $nomatch = $this->makeContact('other@test.test', 'Other');

        $response = $this->actingAs($this->superadmin)
            ->getJson($this->searchUrl(0, 'uniquetoken'));

        $response->assertStatus(200);

        $ids = array_column($response->json('results'), 'id');
        $this->assertContains($match->id, $ids, 'Search must match by email');
        $this->assertNotContains($nomatch->id, $ids);
    }

    /**
     * Search results have expected JSON structure.
     */
    public function test_search_result_structure(): void
    {
        $this->makeContact('struct@test.test', 'Structured Contact');

        $response = $this->actingAs($this->superadmin)
            ->getJson($this->searchUrl());

        $response->assertStatus(200);
        $response->assertJsonStructure(['results']);

        $results = $response->json('results');
        if (! empty($results)) {
            foreach ($results as $item) {
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('name', $item);
                $this->assertArrayHasKey('email', $item);
                $this->assertArrayHasKey('company', $item);
            }
        }
    }

    // ── Permission gating ──────────────────────────────────────────────────────

    /**
     * View-only user (has 'view segments', no 'edit segments') → 403 on pin.
     */
    public function test_view_only_user_gets_403_on_pin(): void
    {
        $contact = $this->makeContact('perm@test.test');

        $response = $this->actingAs($this->viewOnly)
            ->postJson($this->pinUrl(), [
                'contact_id' => $contact->id,
                'mode'       => 'include',
            ]);

        $response->assertStatus(403);
    }

    /**
     * View-only user → 403 on unpin.
     */
    public function test_view_only_user_gets_403_on_unpin(): void
    {
        $contact = $this->makeContact('perm2@test.test');

        $response = $this->actingAs($this->viewOnly)
            ->deleteJson($this->unpinUrl($contact->id));

        $response->assertStatus(403);
    }

    /**
     * View-only user ('view segments') → 200 on search (search is view-gated).
     */
    public function test_view_only_user_can_search(): void
    {
        $response = $this->actingAs($this->viewOnly)
            ->getJson($this->searchUrl());

        $response->assertStatus(200);
        $response->assertJsonStructure(['results']);
    }

    /**
     * Superadmin → 200 on pin.
     */
    public function test_superadmin_can_pin(): void
    {
        $contact = $this->makeContact('admin@test.test');

        $response = $this->actingAs($this->superadmin)
            ->postJson($this->pinUrl(), [
                'contact_id' => $contact->id,
                'mode'       => 'include',
            ]);

        $response->assertStatus(200);
    }

    /**
     * Superadmin → 200 on unpin.
     */
    public function test_superadmin_can_unpin(): void
    {
        $contact = $this->makeContact('adminunpin@test.test');

        $response = $this->actingAs($this->superadmin)
            ->deleteJson($this->unpinUrl($contact->id));

        $response->assertStatus(200);
    }

    /**
     * Guest user → redirected (401/302) on pin.
     */
    public function test_guest_is_redirected_on_pin(): void
    {
        $contact = $this->makeContact('guest@test.test');

        $response = $this->postJson($this->pinUrl(), [
            'contact_id' => $contact->id,
            'mode'       => 'include',
        ]);

        $this->assertContains($response->status(), [302, 401]);
    }

    /**
     * User with 'edit segments' (not superadmin) → 200 on pin.
     */
    public function test_edit_segments_user_can_pin(): void
    {
        $editUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $editUser->givePermissionTo('backend.access');
        $editUser->givePermissionTo('view segments');
        $editUser->givePermissionTo('edit segments');

        $contact = $this->makeContact('editperm@test.test');

        $response = $this->actingAs($editUser)
            ->postJson($this->pinUrl(), [
                'contact_id' => $contact->id,
                'mode'       => 'include',
            ]);

        $response->assertStatus(200);
    }
}
