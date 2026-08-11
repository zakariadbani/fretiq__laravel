<?php

namespace Database\Factories;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProspectBatchItem> */
class ProspectBatchItemFactory extends Factory
{
    protected $model = ProspectBatchItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $companyName = fake()->company();

        return [
            'prospect_batch_id' => ProspectBatch::factory(),
            'row_number' => fake()->unique()->numberBetween(1, 1_000_000),
            'original_input' => $companyName,
            'company_name' => $companyName,
            'normalized_name' => Str::lower(Str::ascii($companyName)),
            'country' => 'FR',
            'city' => fake()->city(),
            'provided_domain' => null,
            'selected_domain' => null,
            'registrable_domain' => null,
            'domain_alternatives' => null,
            'domain_confidence' => null,
            'domain_reason' => null,
            'status' => 'pending',
            'company_id' => null,
            'source_metadata' => null,
        ];
    }
}
