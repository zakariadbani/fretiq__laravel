<?php

namespace Tests\Unit;

use App\Models\ProspectCriteria;
use Tests\TestCase;

class ProspectCriteriaEngineNeutralTest extends TestCase
{
    public function test_ai_queries_mutator_removes_engines_and_merges_legacy_duplicates(): void
    {
        $criteria = new ProspectCriteria();
        $criteria->ai_queries = [
            ['q' => ' transitaire Maroc ', 'enabled' => false, 'engine' => 'google'],
            ['q' => 'transitaire Maroc', 'enabled' => true, 'engine' => 'google_maps'],
            ['q' => 'fabricant textile', 'enabled' => false, 'engine' => 'bing'],
            ['q' => 'legacy sans drapeau', 'engine' => 'google'],
            ['q' => '  ', 'enabled' => true],
        ];

        $this->assertSame([
            ['q' => 'transitaire Maroc', 'enabled' => true],
            ['q' => 'fabricant textile', 'enabled' => false],
            ['q' => 'legacy sans drapeau', 'enabled' => true],
        ], $criteria->ai_queries);
        $this->assertArrayNotHasKey('engine', $criteria->ai_queries[0]);
    }

    public function test_query_preview_uses_aggregate_attempt_copy_without_engine_controls_or_row_claims(): void
    {
        $blade = file_get_contents(resource_path('views/backend/contents/prospect_criteria/crud/form.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Backend/ProspectCriteriaController.php'));

        $this->assertStringContainsString('appels possibles sur', $blade);
        $this->assertStringNotContainsString('Dans le budget · rang', $blade);
        $this->assertStringNotContainsString('q-engine', $blade);
        $this->assertStringNotContainsString('data-engine', $blade);
        $this->assertStringContainsString("'engine_count'", $controller);
        $this->assertStringContainsString("'prepared_attempts'", $controller);
    }
}
