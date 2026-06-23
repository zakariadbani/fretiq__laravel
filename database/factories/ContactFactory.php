<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id'   => Company::factory(),
            'email'        => fake()->unique()->safeEmail(),
            'name'         => fake()->name(),
            'position'     => fake()->randomElement([
                'Directeur logistique',
                'Responsable achats',
                'PDG',
                'Responsable transport',
            ]),
            'phone'        => fake()->phoneNumber(),
            'source'       => 'manual',
            'status'       => 'new',
            'legal_basis'  => 'legitimate_interest',
            'email_kind'   => 'role',
        ];
    }
}
