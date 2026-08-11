<?php

namespace Database\Factories;

use App\Models\ProspectBatchItem;
use App\Models\ProspectContactCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProspectContactCandidate> */
class ProspectContactCandidateFactory extends Factory
{
    protected $model = ProspectContactCandidate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'prospect_batch_item_id' => ProspectBatchItem::factory(),
            'prospect_batch_id' => function (array $attributes): int {
                return (int) ProspectBatchItem::query()
                    ->findOrFail($attributes['prospect_batch_item_id'])
                    ->prospect_batch_id;
            },
            'company_id' => null,
            'email' => $email,
            'normalized_email' => Str::lower($email),
            'name' => fake()->name(),
            'position' => fake()->jobTitle(),
            'phone' => null,
            'source' => 'hunter_domain_search',
            'email_kind' => 'role',
            'verification_status' => null,
            'verification_checked_at' => null,
            'verification_source' => null,
            'decision' => 'pending',
            'decision_reason' => null,
            'decided_by' => null,
            'decided_at' => null,
            'contact_id' => null,
            'metadata' => null,
        ];
    }
}
