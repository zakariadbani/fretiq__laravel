<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyEnrichmentUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_formats_enrichment_email_data_without_raw_json_blob(): void
    {
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('superadmin');

        $company = Company::create([
            'name' => 'Market Select',
            'domain' => 'marketselect.dk',
            'relationship' => 'prospect',
            'source' => 'discovered',
            'qualification_status' => 'pending',
            'is_active' => true,
            'enrichment_data' => [
                'domain' => 'marketselect.dk',
                'emails' => [
                    [
                        'type' => 'personal',
                        'value' => 'helle@marketselect.dk',
                        'first_name' => 'Helle',
                        'last_name' => 'Lykke',
                        'confidence' => 94,
                        'sources' => [
                            [
                                'uri' => 'https://companies.creditreports.dk/da/companies/685453',
                                'domain' => 'companies.creditreports.dk',
                            ],
                            [
                                'uri' => 'https://marketselect.dk',
                                'domain' => 'marketselect.dk',
                            ],
                            [
                                'uri' => 'https://enqua.hu/english/news',
                                'domain' => 'enqua.hu',
                            ],
                            [
                                'uri' => 'https://example.com/extra-source',
                                'domain' => 'example.com',
                            ],
                        ],
                    ],
                ],
                'webmail' => false,
                'accept_all' => true,
                'linked_domains' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get("/admin/companies/{$company->id}/edit#company_enrichment");

        $response->assertOk();
        $response->assertSee('Données d\'enrichissement', false);
        $response->assertSee('mailto:helle@marketselect.dk', false);
        $response->assertSee('Confiance 94%', false);
        $response->assertSee('Contact : Helle Lykke', false);
        $response->assertSee('4 sources', false);
        $response->assertSee('+1 autres', false);
        $response->assertSee('Aucun élément', false);
        $response->assertSee('Oui', false);
        $response->assertSee('Non', false);
        $response->assertDontSee('<code class="fs-7">', false);
        $response->assertDontSee('[{&quot;type&quot;:&quot;personal&quot;', false);
        $response->assertDontSee('&quot;sources&quot;:[', false);
    }

    /**
     * The view page must answer "why does this company have no contacts?" without
     * a round-trip to the edit page: the enrichment_status badge plus Hunter's
     * per-email confidence value.
     */
    public function test_view_page_shows_enrichment_status_and_per_email_confidence(): void
    {
        $user = $this->makeAdmin();

        $company = Company::create([
            'name' => 'Confiance SA',
            'domain' => 'confiance.test',
            'relationship' => 'prospect',
            'source' => 'discovered',
            'qualification_status' => 'pending',
            'is_active' => true,
            'enrichment_status' => Company::ENRICHMENT_ENRICHED,
            'enrichment_data' => [
                'emails' => [
                    ['value' => 'a@confiance.test', 'first_name' => 'Ana', 'last_name' => 'Roux', 'confidence' => 88],
                    ['value' => 'b@confiance.test', 'confidence' => 41],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get("/admin/companies/{$company->id}");

        $response->assertOk();
        $response->assertSee('Enrichi', false);
        $response->assertSee('Confiance 88%', false);
        $response->assertSee('Confiance 41%', false);
        $response->assertSee('mailto:a@confiance.test', false);
    }

    /**
     * NULL enrichment_status must render as an explicit « Recherche de contacts non effectuée » — telling
     * "never attempted" apart from "attempted, found nothing" is the whole point
     * of the column, so a muted dash would defeat it.
     */
    public function test_view_page_renders_null_enrichment_status_as_never_attempted(): void
    {
        $user = $this->makeAdmin();

        $company = Company::create([
            'name' => 'Jamais Tentée',
            'domain' => 'jamais.test',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get("/admin/companies/{$company->id}");

        $response->assertOk();
        $response->assertSee('Recherche de contacts non effectuée', false);
    }

    public function test_view_page_labels_a_provider_failure_distinctly(): void
    {
        $user = $this->makeAdmin();

        $company = Company::create([
            'name' => 'Échec SA',
            'domain' => 'echec.test',
            'relationship' => 'prospect',
            'source' => 'discovered',
            'qualification_status' => 'pending',
            'is_active' => true,
            'enrichment_status' => Company::ENRICHMENT_HUNTER_FAILED,
        ]);

        $response = $this->actingAs($user)->get("/admin/companies/{$company->id}");

        $response->assertOk();
        $response->assertSee('Échec de la recherche de contacts', false);
        $response->assertSee('badge-light-danger', false);
        $response->assertDontSee('Aucun email trouvé — ', false);
    }

    private function makeAdmin(): User
    {
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('superadmin');

        return $user;
    }
}
