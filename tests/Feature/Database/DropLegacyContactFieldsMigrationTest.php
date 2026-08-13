<?php

namespace Tests\Feature\Database;

use App\Models\Segment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DropLegacyContactFieldsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_precheck_refuses_to_drop_columns_while_a_segment_uses_filter_status(): void
    {
        $migration = require database_path('migrations/2026_08_13_100100_drop_legacy_contact_state_and_legal_fields.php');
        $migration->down();
        $segment = Segment::create([
            'name' => 'Legacy state',
            'scope' => 'client',
            'filter' => ['status' => 'new'],
        ]);

        try {
            $migration->up();
            $this->fail('The migration should refuse legacy filter.status.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString((string) $segment->id, $exception->getMessage());
            $this->assertTrue(Schema::hasColumn('contacts', 'status'));
        } finally {
            $segment->update(['filter' => ['lifecycle_state' => 'needs_verification']]);
            $migration->up();
        }

        $this->assertFalse(Schema::hasColumn('contacts', 'status'));
    }
}
