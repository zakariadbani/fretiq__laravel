<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Suppression;
use App\Services\Campaign\SegmentService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Service-level tests for SegmentService::resolve().
 *
 * Cold gate is OFF (config('prospecting.cold_send_enabled') = false).
 */
class SegmentResolveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Guarantee the cold gate is off for every test in this class.
        config(['prospecting.cold_send_enabled' => false]);
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    private function makeClientContact(string $email = 'jean@acme.test'): array
    {
        $co = Company::create([
            'name'                 => 'Acme',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        $ct = Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Jean Dupont',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        return [$co, $ct];
    }

    private function makeProspectContact(string $email = 'pierre@prospect.test'): array
    {
        $co = Company::create([
            'name'                 => 'Prospect SARL',
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        $ct = Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Pierre Martin',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'legitimate_interest',
            'email_kind'  => 'role',
        ]);

        return [$co, $ct];
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * A client-scoped segment should include a client contact and exclude a
     * prospect contact when the cold gate is off.
     */
    public function test_client_segment_includes_client_excludes_prospect(): void
    {
        [, $clientContact]   = $this->makeClientContact('jean@acme.test');
        [, $prospectContact] = $this->makeProspectContact('pierre@prospect.test');

        $segment = Segment::create(['name' => 'Clients', 'scope' => 'client']);

        $service = app(SegmentService::class);
        $result  = $service->resolve($segment);

        $emails = $result->pluck('email')->all();

        $this->assertContains('jean@acme.test', $emails, 'Client contact should be included');
        $this->assertNotContains('pierre@prospect.test', $emails, 'Prospect contact should be excluded by scope');
    }

    /**
     * A suppressed email must be excluded from a client segment resolve.
     */
    public function test_suppressed_client_contact_is_excluded(): void
    {
        [, $clientContact] = $this->makeClientContact('jean@acme.test');

        // Add suppression after contact creation
        Suppression::create([
            'email'      => 'jean@acme.test',
            'contact_id' => $clientContact->id,
            'reason'     => 'unsubscribe',
            'source'     => 'campaign',
        ]);

        $segment = Segment::create(['name' => 'Clients', 'scope' => 'client']);

        $service = app(SegmentService::class);
        $result  = $service->resolve($segment);

        $emails = $result->pluck('email')->all();
        $this->assertNotContains('jean@acme.test', $emails, 'Suppressed contact must be excluded');
    }

    /**
     * A prospect-scoped segment resolves to 0 contacts when the cold gate is off.
     */
    public function test_prospect_segment_resolves_to_zero_when_cold_gate_off(): void
    {
        $this->makeProspectContact('prospect@cold.test');

        $segment = Segment::create(['name' => 'Prospects', 'scope' => 'prospect']);

        $service = app(SegmentService::class);
        $count   = $service->resolve($segment)->count();

        $this->assertSame(0, $count, 'Cold gate is off — prospect segment must resolve to 0');
    }

    /**
     * Mixed-scope segment should include both client and client-owned contacts
     * (prospects are excluded by the cold gate, but clients are included).
     * Verifies that scope='mixed' applies no relationship filter (clients pass through).
     */
    public function test_mixed_segment_includes_client_contacts(): void
    {
        [, $clientContact] = $this->makeClientContact('jean@acme.test');

        $segment = Segment::create(['name' => 'Mixed', 'scope' => 'mixed']);

        $result = app(SegmentService::class)->resolve($segment);

        $emails = $result->pluck('email')->all();
        $this->assertContains('jean@acme.test', $emails, 'Client contact should be included in mixed segment');
    }

    /**
     * Soft-deleted contacts must NOT appear in the resolved audience.
     *
     * Contact uses SoftDeletes — deleted_at IS NULL is the default scope.
     * SegmentService::resolve() uses Contact::query() which applies the soft-delete
     * scope automatically, so deleted contacts should never appear.
     */
    public function test_resolve_excludes_soft_deleted_contacts(): void
    {
        [, $contact] = $this->makeClientContact('deleted@acme.test');

        // Soft-delete the contact
        $contact->delete();

        $segment = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $result  = app(SegmentService::class)->resolve($segment);

        $emails = $result->pluck('email')->all();
        $this->assertNotContains('deleted@acme.test', $emails, 'Soft-deleted contact must be excluded');
    }
}
