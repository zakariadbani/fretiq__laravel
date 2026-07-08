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
}
