<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Services\Zoho\LocalCrmClient;
use App\Services\Zoho\ZohoCrmSyncService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ZohoSyncTest — verifies the ZohoCrmSyncService against the local fixtures.
 *
 * Fixture counts (database/fixtures/zoho/):
 *   accounts.json  → 4 accounts (all have Account_Name)
 *   contacts.json  → 5 contacts, 3 with email, 2 without
 *
 * Known fixture values used in assertions:
 *   Account:  "Transports Dupont SAS"  (id 5309063000000100001)
 *   Contact email: "jp.martin@transports-dupont.fr"  (linked to above account)
 */
class ZohoSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed ACL so Spatie doesn't error on missing tables (no auth needed for service tests).
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

    private function service(): ZohoCrmSyncService
    {
        return new ZohoCrmSyncService(new LocalCrmClient());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * syncAccounts() imports all 4 fixture accounts as companies with
     * source='zoho' and relationship='client'.
     */
    public function test_sync_imports_accounts_as_client_companies(): void
    {
        $this->service()->syncAccounts();

        $this->assertSame(
            4,
            Company::where('source', 'zoho')->where('relationship', 'client')->count(),
            'Expected 4 zoho/client companies after syncAccounts()'
        );

        $this->assertDatabaseHas('companies', [
            'name'         => 'Transports Dupont SAS',
            'source'       => 'zoho',
            'relationship' => 'client',
        ]);
    }

    /**
     * syncContacts() (after syncAccounts) imports only the 3 contacts that
     * carry an email address. The 2 email-less contacts are silently skipped.
     * Each imported contact has legal_basis='relationship' and email_kind='role'.
     */
    public function test_sync_imports_emailable_contacts_with_relationship_basis(): void
    {
        $svc = $this->service();
        $svc->syncAccounts();
        $svc->syncContacts();

        // Only 3 of 5 contacts have an email in the fixture.
        $this->assertSame(
            3,
            Contact::where('source', 'zoho')->count(),
            'Expected exactly 3 zoho contacts (email-less contacts must be skipped)'
        );

        // Every imported contact must carry the required legal fields.
        $this->assertSame(
            0,
            Contact::where('source', 'zoho')
                ->where(function ($q) {
                    $q->where('legal_basis', '!=', 'relationship')
                      ->orWhere('email_kind', '!=', 'role');
                })
                ->count(),
            'All zoho contacts must have legal_basis=relationship and email_kind=role'
        );

        // Known fixture email must be present.
        $this->assertDatabaseHas('contacts', [
            'email'       => 'jp.martin@transports-dupont.fr',
            'source'      => 'zoho',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        // The 2 email-less contacts (Karim Nassiri, Sophie Renard) must NOT exist.
        $this->assertDatabaseMissing('contacts', ['email' => '']);
        $this->assertSame(3, Contact::where('source', 'zoho')->count());
    }

    /**
     * After a full sync every contact is linked to a company with relationship='client'.
     */
    public function test_sync_links_contacts_to_companies(): void
    {
        $svc = $this->service();
        $svc->syncAccounts();
        $svc->syncContacts();

        $contact = Contact::where('email', 'jp.martin@transports-dupont.fr')->firstOrFail();

        $this->assertNotNull($contact->company_id, 'Contact must have a company_id');

        $company = Company::findOrFail($contact->company_id);
        $this->assertSame('client', $company->relationship);
        $this->assertSame('Transports Dupont SAS', $company->name);
    }

    /**
     * Running a full sync twice produces the same counts — no duplicates.
     * Idempotency is enforced via zoho_account_id (companies) and email (contacts).
     */
    public function test_sync_is_idempotent(): void
    {
        $svc = $this->service();

        // First run.
        $svc->syncAccounts();
        $svc->syncContacts();

        $companiesAfterFirst = Company::where('source', 'zoho')->count();
        $contactsAfterFirst  = Contact::where('source', 'zoho')->count();

        // Second run — should update, not duplicate.
        $svc->syncAccounts();
        $svc->syncContacts();

        $this->assertSame(
            $companiesAfterFirst,
            Company::where('source', 'zoho')->count(),
            'Company count must not grow after a second sync run'
        );

        $this->assertSame(
            $contactsAfterFirst,
            Contact::where('source', 'zoho')->count(),
            'Contact count must not grow after a second sync run'
        );
    }
}
