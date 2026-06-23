<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\DefaultProspectionSeeder;
use App\Models\Campaign;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DefaultProspectionSeederTest — verifies the persistent default data seeder.
 *
 * Checks: correct row counts, safety invariant (campaign cannot be dispatched).
 * Runs on the test DB under RefreshDatabase; does not touch dev data.
 */
class DefaultProspectionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_prospection_seeder_creates_expected_rows(): void
    {
        $this->seed(DefaultProspectionSeeder::class);

        // 3 segments
        $this->assertSame(3, Segment::count(), 'Expected 3 default segments.');

        // 1 sequence with 3 steps
        $this->assertSame(1, Sequence::count(), 'Expected 1 default sequence.');
        $this->assertSame(3, SequenceStep::count(), 'Expected 3 sequence steps.');

        // 1 campaign
        $this->assertSame(1, Campaign::count(), 'Expected 1 default campaign.');

        // Safety invariant: the campaign must never be dispatchable
        $campaign = Campaign::first();
        $this->assertFalse((bool) $campaign->is_active, 'Campaign is_active must be false (not dispatchable).');
        $this->assertNotNull($campaign->sender_identity_id, 'Campaign must have a sender_identity_id (FK NOT NULL).');
        $this->assertNull($campaign->next_run_at, 'Campaign next_run_at must be null (not dispatchable).');
    }

    public function test_default_prospection_seeder_is_idempotent(): void
    {
        // Running twice must not duplicate rows (all firstOrCreate).
        $this->seed(DefaultProspectionSeeder::class);
        $this->seed(DefaultProspectionSeeder::class);

        $this->assertSame(3, Segment::count(), 'Segments must not be duplicated on reseed.');
        $this->assertSame(1, Sequence::count(), 'Sequence must not be duplicated on reseed.');
        $this->assertSame(3, SequenceStep::count(), 'Steps must not be duplicated on reseed.');
        $this->assertSame(1, Campaign::count(), 'Campaign must not be duplicated on reseed.');
    }
}
