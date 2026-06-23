<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'                 => fake()->company(),
            'domain'               => fake()->unique()->domainName(),
            'sector'               => fake()->randomElement([
                'Transport & Logistique',
                'Industrie',
                'Distribution',
                'Agroalimentaire',
                'BTP',
            ]),
            'country'              => 'FR',
            'estimated_size'       => fake()->randomElement(['1-10', '11-50', '51-200', '201-500']),
            'phone'                => fake()->phoneNumber(),
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'is_active'            => true,
        ];
    }

    /**
     * State: an existing client (qualified relationship).
     */
    public function client(): static
    {
        return $this->state(fn (array $attributes) => [
            'relationship'         => 'client',
            'qualification_status' => 'qualified',
        ]);
    }
}
