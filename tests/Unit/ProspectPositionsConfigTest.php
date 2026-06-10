<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Asserts that the prospect_positions_recommended_groups config is coherent:
 * every group name must be a key present in prospect_positions, and the
 * flattened recommended list must be non-empty with >= 13 entries.
 */
class ProspectPositionsConfigTest extends TestCase
{
    public function test_every_recommended_group_is_a_key_in_prospect_positions(): void
    {
        $positions  = config('global.data.prospect_positions', []);
        $groups     = config('global.data.prospect_positions_recommended_groups', []);

        $this->assertNotEmpty($groups, 'prospect_positions_recommended_groups must not be empty.');

        foreach ($groups as $group) {
            $this->assertArrayHasKey(
                $group,
                $positions,
                "Recommended group \"{$group}\" is not a key in prospect_positions."
            );
        }
    }

    public function test_flattened_recommended_positions_has_at_least_13_entries(): void
    {
        $positions = config('global.data.prospect_positions', []);
        $groups    = config('global.data.prospect_positions_recommended_groups', []);

        $flattened = collect($positions)
            ->only($groups)
            ->flatten()
            ->values()
            ->all();

        $this->assertNotEmpty($flattened, 'Flattened recommended positions must not be empty.');
        $this->assertGreaterThanOrEqual(
            13,
            count($flattened),
            'Expected at least 13 recommended positions, got ' . count($flattened) . '.'
        );
    }
}
