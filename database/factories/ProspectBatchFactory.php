<?php

namespace Database\Factories;

use App\Models\ProspectBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProspectBatch> */
class ProspectBatchFactory extends Factory
{
    protected $model = ProspectBatch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'source_type' => 'company_list',
            'name' => 'Import '.fake()->unique()->numerify('#####'),
            'created_by' => User::factory(),
            'prospect_criteria_id' => null,
            'status' => 'draft',
            'source_fingerprint' => null,
            'quality_preset' => 'balanced',
            'quality_settings' => ['domain_fallback' => true],
            'source_options' => null,
            'source_cursor' => null,
            'estimate' => null,
            'recovery_audit' => null,
        ];
    }
}
