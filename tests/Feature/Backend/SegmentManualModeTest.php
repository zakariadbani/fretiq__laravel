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

class SegmentManualModeTest extends TestCase
{
    use RefreshDatabase;

    private SegmentService $service;
    private Company $clientCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->service = app(SegmentService::class);
        $this->clientCompany = Company::create([
            'name' => 'Client manuel',
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
            'sector' => 'Transport',
        ]);
    }

    private function contact(string $email, array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'company_id' => $this->clientCompany->id,
            'email' => $email,
            'name' => 'Contact manuel',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ], $attributes));
    }

    private function manualSegment(): Segment
    {
        return Segment::create([
            'name' => 'Sélection manuelle',
            'scope' => 'client',
            'is_manual' => true,
            'filter' => ['sector' => ['Transport']],
        ]);
    }

    public function test_manual_segment_resolves_only_selected_included_contacts(): void
    {
        $selected = $this->contact('selected@fretiq.test');
        $unselected = $this->contact('unselected@fretiq.test');
        $segment = $this->manualSegment();
        $segment->pinnedContacts()->syncWithoutDetaching([
            $selected->id => ['mode' => 'include'],
        ]);

        $resolvedIds = $this->service->resolve($segment)->pluck('id')->all();

        $this->assertSame([$selected->id], $resolvedIds);
        $this->assertNotContains($unselected->id, $resolvedIds);

        $empty = $this->manualSegment();
        $this->assertCount(0, $this->service->resolve($empty));
    }

    public function test_manual_selected_contacts_still_obey_suppression_email_hygiene_and_manual_excludes(): void
    {
        $valid = $this->contact('valid@fretiq.test');
        $suppressed = $this->contact('suppressed@fretiq.test');
        $blank = $this->contact('blank@fretiq.test', ['email' => '   ']);
        $excluded = $this->contact('excluded@fretiq.test');
        $segment = $this->manualSegment();

        Suppression::create([
            'email' => 'suppressed@fretiq.test',
            'reason' => 'unsubscribe',
            'source' => 'campaign',
        ]);

        $segment->pinnedContacts()->syncWithoutDetaching([
            $valid->id => ['mode' => 'include'],
            $suppressed->id => ['mode' => 'include'],
            $blank->id => ['mode' => 'include'],
            $excluded->id => ['mode' => 'exclude'],
        ]);

        $resolvedIds = $this->service->resolve($segment)->pluck('id')->all();

        $this->assertSame([$valid->id], $resolvedIds);
    }

    public function test_dynamic_segment_keeps_union_include_behavior(): void
    {
        $filterMatch = $this->contact('match@fretiq.test');
        $otherCompany = Company::create([
            'name' => 'Client hors filtre',
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
            'sector' => 'Informatique',
        ]);
        $includedFilterMiss = Contact::create([
            'company_id' => $otherCompany->id,
            'email' => 'included@fretiq.test',
            'name' => 'Contact inclus',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
        $segment = Segment::create([
            'name' => 'Dynamique',
            'scope' => 'client',
            'is_manual' => false,
            'filter' => ['sector' => ['Transport']],
        ]);
        $segment->pinnedContacts()->syncWithoutDetaching([
            $includedFilterMiss->id => ['mode' => 'include'],
        ]);

        $resolvedIds = $this->service->resolve($segment)->pluck('id')->sort()->values()->all();

        $this->assertSame([$filterMatch->id, $includedFilterMiss->id], $resolvedIds);
    }
}
